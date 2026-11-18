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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Repair for submission data that earlier, incomplete deletes left behind.
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
 * 2. delete orphaned field rows from every table of the SubmittedFormField hierarchy, except
 *    upload fields whose file could not be handled in step 1
 * 3. purge version history of form uploads that no longer exist in any stage
 *    (only with SubmissionCleaner.purge_file_versions)
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $purgeVersions = (bool) SubmissionCleaner::config()->get('purge_file_versions');

        if ($input->getOption('dry-run')) {
            $this->report($purgeVersions, $output);
            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;
        $ran = SubmissionCleaner::singleton()->runExclusive(
            function () use ($purgeVersions, $output, &$exitCode) {
                $failedFieldIDs = $this->removeOrphanedUploads($output);
                $this->deleteOrphanedFields($failedFieldIDs, $output);

                if ($purgeVersions) {
                    $this->purgeGoneUploadVersions($output);
                } else {
                    $output->writeln('Version history kept (purge_file_versions is off).');
                }

                if ($failedFieldIDs) {
                    $output->writeln(sprintf(
                        '<error>%d uploads could not be handled, their field rows were kept for the next run</>',
                        count($failedFieldIDs)
                    ));
                    $exitCode = Command::FAILURE;
                }
            }
        );

        if (!$ran) {
            $output->writeln('<error>Another cleanup run is in progress, try again later.</>');
            return Command::FAILURE;
        }

        return $exitCode;
    }

    private function report(bool $purgeVersions, PolyOutput $output): void
    {
        $cleaner = SubmissionCleaner::singleton();
        $files = SubmissionCleaner::emptyCounts();
        foreach (array_chunk($this->orphanedFileFieldIDs(), 200) as $chunk) {
            foreach (SubmittedFileField::get()->byIDs($chunk) as $field) {
                $files[$cleaner->classifyUpload($field)]++;
            }
            DataObject::flush_and_destroy_cache();
        }

        $output->writeln(sprintf('Orphaned field rows: <info>%d</>', $this->countOrphanedFields()));
        $output->writeln(
            'Uploaded files on orphaned fields: ' . DeleteExpiredSubmissionsTask::describeFiles($files, true)
        );
        $output->writeln(sprintf(
            'Deleted uploads with leftover version history: <info>%d</>%s',
            count($this->goneUploadIDs()),
            $purgeVersions ? '' : ' (kept, purge_file_versions is off)'
        ));
    }

    /**
     * @return array<int> IDs of upload fields whose file could not be handled
     */
    private function removeOrphanedUploads(PolyOutput $output): array
    {
        $cleaner = SubmissionCleaner::singleton();
        $files = SubmissionCleaner::emptyCounts();
        $failed = [];

        foreach (array_chunk($this->orphanedFileFieldIDs(), 200) as $chunk) {
            foreach (SubmittedFileField::get()->byIDs($chunk) as $field) {
                try {
                    $files[$cleaner->removeUpload($field)]++;
                } catch (Throwable $e) {
                    $failed[] = (int) $field->ID;
                    $output->writeln(sprintf(
                        '<error>Upload field #%d (file #%d) failed: %s</>',
                        $field->ID,
                        $field->UploadedFileID,
                        $e->getMessage()
                    ));
                }
            }
            DataObject::flush_and_destroy_cache();
        }

        $output->writeln(
            '<info>Orphaned uploads:</> ' . DeleteExpiredSubmissionsTask::describeFiles($files, false)
        );

        return $failed;
    }

    /**
     * Keyset-paginated, so every pass only scans forward from the last ID.
     *
     * @param array<int> $keepIDs field rows that must survive (failed uploads)
     */
    private function deleteOrphanedFields(array $keepIDs, PolyOutput $output): void
    {
        $tables = $this->fieldTables();
        $baseTable = DataObject::getSchema()->baseDataTable(SubmittedFormField::class);
        $formTable = DataObject::getSchema()->tableName(SubmittedForm::class);
        $batchSize = DeleteExpiredSubmissionsTask::parseInt(
            static::config()->get('batch_size'),
            'batch_size config',
            1
        );
        $cleaner = SubmissionCleaner::singleton();
        $lastID = 0;
        $total = 0;

        while (true) {
            $found = DB::prepared_query(
                sprintf(
                    'SELECT f."ID" FROM "%1$s" f LEFT JOIN "%2$s" s ON s."ID" = f."ParentID"
                     WHERE s."ID" IS NULL AND f."ID" > ? ORDER BY f."ID" LIMIT %3$d',
                    $baseTable,
                    $formTable,
                    $batchSize
                ),
                [$lastID]
            )->column();

            if (!$found) {
                break;
            }

            $lastID = (int) end($found);
            $ids = array_values(array_diff(array_map('intval', $found), $keepIDs));
            if (!$ids) {
                continue;
            }

            $placeholders = DB::placeholders($ids);
            $cleaner->transaction(function () use ($tables, $ids, $placeholders) {
                foreach ($tables as $table) {
                    DB::prepared_query(
                        sprintf('DELETE FROM "%s" WHERE "ID" IN (%s)', $table, $placeholders),
                        $ids
                    );
                }
            });

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
        return (int) DB::query(sprintf(
            'SELECT COUNT(*) FROM "%1$s" f LEFT JOIN "%2$s" s ON s."ID" = f."ParentID" WHERE s."ID" IS NULL',
            DataObject::getSchema()->baseDataTable(SubmittedFormField::class),
            DataObject::getSchema()->tableName(SubmittedForm::class)
        ))->value();
    }

    /**
     * Orphaned upload fields that still point at a file.
     *
     * @return array<int>
     */
    private function orphanedFileFieldIDs(): array
    {
        $schema = DataObject::getSchema();

        return array_map('intval', DB::query(sprintf(
            'SELECT ff."ID" FROM "%1$s" ff
             INNER JOIN "%2$s" f ON f."ID" = ff."ID"
             LEFT JOIN "%3$s" s ON s."ID" = f."ParentID"
             WHERE s."ID" IS NULL AND ff."UploadedFileID" > 0',
            $schema->tableName(SubmittedFileField::class),
            $schema->baseDataTable(SubmittedFormField::class),
            $schema->tableName(SubmittedForm::class)
        ))->column());
    }

    /**
     * Form uploads that are gone from both stages but still have version rows.
     *
     * Decided by the last state before deletion (highest non-deleted version), with the same
     * rule as SubmissionCleaner: the file lay in a folder that a form upload field writes or
     * wrote to, and its flag was not 'f'. A file an editor had moved elsewhere keeps its
     * history, so it can still be restored from the CMS archive.
     *
     * @return array<int>
     */
    private function goneUploadIDs(): array
    {
        $folderIDs = SubmissionCleaner::singleton()->uploadFolderIDs();
        if (!$folderIDs) {
            return [];
        }

        $table = DataObject::getSchema()->tableName(File::class);

        return array_map('intval', DB::prepared_query(
            sprintf(
                'SELECT v."RecordID" FROM "%1$s_Versions" v
                 INNER JOIN (
                     SELECT "RecordID", MAX("Version") AS "LastVersion" FROM "%1$s_Versions"
                     WHERE "WasDeleted" = 0 GROUP BY "RecordID"
                 ) last ON last."RecordID" = v."RecordID" AND last."LastVersion" = v."Version"
                 LEFT JOIN "%1$s" d ON d."ID" = v."RecordID"
                 LEFT JOIN "%1$s_Live" l ON l."ID" = v."RecordID"
                 WHERE d."ID" IS NULL AND l."ID" IS NULL
                   AND (v."UserFormUpload" IS NULL OR v."UserFormUpload" <> \'f\')
                   AND v."ParentID" IN (%2$s)',
                $table,
                DB::placeholders($folderIDs)
            ),
            $folderIDs
        )->column());
    }
}
