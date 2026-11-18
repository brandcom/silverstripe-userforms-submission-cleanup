<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tasks;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Deletes userforms submissions older than the retention window, including field rows and
 * uploaded files. Meant to run from cron:
 *
 *   vendor/bin/sake tasks:userforms-delete-expired
 *   vendor/bin/sake tasks:userforms-delete-expired --days=30 --dry-run
 *
 * Retention window, first match wins: --days, env USERFORMS_CLEANUP_RETENTION_DAYS,
 * config retention_days (default 90).
 *
 * Config (YAML):
 *   Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask:
 *     retention_days: 90
 *   Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner:
 *     purge_file_versions: true
 */
class DeleteExpiredSubmissionsTask extends BuildTask
{
    public const RETENTION_ENV = 'USERFORMS_CLEANUP_RETENTION_DAYS';

    protected static string $commandName = 'userforms-delete-expired';

    protected string $title = 'Delete expired form submissions';

    protected static string $description = 'Deletes userforms submissions older than the retention '
        . 'window, including field rows and uploaded files';

    /**
     * Days of submissions to keep.
     */
    private static int $retention_days = 90;

    /**
     * Submissions loaded per pass.
     */
    private static int $batch_size = 100;

    private bool $skipped = false;

    public function getOptions(): array
    {
        return [
            new InputOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window in days <comment>[default: env ' . self::RETENTION_ENV
                    . ', else retention_days config]</comment>'
            ),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted'),
            new InputOption(
                'max-runtime',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after this many seconds, the next run continues <comment>[default: unlimited]</comment>'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        [$days, $source] = $this->retentionDays($input);
        if (!preg_match('/^[1-9][0-9]*$/', $days)) {
            // Never fall back to another value: a typo must not widen what gets deleted.
            $output->writeln(sprintf('<error>%s must be a positive integer, got "%s"</>', $source, $days));
            return Command::INVALID;
        }
        $days = (int) $days;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days", DBDatetime::now()->getTimestamp()));
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln(sprintf(
            'Deleting submissions created before <info>%s</> (%d days, from %s)%s',
            $cutoff,
            $days,
            $source,
            $dryRun ? ' <comment>[dry run]</>' : ''
        ));

        if ($dryRun) {
            $this->report($cutoff, $output);
            return Command::SUCCESS;
        }

        $maxRuntime = (int) $input->getOption('max-runtime');
        if ($maxRuntime < 0) {
            $output->writeln('<error>--max-runtime must be zero or greater</>');
            return Command::INVALID;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $exitCode = Command::SUCCESS;
        $ran = SubmissionCleaner::singleton()->runExclusive(
            function () use ($cutoff, $maxRuntime, $output, &$exitCode) {
                $exitCode = $this->deleteExpired($cutoff, $maxRuntime, $output);
            }
        );

        if (!$ran) {
            $this->skipped = true;
            $output->writeln('<comment>Another cleanup run is in progress, skipped.</>');
        }

        return $exitCode;
    }

    /**
     * Retention window as raw string plus where it came from: --days, then the environment
     * variable, then config. An empty environment variable counts as unset.
     *
     * @return array{0: string, 1: string}
     */
    private function retentionDays(InputInterface $input): array
    {
        $option = $input->getOption('days');
        if ($option !== null) {
            return [trim((string) $option), '--days'];
        }

        $env = Environment::getEnv(self::RETENTION_ENV);
        if ($env !== false && $env !== null && trim((string) $env) !== '') {
            return [trim((string) $env), self::RETENTION_ENV];
        }

        return [trim((string) static::config()->get('retention_days')), 'retention_days config'];
    }

    /**
     * Whether the last run was skipped because another run held the lock.
     */
    public function wasSkipped(): bool
    {
        return $this->skipped;
    }

    private function deleteExpired(string $cutoff, int $maxRuntime, PolyOutput $output): int
    {
        $deadline = $maxRuntime > 0 ? microtime(true) + $maxRuntime : null;
        $timedOut = false;
        $cleaner = SubmissionCleaner::singleton();
        $batchSize = (int) static::config()->get('batch_size');
        $deleted = 0;
        $failedIDs = [];
        $files = [SubmissionCleaner::FILE_DELETED => 0, SubmissionCleaner::FILE_KEPT => 0];

        do {
            $batch = SubmittedForm::get()
                ->filter('Created:LessThan', $cutoff)
                ->sort('ID')
                ->limit($batchSize);
            if ($failedIDs) {
                $batch = $batch->exclude('ID', $failedIDs);
            }
            $batch = $batch->toArray();

            foreach ($batch as $form) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $timedOut = true;
                    break 2;
                }

                try {
                    $result = $cleaner->deleteSubmission($form);
                    $files[SubmissionCleaner::FILE_DELETED] += $result[SubmissionCleaner::FILE_DELETED];
                    $files[SubmissionCleaner::FILE_KEPT] += $result[SubmissionCleaner::FILE_KEPT];
                    $deleted++;
                } catch (Throwable $e) {
                    $failedIDs[] = (int) $form->ID;
                    $output->writeln(sprintf(
                        '<error>Submission #%d failed: %s</>',
                        $form->ID,
                        $e->getMessage()
                    ));
                }
            }

            DataObject::flush_and_destroy_cache();
        } while (count($batch) === $batchSize);

        $output->writeln(sprintf(
            '<info>Deleted %d submissions</>, %d uploaded files deleted, %d kept (reused elsewhere)',
            $deleted,
            $files[SubmissionCleaner::FILE_DELETED],
            $files[SubmissionCleaner::FILE_KEPT]
        ));

        if ($timedOut) {
            $output->writeln('<comment>Runtime limit reached, remaining submissions follow on the next run</>');
        }

        if ($failedIDs) {
            $output->writeln(sprintf('<error>%d submissions could not be deleted</>', count($failedIDs)));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function report(string $cutoff, PolyOutput $output): void
    {
        $forms = SubmittedForm::get()->filter('Created:LessThan', $cutoff);
        $files = SubmittedFileField::get()->filter([
            'UploadedFileID:GreaterThan' => 0,
            'Parent.Created:LessThan' => $cutoff,
        ]);

        $output->writeln(sprintf('Submissions: <info>%d</>', $forms->count()));
        $output->writeln(sprintf('Uploaded files referenced: <info>%d</>', $files->count()));
    }
}
