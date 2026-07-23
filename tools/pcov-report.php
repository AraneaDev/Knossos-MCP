<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$coverageDirectory = $root . '/coverage/php';
$merged = [];
foreach (glob($coverageDirectory . '/pcov-*.json') ?: [] as $path) {
    $processData = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($processData as $file => $lines) {
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line => $hits) {
            $merged[$file][(int) $line] = max($merged[$file][(int) $line] ?? -1, (int) $hits);
        }
    }
}

$prefixes = [$root . '/src/', $root . '/workers/php/src/', $root . '/bin/http-router.php'];

// pcov only reports files that were actually loaded during the run. A source
// file that no test ever executes never appears in the merged data and would
// silently drop out of the aggregate -- inflating coverage by pretending the
// gap does not exist. Enumerate every file that SHOULD be measured and inject
// any that are missing as fully uncovered (every executable line at 0 hits), so
// an unexecuted file shows as 0% and drags the aggregate/component floors down
// where it belongs.
$approxExecutableLines = static function (string $path): int {
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $count = 0;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (
            $trimmed === '' || $trimmed === '{' || $trimmed === '}' || $trimmed === '<?php'
            || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, 'declare(') || str_starts_with($trimmed, 'namespace ')
            || str_starts_with($trimmed, 'use ')
        ) {
            continue;
        }
        ++$count;
    }

    return max(1, $count);
};
$expectedFiles = [$root . '/bin/http-router.php' => true];
foreach ([$root . '/src', $root . '/workers/php/src'] as $sourceDirectory) {
    if (!is_dir($sourceDirectory)) {
        continue;
    }
    /** @var iterable<\SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $expectedFiles[$fileInfo->getPathname()] = true;
        }
    }
}
foreach (array_keys($expectedFiles) as $expectedFile) {
    if (isset($merged[$expectedFile]) || $expectedFile === $root . '/src/Application.php') {
        continue;
    }
    $merged[$expectedFile] = array_fill(1, $approxExecutableLines($expectedFile), 0);
}
$covered = 0;
$executable = 0;
$files = [];
$components = [];
foreach ($merged as $file => $lines) {
    if ($file === $root . '/src/Application.php') {
        continue;
    }
    if (count(array_filter($prefixes, static fn(string $prefix): bool => str_starts_with($file, $prefix))) === 0) {
        continue;
    }
    $fileCovered = count(array_filter($lines, static fn(int $hits): bool => $hits > 0));
    $fileExecutable = count($lines);
    $covered += $fileCovered;
    $executable += $fileExecutable;
    $files[str_replace($root . '/', '', $file)] = [$fileCovered, $fileExecutable];
    $relative = str_replace($root . '/', '', $file);
    $component = match (true) {
        str_starts_with($relative, 'src/Mcp/'), $relative === 'bin/http-router.php' => 'transport',
        str_starts_with($relative, 'src/Store/') => 'storage',
        str_starts_with($relative, 'src/Discovery/'), str_starts_with($relative, 'src/Configuration/') => 'discovery-config',
        str_starts_with($relative, 'src/Reconciliation/') => 'reconciliation',
        str_starts_with($relative, 'src/Query/'), str_starts_with($relative, 'src/Boundary/'), str_starts_with($relative, 'src/Classification/') => 'query-analysis',
        str_starts_with($relative, 'src/Scan/'), str_starts_with($relative, 'src/Scanner/') => 'scanner-runtime',
        str_starts_with($relative, 'workers/php/src/') => 'php-scanner',
        str_starts_with($relative, 'src/Maintenance/'), str_starts_with($relative, 'src/Runtime/') => 'maintenance-runtime',
        default => 'bundle-git-watch',
    };
    $components[$component][0] = ($components[$component][0] ?? 0) + $fileCovered;
    $components[$component][1] = ($components[$component][1] ?? 0) + $fileExecutable;
}
ksort($files, SORT_STRING);
$percent = $executable === 0 ? 0.0 : 100 * $covered / $executable;
foreach ($files as $file => [$fileCovered, $fileExecutable]) {
    printf("%-70s %6.2f%% (%d/%d)\n", $file, 100 * $fileCovered / max(1, $fileExecutable), $fileCovered, $fileExecutable);
}
printf("PHP aggregate line coverage: %.2f%% (%d/%d)\n", $percent, $covered, $executable);

$budgetPath = $root . '/coverage-budgets.json';
$budgets = is_file($budgetPath) ? json_decode((string) file_get_contents($budgetPath), true, 512, JSON_THROW_ON_ERROR) : [];
$componentSummary = [];
$componentPassed = true;
ksort($components, SORT_STRING);
foreach ($components as $component => [$componentCovered, $componentExecutable]) {
    $componentPercent = 100 * $componentCovered / max(1, $componentExecutable);
    $floor = (float) ($budgets['php']['components'][$component] ?? 0);
    $passed = $componentPercent >= $floor;
    $componentPassed = $componentPassed && $passed;
    printf("PHP component %-24s %6.2f%% (%d/%d), floor %.2f%% %s\n", $component, $componentPercent, $componentCovered, $componentExecutable, $floor, $passed ? 'PASS' : 'FAIL');
    $componentSummary[$component] = ['covered' => $componentCovered, 'valid' => $componentExecutable, 'percent' => round($componentPercent, 2), 'floor' => $floor, 'passed' => $passed];
}
$summary = ['lines' => ['covered' => $covered, 'valid' => $executable, 'percent' => round($percent, 2)], 'components' => $componentSummary, 'files' => $files];
file_put_contents($coverageDirectory . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
exit($percent >= (float) ($budgets['php']['aggregate_lines'] ?? 90.0) && $componentPassed ? 0 : 1);
