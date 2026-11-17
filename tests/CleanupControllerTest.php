<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests;

use Brandcom\UserFormsSubmissionCleanup\Control\CleanupController;
use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;

class CleanupControllerTest extends CleanupTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';

    private ?string $previousToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousToken = Environment::getEnv(CleanupController::TOKEN_ENV) ?: null;
        Environment::setEnv(CleanupController::TOKEN_ENV, self::TOKEN);
    }

    protected function tearDown(): void
    {
        Environment::setEnv(CleanupController::TOKEN_ENV, $this->previousToken ?? '');
        parent::tearDown();
    }

    private function call(string $query = '', array $headers = []): HTTPResponse
    {
        return Director::test('_userforms-cleanup' . $query, null, null, 'GET', null, $headers);
    }

    public function testDisabledWithoutOrWithShortToken(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');

        Environment::setEnv(CleanupController::TOKEN_ENV, '');
        $this->assertSame(404, $this->call('?token=')->getStatusCode());

        Environment::setEnv(CleanupController::TOKEN_ENV, 'short');
        $this->assertSame(404, $this->call('?token=short')->getStatusCode());

        $this->assertTrue($this->formExists($form->ID));
    }

    public function testRejectsMissingAndWrongToken(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');

        $this->assertSame(404, $this->call()->getStatusCode());
        $this->assertSame(404, $this->call('?token=' . strrev(self::TOKEN))->getStatusCode());
        $this->assertSame(404, $this->call('', ['X-Cleanup-Token' => self::TOKEN . 'x'])->getStatusCode());

        $this->assertTrue($this->formExists($form->ID));
    }

    public function testRunsWithHeaderToken(): void
    {
        $old = $this->createSubmission('2020-01-01 00:00:00');
        $new = $this->createSubmission('2026-11-01 00:00:00');

        $response = $this->call('', ['X-Cleanup-Token' => self::TOKEN]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Deleted 1 submissions', $response->getBody());
        $this->assertStringStartsWith('text/plain', $response->getHeader('Content-Type'));
        $this->assertFalse($this->formExists($old->ID));
        $this->assertTrue($this->formExists($new->ID));
    }

    public function testRunsWithQueryToken(): void
    {
        $old = $this->createSubmission('2020-01-01 00:00:00');

        $this->assertSame(200, $this->call('?token=' . self::TOKEN)->getStatusCode());
        $this->assertFalse($this->formExists($old->ID));
    }

    public function testDryRunDeletesNothing(): void
    {
        $old = $this->createSubmission('2020-01-01 00:00:00');

        $response = $this->call('?dry-run=1', ['X-Cleanup-Token' => self::TOKEN]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Submissions: 1', $response->getBody());
        $this->assertTrue($this->formExists($old->ID));
    }

    public function testRetentionCannotBeChangedOverHttp(): void
    {
        $form = $this->createSubmission('2026-11-01 00:00:00');

        $this->call('?days=1', ['X-Cleanup-Token' => self::TOKEN]);

        $this->assertTrue($this->formExists($form->ID));
    }

    public function testConflictWhileAnotherRunHoldsTheLock(): void
    {
        $form = $this->createSubmission('2020-01-01 00:00:00');
        $this->holdLockElsewhere(SubmissionCleaner::LOCK_NAME);

        $response = $this->call('', ['X-Cleanup-Token' => self::TOKEN]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($this->formExists($form->ID));
    }
}
