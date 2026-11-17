<?php

namespace Brandcom\UserFormsSubmissionCleanup;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Shortcodes\FileLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\Versioned\Versioned;

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

    public const FILE_NONE = 'none';
    public const FILE_DELETED = 'deleted';
    public const FILE_KEPT = 'kept';

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
        $files = [self::FILE_DELETED => 0, self::FILE_KEPT => 0, self::FILE_NONE => 0];

        $this->inDraft(function () use ($form, &$files) {
            foreach (SubmittedFileField::get()->filter('ParentID', $form->ID) as $field) {
                $files[$this->removeUpload($field)]++;
            }

            // Cascades to all SubmittedFormField rows (and subclass tables) via the ORM.
            DB::get_conn()->withTransaction(fn () => $form->delete());
        });

        return $files;
    }

    /**
     * Archive the file attached to an upload field and detach it from the field.
     *
     * Files that are not clearly form uploads, are linked in content, or are referenced by
     * another submission are kept, but still detached so a later cascade on the field cannot touch them.
     *
     * @return string One of the FILE_* constants
     */
    public function removeUpload(SubmittedFileField $field): string
    {
        if (!$field->UploadedFileID) {
            return self::FILE_NONE;
        }

        $file = $this->inDraft(fn () => File::get()->byID($field->UploadedFileID))
            ?? Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($field->UploadedFileID);

        $outcome = self::FILE_NONE;

        if ($file) {
            if ($this->isReusedElsewhere($file, $field)) {
                $outcome = self::FILE_KEPT;
            } else {
                $this->archiveFile($file);
                $outcome = self::FILE_DELETED;
            }
        }

        $this->detachUpload($field);

        return $outcome;
    }

    /**
     * Remove version history rows of files that no longer exist in any stage.
     *
     * @param array<int> $fileIDs
     * @return int Number of rows removed from the base versions table
     */
    public function purgeFileVersions(array $fileIDs, string $fileClass = File::class): int
    {
        $fileIDs = array_values(array_filter(array_map('intval', $fileIDs)));
        if (!$fileIDs) {
            return 0;
        }

        $schema = DataObject::getSchema();
        $placeholders = DB::placeholders($fileIDs);
        $baseRows = 0;

        // Subclass version tables first, base table last (its count is what we report).
        $tables = [];
        foreach (ClassInfo::ancestry($fileClass) as $class) {
            if (is_a($class, DataObject::class, true) && $schema->classHasTable($class)) {
                $tables[] = $schema->tableName($class) . '_Versions';
            }
        }

        foreach (array_reverse($tables) as $i => $table) {
            DB::prepared_query(
                sprintf('DELETE FROM "%s" WHERE "RecordID" IN (%s)', $table, $placeholders),
                $fileIDs
            );
            if ($i === count($tables) - 1) {
                $baseRows = DB::affected_rows();
            }
        }

        return $baseRows;
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
            $db->releaseLock(self::LOCK_NAME);
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

    private function archiveFile(File $file): void
    {
        $fileID = (int) $file->ID;
        $fileClass = $file->ClassName;

        // Unpublishes, removes the draft row and, with keep_archived_assets = false (default),
        // deletes the asset including variants from the public and protected store.
        $this->inDraft(fn () => $file->doArchive());

        if (static::config()->get('purge_file_versions')) {
            $this->purgeFileVersions([$fileID], $fileClass);
        }
    }

    private function isReusedElsewhere(File $file, SubmittedFileField $field): bool
    {
        if (!$this->isFormUpload($file)) {
            return true;
        }

        if (FileLink::get()->filter('LinkedID', $file->ID)->exists()) {
            return true;
        }

        return SubmittedFileField::get()
            ->filter('UploadedFileID', $file->ID)
            ->exclude('ID', $field->ID)
            ->exists();
    }

    /**
     * Whether a file was created by a form submission, read without side effects.
     *
     * UserFormFileExtension keeps a tri-state flag on the draft row: 't', 'f' or NULL
     * (unknown, e.g. uploads from before the flag existed). File::isTrackedFormUpload() would
     * resolve NULL to "upload" as soon as any submission field points at the file, and write
     * the result back to the database. Old submissions can point at IDs that now belong to
     * editorial files, so NULL only counts as an upload if the file sits in a folder that a
     * form upload field writes to. 'f' never counts.
     */
    private function isFormUpload(File $file): bool
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableName(File::class);
        $flag = DB::prepared_query(
            sprintf('SELECT "UserFormUpload" FROM "%s" WHERE "ID" = ?', $table),
            [$file->ID]
        )->value();

        if ($flag === 't') {
            return true;
        }

        // NULL/empty: unknown. false: no draft row (live-only file), treat the same way.
        if ($flag === null || $flag === '' || $flag === false) {
            return in_array((int) $file->ParentID, $this->uploadFolderIDs(), true);
        }

        return false;
    }

    /**
     * Folders that form upload fields write to (draft and live configuration).
     *
     * @return array<int>
     */
    private function uploadFolderIDs(): array
    {
        if ($this->uploadFolderIDs === null) {
            $table = DataObject::getSchema()->tableName(EditableFileField::class);
            $ids = [];
            foreach ([$table, $table . '_Live'] as $t) {
                if (DB::get_schema()->hasTable($t)) {
                    $ids = array_merge($ids, DB::query(sprintf('SELECT "FolderID" FROM "%s"', $t))->column());
                }
            }
            $this->uploadFolderIDs = array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        return $this->uploadFolderIDs;
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
}
