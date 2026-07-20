<?php

declare(strict_types=1);

// PHPUnit bootstrap: load the Composer autoloader first, then the global
// assertion helper functions. Assertions.php stays out of composer.json's
// autoload-dev 'files' so the helpers load only for the test suite and never
// for the shipped autoloader.

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Support/Assertions.php';
