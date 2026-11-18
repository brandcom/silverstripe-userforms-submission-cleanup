<?php

// Works both from a project (module in vendor/) and from the module root with its own vendor/.
$candidates = [
    __DIR__ . '/../../../silverstripe/framework/tests/bootstrap.php',
    __DIR__ . '/../vendor/silverstripe/framework/tests/bootstrap.php',
];
foreach ($candidates as $bootstrap) {
    if (file_exists($bootstrap)) {
        require $bootstrap;
        break;
    }
}

// PHPUnit loads test files before Silverstripe's class manifest is booted, and a dependency's
// autoload-dev is not registered in a project, so the shared base class is loaded here.
require_once __DIR__ . '/CleanupTestCase.php';
foreach (glob(__DIR__ . '/Fixtures/*.php') as $fixture) {
    require_once $fixture;
}
