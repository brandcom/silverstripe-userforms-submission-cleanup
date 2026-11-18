<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures;

use Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner;
use RuntimeException;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;

/**
 * Fails on chosen upload fields, e.g. an asset that cannot be deleted.
 */
class FailingCleaner extends SubmissionCleaner implements TestOnly
{
    /**
     * @var array<int>
     */
    public array $failFor = [];

    public function removeUpload(SubmittedFileField $field): string
    {
        if (in_array((int) $field->ID, $this->failFor, true)) {
            throw new RuntimeException('simulated asset store failure');
        }

        return parent::removeUpload($field);
    }
}
