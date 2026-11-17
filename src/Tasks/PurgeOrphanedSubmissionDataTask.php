<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tasks;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * One-off repair for submission data that earlier, incomplete deletes left behind.
 *
 * Typical cause: cleanup code that deleted SubmittedForm via raw SQL without also deleting
 * SubmittedFormField / SubmittedFileField. Result: field rows (and uploaded files) without a
 * parent submission, which the regular cleanup never sees.
 *
 *   vendor/bin/sake tasks:userforms-purge-orphans --dry-run
 *   vendor/bin/sake tasks:userforms-purge-orphans
 *
 * Steps:
 * 1. archive uploaded files of orphaned upload fields (via SubmissionCleaner)
 * 2. delete orphaned field rows from every table of the SubmittedFormField hierarchy
 * 3. purge version history of form uploads that no longer exist in any stage
 */
class PurgeOrphanedSubmissionDataTask extends BuildTask
{
    protected static string $commandName = 'userforms-purge-orphans';

    protected string $title = 'Purge orphaned form submission data';

    protected static string $description = 'Deletes submission field rows without a submission, '
        . 'their uploaded files, and version history of deleted form uploads';

    private static int $batch_size = 5000;

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        set_time_limit(0);
        $dryRun = (bool) $input->getOption('dry-run');

        $fileFieldIDs = $this->orphanedFileFieldIDs();
        $versionIDs = $this->goneUploadIDs();

        if ($dryRun) {
            $output->writeln(sprintf('Orphaned field rows: <info>%d</>', $this->countOrphanedFields()));
            $output->writeln(sprintf('Uploaded files on orphaned fields: <info>%d</>', count($fileFieldIDs)));
            $output->writeln(sprintf(
                'Deleted uploads with leftover version history: <info>%d</>',
                count($versionIDs)
            ));
            return Command::SUCCESS;
        }

        $this->removeOrphanedUploads($fileFieldIDs, $output);
        $this->deleteOrphanedFields($output);
        $this->purgeGoneUploadVersions($output);

        return Command::SUCCESS;
    }

    /**
     * @param array<int> $fieldIDs
     */
    private function removeOrphanedUploads(array $fieldIDs, PolyOutput $output): void
    {
        $cleaner = SubmissionCleaner::singleton();
        $files = [
            SubmissionCleaner::FILE_DELETED => 0,
            SubmissionCleaner::FILE_KEPT => 0,
            SubmissionCleaner::FILE_NONE => 0,
        ];

        foreach (array_chunk($fieldIDs, 200) as $chunk) {
            $fields = $cleaner->inDraft(fn () => SubmittedFileField::get()->byIDs($chunk)->toArray());
            foreach ($fields as $field) {
                $files[$cleaner->removeUpload($field)]++;
            }
            DataObject::flush_and_destroy_cache();
        }

        $output->writeln(sprintf(
            '<info>Orphaned uploads:</> %d deleted, %d kept (reused elsewhere), %d already gone',
            $files[SubmissionCleaner::FILE_DELETED],
            $files[SubmissionCleaner::FILE_KEPT],
            $files[SubmissionCleaner::FILE_NONE]
        ));
    }

    /**
     * Keyset-paginated, so every pass only scans forward from the last ID.
     */
    private function deleteOrphanedFields(PolyOutput $output): void
    {
        $tables = $this->fieldTables();
        $baseTable = DataObject::getSchema()->baseDataTable(SubmittedFormField::class);
        $formTable = DataObject::getSchema()->tableName(SubmittedForm::class);
        $batchSize = (int) static::config()->get('batch_size');
        $lastID = 0;
        $total = 0;

        while (true) {
            $ids = DB::prepared_query(
                sprintf(
                    'SELECT f."ID" FROM "%1$s" f LEFT JOIN "%2$s" s ON s."ID" = f."ParentID"
                     WHERE s."ID" IS NULL AND f."ID" > ? ORDER BY f."ID" LIMIT %3$d',
                    $baseTable,
                    $formTable,
                    $batchSize
                ),
                [$lastID]
            )->column();

            if (!$ids) {
                break;
            }

            $placeholders = DB::placeholders($ids);
            DB::get_conn()->withTransaction(function () use ($tables, $ids, $placeholders) {
                foreach ($tables as $table) {
                    DB::prepared_query(
                        sprintf('DELETE FROM "%s" WHERE "ID" IN (%s)', $table, $placeholders),
                        $ids
                    );
                }
            });

            $lastID = (int) end($ids);
            $total += count($ids);
            $output->writeln(sprintf('  ... %d field rows deleted', $total));
        }

        $output->writeln(sprintf('<info>Deleted %d orphaned field rows.</>', $total));
    }

    private function purgeGoneUploadVersions(PolyOutput $output): void
    {
        $cleaner = SubmissionCleaner::singleton();
        $ids = $this->goneUploadIDs();
        $rows = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows += $cleaner->purgeFileVersions($chunk);
        }

        $output->writeln(sprintf('<info>Purged %d version rows of %d deleted uploads.</>', $rows, count($ids)));
    }

    /**
     * Subclass tables first, base table last.
     *
     * @return array<string>
     */
    private function fieldTables(): array
    {
        $schema = DataObject::getSchema();
        $tables = [];
        foreach (ClassInfo::subclassesFor(SubmittedFormField::class) as $class) {
            if ($schema->classHasTable($class)) {
                $tables[] = $schema->tableName($class);
            }
        }

        return array_reverse(array_unique($tables));
    }

    private function countOrphanedFields(): int
    {
        return (int) DB::query(
            'SELECT COUNT(*) FROM "SubmittedFormField" f
             LEFT JOIN "SubmittedForm" s ON s."ID" = f."ParentID"
             WHERE s."ID" IS NULL'
        )->value();
    }

    /**
     * Orphaned upload fields that still point at a file.
     *
     * @return array<int>
     */
    private function orphanedFileFieldIDs(): array
    {
        return array_map('intval', DB::query(
            'SELECT ff."ID" FROM "SubmittedFileField" ff
             INNER JOIN "SubmittedFormField" f ON f."ID" = ff."ID"
             LEFT JOIN "SubmittedForm" s ON s."ID" = f."ParentID"
             WHERE s."ID" IS NULL AND ff."UploadedFileID" > 0'
        )->column());
    }

    /**
     * Form uploads that are gone from both stages but still have version rows.
     *
     * UserFormFileExtension writes its upload flag to the draft table only, so version rows
     * often carry NULL. Rows stored in a configured upload folder count as uploads as well.
     *
     * @return array<int>
     */
    private function goneUploadIDs(): array
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableName(File::class);
        $folderTable = $schema->tableName(EditableFileField::class);

        return array_map('intval', DB::query(sprintf(
            'SELECT DISTINCT v."RecordID" FROM "%1$s_Versions" v
             LEFT JOIN "%1$s" d ON d."ID" = v."RecordID"
             LEFT JOIN "%1$s_Live" l ON l."ID" = v."RecordID"
             WHERE d."ID" IS NULL AND l."ID" IS NULL
               AND (v."UserFormUpload" = \'t\'
                    OR v."ParentID" IN (SELECT "FolderID" FROM "%2$s" WHERE "FolderID" > 0))',
            $table,
            $folderTable
        ))->column());
    }
}
