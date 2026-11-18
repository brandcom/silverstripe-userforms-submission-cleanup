<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask;
use SilverStripe\Assets\Shortcodes\FileLink;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use Symfony\Component\Console\Command\Command;

class DeleteExpiredSubmissionsTaskTest extends CleanupTestCase
{
    public function testDeletesOnlySubmissionsOlderThanRetention(): void
    {
        $old = $this->createSubmission('2026-08-01 00:00:00');
        $justExpired = $this->createSubmission('2026-08-19 11:59:59');
        $atCutoff = $this->createSubmission(self::CUTOFF);
        $justInside = $this->createSubmission('2026-08-19 12:00:01');
        $recent = $this->createSubmission('2026-11-17 11:59:00');

        $kept = [$atCutoff, $justInside, $recent];
        $before = array_map(fn ($f) => $this->submissionFingerprint($f->ID), $kept);

        [$exit] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFalse($this->formExists($old->ID));
        $this->assertFalse($this->formExists($justExpired->ID));
        $this->assertSame(0, $this->fieldCount($old->ID), 'field rows of deleted submissions must go too');
        $this->assertSame(0, $this->fieldCount($justExpired->ID));

        foreach ($kept as $i => $form) {
            $this->assertTrue($this->formExists($form->ID));
            $this->assertSame($before[$i], $this->submissionFingerprint($form->ID), 'kept submission was modified');
        }
    }

    public function testDaysOptionOverridesConfig(): void
    {
        Config::modify()->set(DeleteExpiredSubmissionsTask::class, 'retention_days', 365);
        $form = $this->createSubmission('2026-10-01 00:00:00');

        $this->runTask(DeleteExpiredSubmissionsTask::create());
        $this->assertTrue($this->formExists($form->ID), 'config retention 365 days keeps it');

        $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--days' => '30']);
        $this->assertFalse($this->formExists($form->ID), '--days=30 deletes it');
    }

    public function testRejectsInvalidDays(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');

        foreach (['0', '-5', 'abc', '1.5'] as $days) {
            [$exit] = $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--days' => $days]);
            $this->assertSame(Command::INVALID, $exit, "--days=$days");
        }

        $this->assertTrue($this->formExists($form->ID));
    }

    public function testEnvOverridesConfigAndDaysOverridesEnv(): void
    {
        Config::modify()->set(DeleteExpiredSubmissionsTask::class, 'retention_days', 365);
        $form = $this->createSubmission('2026-10-01 00:00:00');

        Environment::setEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV, '400');
        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--days' => '30', '--dry-run' => true]);
        $this->assertStringContainsString('(30 days, from --days)', $out);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());
        $this->assertStringContainsString('(400 days, from ' . DeleteExpiredSubmissionsTask::RETENTION_ENV . ')', $out);
        $this->assertTrue($this->formExists($form->ID), 'env retention 400 days keeps it');

        Environment::setEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV, ' 30 ');
        $this->runTask(DeleteExpiredSubmissionsTask::create());
        $this->assertFalse($this->formExists($form->ID), 'env retention 30 days beats config 365');
    }

    public function testEmptyEnvFallsBackToConfig(): void
    {
        Config::modify()->set(DeleteExpiredSubmissionsTask::class, 'retention_days', 365);
        Environment::setEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV, '  ');
        $form = $this->createSubmission('2026-10-01 00:00:00');

        [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('(365 days, from retention_days config)', $out);
        $this->assertTrue($this->formExists($form->ID));
    }

    public function testRejectsInvalidEnvWithoutFallback(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');

        foreach (['0', '-5', 'abc', '30d', '1.5', '7 days'] as $days) {
            Environment::setEnv(DeleteExpiredSubmissionsTask::RETENTION_ENV, $days);
            [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());
            $this->assertSame(Command::INVALID, $exit, "env=$days");
            $this->assertStringContainsString(DeleteExpiredSubmissionsTask::RETENTION_ENV, $out);
        }

        $this->assertTrue($this->formExists($form->ID), 'an invalid env value must not delete anything');
    }

    public function testDryRunChangesNothing(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('cv.pdf');
        $this->attachUpload($form, $file);
        $fingerprint = $this->submissionFingerprint($form->ID);

        [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create(), ['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Submissions: 1', $out);
        $this->assertStringContainsString('Uploaded files referenced: 1', $out);
        $this->assertSame($fingerprint, $this->submissionFingerprint($form->ID));
        $this->assertSame(1, $this->fileRowCounts($file->ID)['draft']);
        $this->assertTrue($this->assetExists($file->getFilename(), $file->getHash()));
    }

    public function testDeletesDraftOnlyUploadWithAssetAndVersions(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('draft.pdf');
        $this->attachUpload($form, $file);
        [$filename, $hash] = [$file->getFilename(), $file->getHash()];
        $this->assertTrue($this->assetExists($filename, $hash));

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($file->ID));
        $this->assertFalse($this->assetExists($filename, $hash), 'asset must be removed from the store');
    }

    public function testDeletesPublishedUploadWithAssetAndVersions(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('published.pdf', 't', true);
        $this->attachUpload($form, $file);
        [$filename, $hash] = [$file->getFilename(), $file->getHash()];
        $this->assertSame(1, $this->fileRowCounts($file->ID)['live']);

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($file->ID));
        $this->assertFalse($this->assetExists($filename, $hash));
    }

    public function testKeepsVersionsWhenPurgeIsDisabled(): void
    {
        Config::modify()->set(SubmissionCleaner::class, 'purge_file_versions', false);
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('keepversions.pdf');
        $this->attachUpload($form, $file);

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $counts = $this->fileRowCounts($file->ID);
        $this->assertSame(0, $counts['draft']);
        $this->assertGreaterThan(0, $counts['versions']);
    }

    public function testNeverTouchesUploadsOfNewerSubmissions(): void
    {
        $old = $this->createSubmission('2026-01-01 00:00:00');
        $this->attachUpload($old, $this->createUpload('old.pdf'));

        $new = $this->createSubmission('2026-11-01 00:00:00');
        $newFile = $this->createUpload('new.pdf', 't', true);
        $this->attachUpload($new, $newFile);
        $fileBefore = $this->fileRowCounts($newFile->ID);
        $formBefore = $this->submissionFingerprint($new->ID);

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame($formBefore, $this->submissionFingerprint($new->ID));
        $this->assertSame($fileBefore, $this->fileRowCounts($newFile->ID));
        $this->assertTrue($this->assetExists($newFile->getFilename(), $newFile->getHash()));
    }

    public function testKeepsFileSharedWithNewerSubmission(): void
    {
        $shared = $this->createUpload('shared.pdf');
        $old = $this->createSubmission('2026-01-01 00:00:00');
        $this->attachUpload($old, $shared);
        $new = $this->createSubmission('2026-11-01 00:00:00');
        $newField = $this->attachUpload($new, $shared);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('1 kept', $out);
        $this->assertFalse($this->formExists($old->ID));
        $this->assertSame(1, $this->fileRowCounts($shared->ID)['draft']);
        $this->assertTrue($this->assetExists($shared->getFilename(), $shared->getHash()));
        $this->assertSame(
            $shared->ID,
            (int) DB::query(
                "SELECT \"UploadedFileID\" FROM \"SubmittedFileField\" WHERE \"ID\" = {$newField->ID}"
            )->value(),
            'the newer submission must still point at the file'
        );
    }

    public function testKeepsFileNotCreatedByForm(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $editorial = $this->createUpload('editorial.jpg', 'f', true);
        $this->attachUpload($form, $editorial);
        $rowBefore = $this->fileRow($editorial->ID);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('1 kept', $out);
        $this->assertFalse($this->formExists($form->ID));
        $this->assertSame(1, $this->fileRowCounts($editorial->ID)['draft']);
        $this->assertSame(1, $this->fileRowCounts($editorial->ID)['live']);
        $this->assertTrue($this->assetExists($editorial->getFilename(), $editorial->getHash()));
        $this->assertSame($rowBefore, $this->fileRow($editorial->ID), 'kept file must not be modified');
    }

    public function testUnknownFlagOutsideUploadFolderIsKeptUntouched(): void
    {
        $this->registerUploadFolder('Mail');
        $form = $this->createSubmission('2026-01-01 00:00:00');
        // e.g. an old submission whose file ID now belongs to an editorial image
        $file = $this->createUpload('header.jpg', null, true, 'Images');
        $this->attachUpload($form, $file);
        $rowBefore = $this->fileRow($file->ID);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('1 kept', $out);
        $this->assertFalse($this->formExists($form->ID));
        $this->assertSame($rowBefore, $this->fileRow($file->ID), 'no flag written as side effect');
        $this->assertTrue($this->assetExists($file->getFilename(), $file->getHash()));
    }

    public function testUnknownFlagInUploadFolderIsDeleted(): void
    {
        $this->registerUploadFolder('Mail');
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('legacy-cv.pdf', null, false, 'Mail');
        $this->attachUpload($form, $file);

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('1 uploaded files deleted', $out);
        $this->assertSame(['draft' => 0, 'live' => 0, 'versions' => 0], $this->fileRowCounts($file->ID));
    }

    public function testFlaggedUploadIsDeletedEvenIfMovedOutOfUploadFolder(): void
    {
        $this->registerUploadFolder('Mail');
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('moved.pdf', 't', false, 'Elsewhere');
        $this->attachUpload($form, $file);

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(0, $this->fileRowCounts($file->ID)['draft']);
    }

    public function testKeepsFileLinkedInContent(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $file = $this->createUpload('linked.pdf');
        $this->attachUpload($form, $file);
        // any record whose content links the file; framework-only so the test needs no CMS
        $parent = Group::create(['Title' => 'Links the file']);
        $parent->write();
        FileLink::create([
            'LinkedID' => $file->ID,
            'ParentID' => $parent->ID,
            'ParentClass' => Group::class,
        ])->write();

        $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(1, $this->fileRowCounts($file->ID)['draft']);
        $this->assertTrue($this->assetExists($file->getFilename(), $file->getHash()));
    }

    public function testSecondRunIsNoop(): void
    {
        $this->attachUpload($this->createSubmission('2026-01-01 00:00:00'), $this->createUpload('a.pdf'));
        $this->runTask(DeleteExpiredSubmissionsTask::create());

        [$exit, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Deleted 0 submissions, 0 uploaded files deleted', $out);
    }

    public function testProcessesMoreThanOneBatch(): void
    {
        Config::modify()->set(DeleteExpiredSubmissionsTask::class, 'batch_size', 3);
        for ($i = 0; $i < 10; $i++) {
            $this->createSubmission('2026-01-01 00:00:00');
        }
        $kept = $this->createSubmission('2026-11-01 00:00:00');

        [, $out] = $this->runTask(DeleteExpiredSubmissionsTask::create());

        $this->assertStringContainsString('Deleted 10 submissions', $out);
        $this->assertSame(1, (int) DB::query('SELECT COUNT(*) FROM "SubmittedForm"')->value());
        $this->assertTrue($this->formExists($kept->ID));
    }

    public function testSkipsWhileAnotherRunHoldsTheLock(): void
    {
        $form = $this->createSubmission('2026-01-01 00:00:00');
        $this->holdLockElsewhere(SubmissionCleaner::LOCK_NAME);

        $task = DeleteExpiredSubmissionsTask::create();
        [$exit, $out] = $this->runTask($task);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertTrue($task->wasSkipped());
        $this->assertStringContainsString('in progress', $out);
        $this->assertTrue($this->formExists($form->ID));
    }
}
