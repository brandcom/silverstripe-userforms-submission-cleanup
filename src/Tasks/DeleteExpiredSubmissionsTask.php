<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tasks;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use InvalidArgumentException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
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
        $this->skipped = false;

        // Never fall back to another value on invalid input: a typo must not widen what gets
        // deleted or remove a limit.
        try {
            [$days, $source] = $this->retentionDays($input);
            $maxRuntime = self::parseInt($input->getOption('max-runtime') ?? '0', '--max-runtime', 0);
            $batchSize = self::parseInt(static::config()->get('batch_size'), 'batch_size config', 1);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</>');
            return Command::INVALID;
        }

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

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $exitCode = Command::SUCCESS;
        $ran = SubmissionCleaner::singleton()->runExclusive(
            function () use ($cutoff, $maxRuntime, $batchSize, $output, &$exitCode) {
                $exitCode = $this->deleteExpired($cutoff, $maxRuntime, $batchSize, $output);
            }
        );

        if (!$ran) {
            $this->skipped = true;
            $output->writeln('<comment>Another cleanup run is in progress, skipped.</>');
        }

        return $exitCode;
    }

    /**
     * Whether the last run was skipped because another run held the lock.
     */
    public function wasSkipped(): bool
    {
        return $this->skipped;
    }

    /**
     * Parse a whole number of at least $min, as given on the CLI, in the environment or in config.
     *
     * @throws InvalidArgumentException naming the source of the invalid value
     */
    public static function parseInt(mixed $raw, string $source, int $min): int
    {
        $value = trim((string) $raw);
        if (!preg_match('/^[0-9]+$/', $value) || (int) $value < $min) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a whole number of at least %d, got "%s"',
                $source,
                $min,
                $value
            ));
        }

        return (int) $value;
    }

    /**
     * Retention window and where it came from: --days, then the environment variable, then
     * config. An empty environment variable counts as unset.
     *
     * @return array{0: int, 1: string}
     * @throws InvalidArgumentException if the value from the winning source is not a positive integer
     */
    private function retentionDays(InputInterface $input): array
    {
        $option = $input->getOption('days');
        $env = Environment::getEnv(self::RETENTION_ENV);

        if ($option !== null) {
            [$raw, $source] = [$option, '--days'];
        } elseif ($env !== false && $env !== null && trim((string) $env) !== '') {
            [$raw, $source] = [$env, self::RETENTION_ENV];
        } else {
            [$raw, $source] = [static::config()->get('retention_days'), 'retention_days config'];
        }

        return [self::parseInt($raw, $source, 1), $source];
    }

    private function deleteExpired(string $cutoff, int $maxRuntime, int $batchSize, PolyOutput $output): int
    {
        $deadline = $maxRuntime > 0 ? microtime(true) + $maxRuntime : null;
        $timedOut = false;
        $cleaner = SubmissionCleaner::singleton();
        $db = DB::get_conn();
        $depth = $db->supportsTransactions() ? $db->transactionDepth() : 0;
        $lastID = 0;
        $deleted = 0;
        $failed = 0;
        $files = SubmissionCleaner::emptyCounts();

        do {
            // Keyset pagination: every pass moves forward, also past submissions that failed or
            // that an extension refused to delete.
            $batch = SubmittedForm::get()
                ->filter(['Created:LessThan' => $cutoff, 'ID:GreaterThan' => $lastID])
                ->sort('ID')
                ->limit($batchSize)
                ->toArray();

            foreach ($batch as $form) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $timedOut = true;
                    break 2;
                }

                $lastID = (int) $form->ID;

                try {
                    foreach ($cleaner->deleteSubmission($form) as $outcome => $count) {
                        $files[$outcome] += $count;
                    }
                    $deleted++;
                } catch (Throwable $e) {
                    $failed++;
                    $output->writeln(sprintf('<error>Submission #%d failed: %s</>', $form->ID, $e->getMessage()));
                }

                // Safety net: a transaction left open by any code path would take every later
                // write with it and be rolled back when the connection closes.
                if ($db->supportsTransactions() && $db->transactionDepth() !== $depth) {
                    $output->writeln('<error>Database transaction left open, run aborted.</>');
                    return Command::FAILURE;
                }
            }

            DataObject::flush_and_destroy_cache();
        } while (count($batch) === $batchSize);

        $output->writeln(sprintf(
            '<info>Deleted %d submissions</>, uploaded files: %s',
            $deleted,
            self::describeFiles($files, false)
        ));

        if ($timedOut) {
            $output->writeln('<comment>Runtime limit reached, remaining submissions follow on the next run</>');
        }

        if ($failed) {
            $output->writeln(sprintf('<error>%d submissions could not be deleted</>', $failed));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Dry run: count submissions and classify their uploads exactly as a real run would.
     *
     * A file shared by several expired submissions counts as kept for each of them here, while
     * a real run deletes it with the last one.
     */
    private function report(string $cutoff, PolyOutput $output): void
    {
        $cleaner = SubmissionCleaner::singleton();
        $forms = SubmittedForm::get()->filter('Created:LessThan', $cutoff);
        $fields = SubmittedFileField::get()->filter([
            'UploadedFileID:GreaterThan' => 0,
            'Parent.Created:LessThan' => $cutoff,
        ]);

        $files = SubmissionCleaner::emptyCounts();
        foreach ($fields as $field) {
            $files[$cleaner->classifyUpload($field)]++;
        }

        $output->writeln(sprintf('Submissions: <info>%d</>', $forms->count()));
        $output->writeln('Uploaded files: ' . self::describeFiles($files, true));
    }

    /**
     * @param array<string,int> $files
     */
    public static function describeFiles(array $files, bool $dryRun): string
    {
        return sprintf(
            '%d %s, %d kept (still in use), %d kept (not a form upload), %d already gone',
            $files[SubmissionCleaner::FILE_DELETED],
            $dryRun ? 'would be deleted' : 'deleted',
            $files[SubmissionCleaner::FILE_KEPT],
            $files[SubmissionCleaner::FILE_FOREIGN],
            $files[SubmissionCleaner::FILE_NONE]
        );
    }
}
