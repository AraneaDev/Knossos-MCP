<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checked = [];
foreach (glob($root . '/src/*/*.php') ?: [] as $path) {
    $source = (string) file_get_contents($path);
    if (!str_contains($source, 'interface ')) {
        continue;
    }
    preg_match('/interface\s+(\w+)/', $source, $interfaceMatch);
    $interface = $interfaceMatch[1] ?? basename($path, '.php');
    preg_match_all('/public function\s+(\w+)\s*\(/', $source, $methods);
    foreach ($methods[1] as $method) {
        $pattern = '/\/\*\*(.*?)\*\/\s*public function\s+' . preg_quote($method, '/') . '\s*\(/s';
        if (preg_match($pattern, $source, $documentation) !== 1 || !hasSummary($documentation[1])) {
            $failures[] = $interface . '::' . $method . ' requires a descriptive docblock summary';
        }
        $checked[] = 'php:' . $interface . '::' . $method;
    }
}

$javascript = (string) file_get_contents($root . '/workers/typescript/src/scanner.js');
foreach ([
    'TypeScriptScanner' => '/\/\*\*(.*?)\*\/\s*export class TypeScriptScanner/s',
    'TypeScriptScanner.discover' => '/\/\*\*(.*?)\*\/\s*discover\s*\(/s',
    'TypeScriptScanner.scan' => '/\/\*\*(.*?)\*\/\s*scan\s*\(/s',
] as $symbol => $pattern) {
    if (preg_match($pattern, $javascript, $documentation) !== 1 || !hasSummary($documentation[1])) {
        $failures[] = $symbol . ' requires a descriptive JSDoc summary';
    }
    $checked[] = 'javascript:' . $symbol;
}
foreach (['TypeScriptScanner.discover', 'TypeScriptScanner.scan'] as $symbol) {
    $method = substr($symbol, strrpos($symbol, '.') + 1);
    if (preg_match('/\/\*\*(.*?)\*\/\s*' . preg_quote($method, '/') . '\s*\(/s', $javascript, $documentation) !== 1 || !str_contains($documentation[1], '@param') || !str_contains($documentation[1], '@returns')) {
        $failures[] = $symbol . ' requires JSDoc parameter and return contracts';
    }
}

$python = (string) file_get_contents($root . '/workers/python/bin/worker.py');
foreach (['PythonAstFactCollector' => 'class', 'scan' => 'def', 'discover' => 'def', 'handle' => 'def'] as $symbol => $kind) {
    $pattern = '/^' . $kind . '\s+' . preg_quote($symbol, '/') . '\b[^\n]*:\n\s+"""[^"\n]+"""/m';
    if (preg_match($pattern, $python) !== 1) {
        $failures[] = $symbol . ' requires an immediate Python docstring summary';
    }
    $checked[] = 'python:' . $symbol;
}

$report = ['schema_version' => 1, 'documented_contracts' => count($checked) - count($failures), 'checked_contracts' => count($checked), 'failures' => $failures, 'contracts' => $checked];
$reportDirectory = $root . '/coverage/quality';
if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0775, true) && !is_dir($reportDirectory)) {
    throw new RuntimeException('Unable to create API documentation report directory.');
}
file_put_contents($reportDirectory . '/api-documentation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
printf("API documentation passed: %d PHP/JavaScript/Python contracts.\n", count($checked));

function hasSummary(string $documentation): bool
{
    foreach (preg_split('/\R/', $documentation) ?: [] as $line) {
        $line = trim($line, " \t*");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return true;
        }
    }
    return false;
}
