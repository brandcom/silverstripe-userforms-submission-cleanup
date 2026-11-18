<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use RuntimeException;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;

/**
 * Fails on chosen upload fields (e.g. an asset that cannot be deleted), or on every run.
 */
class FailingCleaner extends SubmissionCleaner implements TestOnly
{
    /**
     * @var array<int>
     */
    public array $failFor = [];

    public bool $failRun = false;

    public function runExclusive(callable $callback): bool
    {
        if ($this->failRun) {
            throw new RuntimeException('simulated database failure');
        }

        return parent::runExclusive($callback);
    }

    public function removeUpload(SubmittedFileField $field): string
    {
        if (in_array((int) $field->ID, $this->failFor, true)) {
            throw new RuntimeException('simulated asset store failure');
        }

        return parent::removeUpload($field);
    }
}
