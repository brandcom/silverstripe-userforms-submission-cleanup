<?php

namespace Brandcom\UserFormsSubmissionCleanup\Control;

use Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs DeleteExpiredSubmissionsTask over HTTP, for hosts whose cron can only call URLs.
 *
 *   https://example.com/_userforms-cleanup?token=<USERFORMS_CLEANUP_TOKEN>
 *   curl -H "X-Cleanup-Token: <token>" https://example.com/_userforms-cleanup
 *
 * Disabled (404) unless USERFORMS_CLEANUP_TOKEN is set with at least 32 characters.
 * A wrong token also answers 404, so the endpoint does not reveal itself.
 * The header is preferred: a token in the query string ends up in access logs.
 *
 * Optional: ?dry-run=1 only counts. The retention window is not settable over HTTP.
 * Each call stops after max_runtime seconds; the next call continues.
 */
class CleanupController extends Controller
{
    public const TOKEN_ENV = 'USERFORMS_CLEANUP_TOKEN';

    private const MIN_TOKEN_LENGTH = 32;

    private const LOCK_NAME = 'userforms-cleanup';

    private static $url_handlers = [
        '' => 'index',
    ];

    private static $allowed_actions = [
        'index',
    ];

    /**
     * Seconds per request, kept below typical shared-hosting limits (All-Inkl: 30-60 s).
     */
    private static int $max_runtime = 20;

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!$this->isAuthorised($request)) {
            return $this->plain('Not found', 404);
        }

        // Two overlapping cron calls must not work on the same submissions.
        $db = DB::get_conn();
        $locked = $db->canLock(self::LOCK_NAME);
        if ($locked && !$db->getLock(self::LOCK_NAME, 0)) {
            return $this->plain('Cleanup already running', 409);
        }

        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);

        try {
            $task = DeleteExpiredSubmissionsTask::create();
            $input = new ArrayInput(
                array_filter([
                    '--dry-run' => (bool) $request->getVar('dry-run'),
                    '--max-runtime' => (string) static::config()->get('max_runtime'),
                ]),
                new InputDefinition($task->getOptions())
            );
            $exitCode = $task->run($input, $output);
        } catch (Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            $exitCode = Command::FAILURE;
        } finally {
            if ($locked) {
                $db->releaseLock(self::LOCK_NAME);
            }
        }

        return $this->plain($buffer->fetch(), $exitCode === Command::SUCCESS ? 200 : 500);
    }

    private function isAuthorised(HTTPRequest $request): bool
    {
        $expected = (string) Environment::getEnv(self::TOKEN_ENV);
        if (strlen($expected) < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        $given = (string) ($request->getHeader('X-Cleanup-Token') ?: $request->getVar('token'));

        return hash_equals($expected, $given);
    }

    private function plain(string $body, int $status): HTTPResponse
    {
        return HTTPResponse::create($body, $status)
            ->addHeader('Content-Type', 'text/plain; charset=utf-8')
            ->addHeader('Cache-Control', 'no-store')
            ->addHeader('X-Robots-Tag', 'noindex');
    }
}
