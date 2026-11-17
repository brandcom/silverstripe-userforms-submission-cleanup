<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use Brandcom\UserFormsSubmissionCleanup\Tasks\PurgeOrphanedSubmissionDataTask;
use SilverStripe\ORM\DB;
use Symfony\Component\Console\Command\Command;

class PurgeOrphanedSubmissionDataTaskTest extends CleanupTestCase
{
    /**
     * Simulates the classic broken cleanup: parent row removed via raw SQL, fields left behind.
     */
    private function orphan(int $formID): void
    {
        DB::prepared_query('DELETE FROM "SubmittedForm" WHERE "ID" = ?', [$formID]);
    }

    public function testRemovesOrphanedFieldsAndUploadsOnly(): void
    {
        $orphaned = $this->createSubmission('2026-11-01 00:00:00', ['Name' => 'x', 'Mail' => 'y']);
        $orphanFile = $this->createUpload('orphan.pdf', 't', true);
        $orphanField = $this->attachUpload($orphaned, $orphanFile);
        $this->orphan($orphaned->ID);

        $healthy = $this->createSubmission('2026-11-01 00:00:00');
        $healthyFile = $this->createUpload('healthy.pdf');
        $this->attachUpload($healthy, $healthyFile);
        $healthyBefore = $this->submissionFingerprint($healthy->ID);
        $healthyFileBefore = $this->fileRowCounts($healthyFile->ID);

        [$exit, $out] = $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Deleted 3 orphaned field rows', $out);
        $this->assertSame(0, $this->fieldCount($orphaned->ID));
        $this->assertSame(
            0,
            (int) DB::query("SELECT COUNT(*) FROM \"SubmittedFileField\" WHERE \"ID\" = {$orphanField->ID}")->value(),
            'subclass table row must go too'
        );
        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($orphanFile->ID));
        $this->assertFalse($this->assetExists($orphanFile->getFilename(), $orphanFile->getHash()));

        $this->assertSame($healthyBefore, $this->submissionFingerprint($healthy->ID));
        $this->assertSame($healthyFileBefore, $this->fileRowCounts($healthyFile->ID));
        $this->assertTrue($this->assetExists($healthyFile->getFilename(), $healthyFile->getHash()));
    }

    public function testKeepsNonFormFileReferencedByOrphan(): void
    {
        $orphaned = $this->createSubmission('2026-11-01 00:00:00');
        $editorial = $this->createUpload('editorial.jpg', 'f');
        $this->attachUpload($orphaned, $editorial);
        $this->orphan($orphaned->ID);

        $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertSame(1, $this->fileRowCounts($editorial->ID)['draft']);
        $this->assertTrue($this->assetExists($editorial->getFilename(), $editorial->getHash()));
    }

    public function testDryRunChangesNothing(): void
    {
        $orphaned = $this->createSubmission('2026-11-01 00:00:00');
        $file = $this->createUpload('orphan.pdf');
        $this->attachUpload($orphaned, $file);
        $this->orphan($orphaned->ID);

        [, $out] = $this->runTask(PurgeOrphanedSubmissionDataTask::create(), ['--dry-run' => true]);

        $this->assertStringContainsString('Orphaned field rows: 2', $out);
        $this->assertStringContainsString('Uploaded files on orphaned fields: 1', $out);
        $this->assertSame(2, $this->fieldCount($orphaned->ID));
        $this->assertSame(1, $this->fileRowCounts($file->ID)['draft']);
    }

    public function testRefusesWhileAnotherRunHoldsTheLock(): void
    {
        $orphaned = $this->createSubmission('2026-11-01 00:00:00');
        $this->orphan($orphaned->ID);
        $this->holdLockElsewhere(SubmissionCleaner::LOCK_NAME);

        [$exit] = $this->runTask(PurgeOrphanedSubmissionDataTask::create());

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(1, $this->fieldCount($orphaned->ID));
    }
}
