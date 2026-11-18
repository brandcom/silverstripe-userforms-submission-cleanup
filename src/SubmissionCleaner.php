<?php

namespace Brandcom\UserFormsSubmissionCleanup;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Shortcodes\FileLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\RelatedData\RelatedDataService;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\Versioned\ChangeSetItem;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Deletes userforms submissions together with their field rows and uploaded files.
 *
 * Project independent: depends only on silverstripe/userforms, assets and versioned.
 *
 * Why not just $form->delete():
 * - Uploads are written to the draft stage (and sometimes published via $owns). A task runs
 *   in Stage.Live mode, so the built-in cascade on SubmittedFileField.UploadedFile resolves
 *   against File_Live and at best removes the live row. The draft row survives and
 *   AssetControlExtension keeps the asset on disk because another stage still references it.
 *   Files are therefore archived explicitly in draft mode before the submission goes.
 * - doArchive() keeps File_Versions (it writes a deletion marker), so filenames survive the
 *   file. With purge_file_versions those rows are removed as well.
 * - Raw SQL on SubmittedFormField orphans the SubmittedFileField subclass rows. Only the ORM
 *   knows about all tables in the hierarchy, so submissions are deleted through it.
 */
class SubmissionCleaner
{
    use Configurable;
    use Injectable;

    /**
     * The field points at no file, or at a file that exists in no stage.
     */
    public const FILE_NONE = 'none';

    public const FILE_DELETED = 'deleted';

    /**
     * A form upload that something else still uses (content, another record, another submission).
     */
    public const FILE_KEPT = 'kept';

    /**
     * Not a form upload, or a form upload an editor moved out of the upload folders.
     */
    public const FILE_FOREIGN = 'foreign';

    /**
     * Shared by all cleanup tasks, so cron, HTTP trigger and the orphan task never overlap.
     */
    public const LOCK_NAME = 'userforms-submission-cleanup';

    /**
     * Also remove the *_Versions rows of deleted uploads, so filenames do not outlive files.
     */
    private static bool $purge_file_versions = true;

    /**
     * @var array<int>|null
     */
    private ?array $uploadFolderIDs = null;

    /**
     * Outcome counts with every FILE_* constant set to zero.
     *
     * @return array<string,int>
     */
    public static function emptyCounts(): array
    {
        return [
            self::FILE_DELETED => 0,
            self::FILE_KEPT => 0,
            self::FILE_FOREIGN => 0,
            self::FILE_NONE => 0,
        ];
    }

    /**
     * Delete one submission with all field rows and uploaded files.
     *
     * Files go first and outside a transaction: the asset store is not transactional, so a
     * rollback would bring back File rows whose assets are already gone. Uploads are detached
     * from their field once handled, so a retry after a failed form delete is safe.
     *
     * @return array<string,int> File outcome counts keyed by the FILE_* constants
     */
    public function deleteSubmission(SubmittedForm $form): array
    {
        $files = self::emptyCounts();

        $this->inDraft(function () use ($form, &$files) {
            foreach (SubmittedFileField::get()->filter('ParentID', $form->ID) as $field) {
                $files[$this->removeUpload($field)]++;
            }

            // Cascades to all SubmittedFormField rows (and subclass tables) via the ORM.
            $this->transaction(fn () => $form->delete());
        });

        return $files;
    }

    /**
     * What removeUpload() would do with the file of an upload field, without changing anything.
     *
     * @return string One of the FILE_* constants
     */
    public function classifyUpload(SubmittedFileField $field): string
    {
        $file = $this->findUploadedFile($field);
        if (!$file) {
            return self::FILE_NONE;
        }

        if (!$this->isFormUpload($file)) {
            return self::FILE_FOREIGN;
        }

        return $this->isInUse($file, $field) ? self::FILE_KEPT : self::FILE_DELETED;
    }

    /**
     * Archive the file attached to an upload field and detach it from the field.
     *
     * Files that are not form uploads or are still in use are kept, but detached as well, so a
     * later cascade on the field cannot touch them.
     *
     * @return string One of the FILE_* constants
     */
    public function removeUpload(SubmittedFileField $field): string
    {
        if (!$field->UploadedFileID) {
            return self::FILE_NONE;
        }

        $outcome = $this->classifyUpload($field);

        if ($outcome === self::FILE_DELETED) {
            $this->archiveFile($this->findUploadedFile($field));
        }

        $this->detachUpload($field);

        return $outcome;
    }

    /**
     * Remove version history rows of files that no longer exist in any stage.
     *
     * IDs that still have a draft or live row are skipped, so the history of a living file can
     * never be removed by mistake. Covers every table of the File hierarchy.
     *
     * @param array<int> $fileIDs
     * @return int Number of rows removed from the base versions table
     */
    public function purgeFileVersions(array $fileIDs): int
    {
        $fileIDs = $this->goneFileIDs($fileIDs);
        if (!$fileIDs) {
            return 0;
        }

        $placeholders = DB::placeholders($fileIDs);
        $tables = $this->fileVersionTables();
        $baseRows = 0;

        $this->transaction(function () use ($tables, $placeholders, $fileIDs, &$baseRows) {
            // Subclass version tables first, base table last (its count is what we report).
            foreach ($tables as $i => $table) {
                DB::prepared_query(
                    sprintf('DELETE FROM "%s" WHERE "RecordID" IN (%s)', $table, $placeholders),
                    $fileIDs
                );
                if ($i === count($tables) - 1) {
                    $baseRows = DB::affected_rows();
                }
            }
        });

        return $baseRows;
    }

    /**
     * Run a callback in a database transaction that is also rolled back on PHP errors.
     *
     * Database::withTransaction() only catches Exception. A TypeError or similar would leave the
     * transaction open, every later write would land in it, and the whole run would be rolled
     * back when the connection closes, while deleted assets stay deleted.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $db = DB::get_conn();
        if (!$db->supportsTransactions()) {
            return $callback();
        }

        $db->transactionStart();
        try {
            $result = $callback();
        } catch (Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
        $db->transactionEnd();

        return $result;
    }

    /**
     * Run a callback under an exclusive database lock.
     *
     * Returns false without running the callback if another run holds the lock. On databases
     * without lock support the callback always runs.
     */
    public function runExclusive(callable $callback): bool
    {
        $db = DB::get_conn();
        if (!$db->supportsLocks()) {
            $callback();
            return true;
        }

        if (!$db->getLock(self::LOCK_NAME, 0)) {
            return false;
        }

        try {
            $callback();
        } finally {
            try {
                $db->releaseLock(self::LOCK_NAME);
            } catch (Throwable) {
                // Connection is gone, and with it the lock. Do not hide the original error.
            }
        }

        return true;
    }

    /**
     * Run a callback with versioned reads and writes against the draft stage.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);
            return $callback();
        });
    }

    /**
     * Folders that form upload fields write to or wrote to: draft, live and version history,
     * so uploads stay recognisable after an editor changed or removed the field.
     *
     * @return array<int>
     */
    public function uploadFolderIDs(): array
    {
        if ($this->uploadFolderIDs === null) {
            $table = DataObject::getSchema()->tableName(EditableFileField::class);
            $ids = [];
            foreach ([$table, $table . '_Live', $table . '_Versions'] as $t) {
                if (DB::get_schema()->hasTable($t)) {
                    $ids = array_merge($ids, DB::query(sprintf('SELECT "FolderID" FROM "%s"', $t))->column());
                }
            }
            $this->uploadFolderIDs = array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        return $this->uploadFolderIDs;
    }

    private function findUploadedFile(SubmittedFileField $field): ?File
    {
        if (!$field->UploadedFileID) {
            return null;
        }

        return $this->inDraft(fn () => File::get()->byID($field->UploadedFileID))
            ?? Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($field->UploadedFileID);
    }

    private function archiveFile(File $file): void
    {
        $fileID = (int) $file->ID;

        // Unpublishes, removes the draft row and deletes the asset including variants from the
        // public and protected store. keep_archived_assets would instead move the asset to the
        // protected store, and the version purge below would then leave it without any record.
        Config::withConfig(function ($config) use ($file) {
            foreach (array_unique([File::class, $file->ClassName]) as $class) {
                $config->set($class, 'keep_archived_assets', false);
            }
            $this->inDraft(fn () => $file->doArchive());
        });

        if (static::config()->get('purge_file_versions')) {
            $this->purgeFileVersions([$fileID]);
        }
    }

    /**
     * Whether anything besides this upload field still uses the file.
     */
    private function isInUse(File $file, SubmittedFileField $field): bool
    {
        // Cheap checks first.
        $otherSubmission = SubmittedFileField::get()
            ->filter('UploadedFileID', $file->ID)
            ->exclude('ID', $field->ID)
            ->exists();
        if ($otherSubmission || FileLink::get()->filter('LinkedID', $file->ID)->exists()) {
            return true;
        }

        // has_one and many_many references from any record, the same lookup as the "Used on"
        // tab in the CMS. It also returns the file's own relations (its folder, its owner),
        // which do not count as usage.
        $related = Injector::inst()->get(RelatedDataService::class)->findAll($file, [ChangeSetItem::class]);
        foreach ($related as $record) {
            $isThisField = $record instanceof SubmittedFileField && (int) $record->ID === (int) $field->ID;
            if (!$isThisField && !$this->isOwnRelation($file, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $record is the target of one of the file's own has_one relations (e.g. Parent, Owner).
     */
    private function isOwnRelation(File $file, DataObject $record): bool
    {
        foreach ($file->hasOne() as $component => $class) {
            $sameID = (int) $file->getField($component . 'ID') === (int) $record->ID;
            if ($sameID && ($class === DataObject::class || $record instanceof $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a file is a form upload, read without side effects.
     *
     * Only files inside a folder that a form upload field writes to or wrote to count. The
     * UserFormUpload flag alone is not enough: File::isTrackedFormUpload() sets it to 't' as soon
     * as any submission points at the file, and old submissions can point at IDs that now belong
     * to editorial files. A form upload that an editor moved to another folder was taken over on
     * purpose and is kept as well. A flag of 'f' never counts.
     */
    private function isFormUpload(File $file): bool
    {
        $table = DataObject::getSchema()->tableName(File::class);
        $flag = DB::prepared_query(
            sprintf('SELECT "UserFormUpload" FROM "%s" WHERE "ID" = ?', $table),
            [$file->ID]
        )->value();

        if ($flag === 'f') {
            return false;
        }

        return in_array((int) $file->ParentID, $this->uploadFolderIDs(), true);
    }

    private function detachUpload(SubmittedFileField $field): void
    {
        $table = DataObject::getSchema()->tableName(SubmittedFileField::class);
        DB::prepared_query(
            sprintf('UPDATE "%s" SET "UploadedFileID" = 0 WHERE "ID" = ?', $table),
            [$field->ID]
        );
        $field->UploadedFileID = 0;
    }

    /**
     * @param array<int> $fileIDs
     * @return array<int> The IDs that exist neither in draft nor in live
     */
    private function goneFileIDs(array $fileIDs): array
    {
        $fileIDs = array_values(array_unique(array_filter(array_map('intval', $fileIDs))));
        if (!$fileIDs) {
            return [];
        }

        $table = DataObject::getSchema()->tableName(File::class);
        $placeholders = DB::placeholders($fileIDs);
        $existing = [];
        foreach ([$table, $table . '_Live'] as $t) {
            $existing = array_merge($existing, DB::prepared_query(
                sprintf('SELECT "ID" FROM "%s" WHERE "ID" IN (%s)', $t, $placeholders),
                $fileIDs
            )->column());
        }

        return array_values(array_diff($fileIDs, array_map('intval', $existing)));
    }

    /**
     * Versions tables of every class in the File hierarchy with its own table, base table last.
     *
     * @return array<string>
     */
    private function fileVersionTables(): array
    {
        $schema = DataObject::getSchema();
        $base = $schema->tableName(File::class) . '_Versions';
        $tables = [];
        foreach (ClassInfo::subclassesFor(File::class) as $class) {
            $table = $schema->tableName($class) . '_Versions';
            if ($table !== $base && $schema->classHasTable($class) && DB::get_schema()->hasTable($table)) {
                $tables[] = $table;
            }
        }
        $tables = array_values(array_unique($tables));
        $tables[] = $base;

        return $tables;
    }
}
