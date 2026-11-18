<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests;

use PDO;
use Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared helpers: frozen clock, isolated asset store, submission factories.
 */
abstract class CleanupTestCase extends SapphireTest
{
    protected $usesDatabase = true;

    protected const NOW = '2026-11-17 12:00:00';

    private mixed $previousRetentionEnv = null;

    /**
     * Cutoff for the default 90 days at NOW.
     */
    protected const CUTOFF = '2026-08-19 12:00:00';

    private ?PDO $lockConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now(self::NOW);
        TestAssetStore::activate('UserFormsSubmissionCleanupTest');

        // A retention window set in the host project's .env must not leak into the tests.
        $this->previousRetentionEnv = Environment::getEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV);
        Environment::setEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV, '');
    }

    protected function tearDown(): void
    {
        $this->releaseForeignLock();
        Environment::setEnv(
            DeleteExpiredSubmissionsTask::RETENTION_ENV,
            (string) ($this->previousRetentionEnv ?: '')
        );
        TestAssetStore::reset();
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * @param array<string,string> $values field name => value
     */
    protected function createSubmission(string $created, array $values = ['Name' => 'Jane Doe']): SubmittedForm
    {
        $form = SubmittedForm::create();
        $form->write();
        DB::prepared_query('UPDATE "SubmittedForm" SET "Created" = ? WHERE "ID" = ?', [$created, $form->ID]);

        foreach ($values as $name => $value) {
            $field = SubmittedFormField::create();
            $field->ParentID = $form->ID;
            $field->Name = $name;
            $field->Value = $value;
            $field->write();
        }

        return $form;
    }

    protected function attachUpload(SubmittedForm $form, File $file, string $name = 'Upload'): SubmittedFileField
    {
        $field = SubmittedFileField::create();
        $field->ParentID = $form->ID;
        $field->Name = $name;
        $field->UploadedFileID = $file->ID;
        $field->write();

        return $field;
    }

    /**
     * A file as userforms creates it: draft only, flagged as form upload, in the upload folder.
     */
    protected function createUpload(
        string $name,
        ?string $flag = 't',
        bool $publish = false,
        string $folder = 'Uploads'
    ): File {
        return Versioned::withVersionedMode(function () use ($name, $flag, $publish, $folder) {
            Versioned::set_stage(Versioned::DRAFT);
            $parent = Folder::find_or_make($folder);
            $file = File::create();
            $file->ParentID = $parent->ID;
            $file->setFromString("content of $name", "$folder/$name");
            $file->write();
            if ($publish) {
                $file->publishSingle();
            }
            // like UserFormFileExtension: the flag lives on the draft row only
            DB::prepared_query('UPDATE "File" SET "UserFormUpload" = ? WHERE "ID" = ?', [$flag, $file->ID]);

            return $file;
        });
    }

    /**
     * Register a folder as target of a form upload field.
     */
    protected function registerUploadFolder(string $folder): void
    {
        $field = EditableFileField::create();
        $field->Name = 'Upload';
        $field->FolderID = Folder::find_or_make($folder)->ID;
        $field->write();
    }

    protected function fileRow(int $fileID): string
    {
        return sha1(json_encode([
            DB::query("SELECT * FROM \"File\" WHERE \"ID\" = $fileID")->record(),
            DB::query("SELECT * FROM \"File_Live\" WHERE \"ID\" = $fileID")->record(),
        ]));
    }

    protected function assetExists(string $filename, string $hash): bool
    {
        return Injector::inst()->get(AssetStore::class)->exists($filename, $hash);
    }

    protected function fileRowCounts(int $fileID): array
    {
        return [
            'draft' => (int) DB::query("SELECT COUNT(*) FROM \"File\" WHERE \"ID\" = $fileID")->value(),
            'live' => (int) DB::query("SELECT COUNT(*) FROM \"File_Live\" WHERE \"ID\" = $fileID")->value(),
            'versions' => (int) DB::query(
                "SELECT COUNT(*) FROM \"File_Versions\" WHERE \"RecordID\" = $fileID"
            )->value(),
        ];
    }

    protected function formExists(int $formID): bool
    {
        return (bool) DB::query("SELECT COUNT(*) FROM \"SubmittedForm\" WHERE \"ID\" = $formID")->value();
    }

    protected function fieldCount(int $formID): int
    {
        return (int) DB::query("SELECT COUNT(*) FROM \"SubmittedFormField\" WHERE \"ParentID\" = $formID")->value();
    }

    /**
     * Snapshot of all rows belonging to a submission, to prove they were not touched.
     */
    protected function submissionFingerprint(int $formID): string
    {
        $rows = [
            DB::query("SELECT * FROM \"SubmittedForm\" WHERE \"ID\" = $formID")->record(),
            iterator_to_array(DB::query(
                "SELECT * FROM \"SubmittedFormField\" WHERE \"ParentID\" = $formID ORDER BY \"ID\""
            )),
            iterator_to_array(DB::query(
                "SELECT ff.* FROM \"SubmittedFileField\" ff JOIN \"SubmittedFormField\" f ON f.\"ID\" = ff.\"ID\"
                 WHERE f.\"ParentID\" = $formID ORDER BY ff.\"ID\""
            )),
        ];

        return sha1(json_encode($rows));
    }

    /**
     * @param array<string,mixed> $options e.g. ['--days' => '30', '--dry-run' => true]
     * @return array{0:int,1:string} exit code and output
     */
    protected function runTask(BuildTask $task, array $options = []): array
    {
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
        $exitCode = $task->run($input, $output);

        return [$exitCode, $buffer->fetch()];
    }

    /**
     * Hold the cleanup lock from a second connection, like a concurrent run would.
     */
    protected function holdLockElsewhere(string $name): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s',
            Environment::getEnv('SS_DATABASE_SERVER') ?: 'localhost',
            Environment::getEnv('SS_DATABASE_PORT') ?: '3306'
        );
        $this->lockConnection = new PDO(
            $dsn,
            Environment::getEnv('SS_DATABASE_USERNAME'),
            Environment::getEnv('SS_DATABASE_PASSWORD')
        );
        $id = DB::get_conn()->getSelectedDatabase() . '_' . $name;
        $stmt = $this->lockConnection->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Test setup could not take the lock');
    }

    private function releaseForeignLock(): void
    {
        $this->lockConnection = null;
    }
}
