<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask;
use Brandcom\UserFormsSubmissionCleanup\Tasks\PurgeOrphanedSubmissionDataTask;
use Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures\FailingCleaner;
use Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures\FailingDeleteExtension;
use Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures\FileHolder;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Shortcodes\FileLink;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;

/**
 * Safeguards added after the code review of 1.3.0: invalid input, PHP errors, files that are
 * still in use or were taken over by editors, and the version history of deleted uploads.
 */
class SafeguardsTest extends CleanupTestCase
{
    protected static $extra_dataobjects = [
        FileHolder::class,
    ];

    protected static $required_extensions = [
        SubmittedForm::class => [FailingDeleteExtension::class],
    ];

    protected function tearDown(): void
    {
        FailingDeleteExtension::$failFor = [];
        parent::tearDown();
    }

    public function testRejectsInvalidMaxRuntime(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');

        foreach (['-1', 'abc', '2O', '1.5'] as $value) {
            [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--max-runtime' => $value]);
            $this->assertSame(Command::INVALID, $exit, "--max-runtime=$value");
            $this->assertStringContainsString('--max-runtime', $out);
        }

        $this->assertTrue($this->formExists($form->ID));
    }

    public function testRejectsBatchSizeBelowOne(): void
    {
        // batch_size 0 used to loop forever: LIMIT 0 returns no rows, and 0 === batch size.
        Config::modify()->set(DeleteExpiredSubmissionsTask::class, 'batch_size', 0);
        $form = $this->createSubmission('2020-01-01 00:00:00');

        [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('batch_size', $out);
        $this->assertTrue($this->formExists($form->ID));
    }

    public function testSkippedFlagIsResetOnNextRun(): void
    {
        $task = DeleteExpiredSubmissionsTask::create();
        $this->holdLockElsewhere(SubmissionCleaner::LOCK_NAME);
        $this->runTask($task);
        $this->assertTrue($task->wasSkipped());

        $this->releaseForeignLock();
        $this->runTask($task);

        $this->assertFalse($task->wasSkipped());
    }

    public function testPhpErrorDuringDeleteIsRolledBackAndRunContinues(): void
    {
        $failing = $this->createSubmission('2026-01-01 00:00:00', ['Name' => 'a', 'Mail' => 'b']);
        $after = $this->createSubmission('2026-01-02 00:00:00');
        FailingDeleteExtension::$failFor = [(int) $failing->ID];
        $depth = DB::get_conn()->transactionDepth();

        [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString("Submission #{$failing->ID} failed", $out);
        $this->assertSame($depth, DB::get_conn()->transactionDepth(), 'no transaction may stay open');
        $this->assertTrue($this->formExists($failing->ID));
        $this->assertSame(2, $this->fieldCount($failing->ID), 'field rows must be rolled back');
        $this->assertFalse($this->formExists($after->ID), 'later submissions are still processed');
    }

    public function testKeepsUploadReferencedByHasOne(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('logo.png');
        $this->attachUpload($form, $file);
        FileHolder::create(['DocumentID' => $file->ID])->write();

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('1 kept (still in use)', $out);
        $this->assertFalse($this->formExists($form->ID));
        $this->assertSame(1, $this->fileRowCounts($file->ID)['draft']);
        $this->assertTrue($this->assetExists($file->getFilename(), $file->getHash()));
    }

    public function testDeletesAssetEvenWhenProjectKeepsArchivedAssets(): void
    {
        // With keep_archived_assets the asset would move to the protected store and, after the
        // version purge, stay on disk without any record.
        Config::modify()->set(File::class, 'keep_archived_assets', true);
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('cv.pdf', 't', true);
        $this->attachUpload($form, $file);
        [$filename, $hash] = [$file->getFilename(), $file->getHash()];

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($file->ID));
        $this->assertFalse($this->assetExists($filename, $hash));
        $this->assertTrue((bool) Config::inst()->get(File::class, 'keep_archived_assets'), 'project config restored');
    }

    public function testDeletesUploadInFormerUploadFolder(): void
    {
        // The upload field wrote to "Old" and was later switched to "New".
        $field = EditableFileField::create();
        $field->Name = 'Upload';
        $field->FolderID = Folder::find_or_make('Old')->ID;
        $field->write();
        $field->FolderID = Folder::find_or_make('New')->ID;
        $field->write();

        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('legacy.pdf', null, false, 'Old');
        $this->attachUpload($form, $file);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('uploaded files: 1 deleted', $out);
        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($file->ID));
    }

    public function testDryRunPredictsWhatHappensToUploads(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $this->attachUpload($form, $this->createUpload('a.pdf'), 'A');
        $this->attachUpload($form, $this->createUpload('editorial.jpg', 'f'), 'B');
        $linked = $this->createUpload('linked.pdf');
        $this->attachUpload($form, $linked, 'C');
        $parent = Group::create(['Title' => 'Links the file']);
        $parent->write();
        FileLink::create([
            'LinkedID' => $linked->ID,
            'ParentID' => $parent->ID,
            'ParentClass' => Group::class,
        ])->write();

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--dry-run' => true]);

        $this->assertStringContainsString(
            'Uploaded files: 1 would be deleted, 1 kept (still in use), 1 kept (not a form upload), 0 already gone',
            $out
        );
        $this->assertTrue($this->formExists($form->ID));
    }

    public function testPurgeFileVersionsNeverTouchesExistingFiles(): void
    {
        $file = $this->createUpload('alive.pdf', 't', true);
        $before = $this->fileRowCounts($file->ID);

        $rows = SubmissionCleaner::singleton()->purgeFileVersions([$file->ID]);

        $this->assertSame(0, $rows);
        $this->assertSame($before, $this->fileRowCounts($file->ID));
    }

    public function testOrphanTaskKeepsFieldOfFailedUploadAndFails(): void
    {
        $orphaned = $this->createSubmission('2026-11-01 00:00:00', ['Name' => 'x']);
        $failingField = $this->attachUpload($orphaned, $this->createUpload('broken.pdf'), 'A');
        $okFile = $this->createUpload('ok.pdf');
        $this->attachUpload($orphaned, $okFile, 'B');
        DB::prepared_query('DELETE FROM "SubmittedForm" WHERE "ID" = ?', [$orphaned->ID]);

        $cleaner = new FailingCleaner();
        $cleaner->failFor = [(int) $failingField->ID];
        Injector::inst()->registerService($cleaner, SubmissionCleaner::class);

        [$exit, $out] = $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString("Upload field #{$failingField->ID}", $out);
        $this->assertSame(1, $this->fieldCount($orphaned->ID), 'only the failed upload field remains');
        $this->assertSame(0, $this->fileRowCounts($okFile->ID)['draft'], 'the other upload is still handled');
    }

    public function testOrphanTaskRespectsPurgeFileVersionsOff(): void
    {
        $file = $this->createUpload('gone.pdf');
        $this->archive($file);
        Config::modify()->set(SubmissionCleaner::class, 'purge_file_versions', false);

        $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertGreaterThan(0, $this->fileRowCounts($file->ID)['versions']);
    }

    public function testOrphanTaskPurgesHistoryByLastLocation(): void
    {
        $this->registerUploadFolder('Uploads');
        // last seen in the upload folder: personal data, history goes
        $upload = $this->createUpload('gone-upload.pdf');
        $this->archive($upload);

        // moved to an editorial folder before it was archived: keep history for the CMS archive
        $taken = $this->createUpload('taken-over.jpg');
        $this->moveTo($taken, 'Editorial');
        $this->archive($taken);

        // an editor's own file in the shared upload folder, deleted in the CMS: keep history
        $editorial = $this->createUpload('product.jpg', null);
        DB::prepared_query('UPDATE "File_Versions" SET "OwnerID" = 5 WHERE "RecordID" = ?', [$editorial->ID]);
        $this->archive($editorial);

        $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertSame(0, $this->fileRowCounts($upload->ID)['versions']);
        $this->assertGreaterThan(0, $this->fileRowCounts($taken->ID)['versions']);
        $this->assertGreaterThan(0, $this->fileRowCounts($editorial->ID)['versions']);
    }

    private function archive(File $file): void
    {
        SubmissionCleaner::singleton()->inDraft(fn () => File::get()->byID($file->ID)->doArchive());
        $this->assertSame(0, $this->fileRowCounts($file->ID)['draft']);
    }

    private function moveTo(File $file, string $folder): void
    {
        Versioned::withVersionedMode(function () use ($file, $folder) {
            Versioned::set_stage(Versioned::DRAFT);
            $draft = File::get()->byID($file->ID);
            $draft->ParentID = Folder::find_or_make($folder)->ID;
            $draft->write();
        });
    }
}
