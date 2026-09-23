<?php

declare(strict_types=1);

$coverageDirectory = getenv('KNOSSOS_PHP_COVERAGE_DIR');
if (is_string($coverageDirectory) && $coverageDirectory !== '' && function_exists('pcov\\start')) {
    \pcov\start();
    register_shutdown_function(static function () use ($coverageDirectory): void {
        // The collector is not what the suite measures: it runs after the
        // last test, on top of everything the run still holds, and at PHP's
        // default 128 MB building the coverage array no longer fit.
        ini_set('memory_limit', '-1');
        \pcov\stop();
        $data = \pcov\collect();
        $path = $coverageDirectory . '/pcov-' . getmypid() . '.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    });
}
