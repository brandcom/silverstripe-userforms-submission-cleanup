<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use TypeError;

/**
 * Throws a PHP Error (not an Exception) while a chosen submission is being deleted.
 */
class FailingDeleteExtension extends Extension implements TestOnly
{
    /**
     * @var array<int>
     */
    public static array $failFor = [];

    protected function onBeforeDelete(): void
    {
        if (in_array((int) $this->getOwner()->ID, self::$failFor, true)) {
            throw new TypeError('simulated error in an onBeforeDelete hook');
        }
    }
}
