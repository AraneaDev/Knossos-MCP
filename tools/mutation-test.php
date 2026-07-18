<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configurationPath = $argv[1] ?? $root . '/benchmarks/mutation-score.json';
$reportPath = $argv[2] ?? $root . '/coverage/mutation/report.json';
$configuration = json_decode((string) file_get_contents($configurationPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($configuration) || ($configuration['schema_version'] ?? null) !== 1 || !is_int($configuration['minimum_msi'] ?? null)) {
    fwrite(STDERR, "Invalid mutation score configuration.\n");
    exit(2);
}
$minimum = $configuration['minimum_msi'];
if ($minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "minimum_msi must be between 0 and 100.\n");
    exit(2);
}

$sourcePath = $root . '/src/Scanner/Protocol/RelativePath.php';
$source = (string) file_get_contents($sourcePath);
$mutations = [
    'accept-empty-path' => [["\$path === '' || ", ''], ["\$segment === '' || ", '']],
    'accept-null-byte' => [["str_contains(\$path, \"\\0\") || ", '']],
    'accept-backslash' => [[" || str_contains(\$path, '\\\\')", '']],
    'accept-posix-absolute' => [["str_starts_with(\$path, '/') || ", ''], ["\$segment === '' || ", '']],
    'accept-windows-absolute' => [[" || preg_match('/^[A-Za-z]:\\//', \$path) === 1", '']],
    'accept-empty-segment' => [["\$segment === '' || ", '']],
    'accept-dot-segment' => [["\$segment === '.' || ", '']],
    'accept-parent-segment' => [[" || \$segment === '..'", '']],
];

$temporary = sys_get_temp_dir() . '/knossos-mutation-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) {
    throw new RuntimeException('Unable to create mutation directory.');
}
$results = [];
try {
    foreach ($mutations as $name => $replacements) {
        $mutant = $source;
        foreach ($replacements as [$needle, $replacement]) {
            if (substr_count($mutant, $needle) !== 1) {
                throw new RuntimeException('Mutation target drifted: ' . $name);
            }
            $mutant = str_replace($needle, $replacement, $mutant);
        }
        $mutantPath = $temporary . '/' . $name . '.php';
        file_put_contents($mutantPath, $mutant);
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            '-d', 'auto_prepend_file=' . $mutantPath,
            $root . '/tests/run.php',
            '--group=mutation-critical',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start mutation test.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $killed = $exitCode !== 0;
        $results[] = [
            'name' => $name,
            'status' => $killed ? 'killed' : 'survived',
            'exit_code' => $exitCode,
            'diagnostic' => trim(substr($stderr !== '' ? $stderr : $stdout, 0, 500)),
        ];
        fwrite(STDOUT, sprintf("%s %s\n", $killed ? 'KILLED' : 'SURVIVED', $name));
    }
} finally {
    foreach (glob($temporary . '/*.php') ?: [] as $file) {
        unlink($file);
    }
    rmdir($temporary);
}

$killed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'killed'));
$score = count($results) === 0 ? 0.0 : round($killed * 100 / count($results), 2);
$report = [
    'schema_version' => 1,
    'target' => 'src/Scanner/Protocol/RelativePath.php',
    'minimum_msi' => $minimum,
    'mutation_score' => $score,
    'passed' => $score >= $minimum,
    'killed' => $killed,
    'total' => count($results),
    'results' => $results,
];
$directory = dirname($reportPath);
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create mutation report directory.');
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, sprintf("Mutation score: %.2f%% (%d/%d), minimum %d%%\n", $score, $killed, count($results), $minimum));
exit($score >= $minimum ? 0 : 1);
