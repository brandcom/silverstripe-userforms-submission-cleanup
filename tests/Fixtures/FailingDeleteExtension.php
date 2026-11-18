<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use TypeError;

/**
 * Throws a PHP Error (not an Exception), or takes its time, while a chosen submission is being
 * deleted.
 */
class FailingDeleteExtension extends Extension implements TestOnly
{
    /**
     * @var array<int>
     */
    public static array $failFor = [];

    /**
     * @var array<int>
     */
    public static array $slowFor = [];

    protected function onBeforeDelete(): void
    {
        $id = (int) $this->getOwner()->ID;
        if (in_array($id, self::$slowFor, true)) {
            usleep(1_100_000);
        }
        if (in_array($id, self::$failFor, true)) {
            throw new TypeError('simulated error in an onBeforeDelete hook');
        }
    }
}
