<?php

declare(strict_types=1);

// PHPUnit bootstrap: load the Composer autoloader first, then the global
// assertion helper functions. Assertions.php stays out of composer.json's
// autoload-dev 'files' so the helpers load only for the test suite and never
// for the shipped autoloader.

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Support/Assertions.php';

// The CLI falls back to ~/.knossos/knossos.sqlite when it exists, which on a
// developer machine is the real graph. Every test, and every process a test
// starts, gets a home of its own instead.
$home = sys_get_temp_dir() . '/knossos-test-home-' . bin2hex(random_bytes(6));
mkdir($home, 0700);
putenv('HOME=' . $home);
$_ENV['HOME'] = $_SERVER['HOME'] = $home;
register_shutdown_function(static function () use ($home): void {
    exec('rm -rf ' . escapeshellarg($home));
});
