<?php

namespace Brandcom\UserFormsSubmissionCleanup\Tests\Fixtures;

use SilverStripe\Assets\File;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record that uses a file through a plain has_one, like a page with a download.
 */
class FileHolder extends DataObject implements TestOnly
{
    private static string $table_name = 'UFSC_FileHolder';

    private static array $has_one = [
        'Document' => File::class,
    ];
}
