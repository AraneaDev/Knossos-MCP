<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$reportPath = $root . '/coverage/quality/maintainability.json';
$patterns = [$root . '/src/*.php', $root . '/src/*/*.php', $root . '/src/*/*/*.php', $root . '/workers/php/src/*.php', $root . '/workers/php/src/*/*.php', $root . '/workers/typescript/src/*.js', $root . '/workers/python/bin/*.py'];
$paths = [];
foreach ($patterns as $pattern) {
    foreach (glob($pattern) ?: [] as $path) {
        $paths[$path] = true;
    }
}
ksort($paths, SORT_STRING);
$files = [];
$blocks = [];
$functions = [];
foreach (array_keys($paths) as $path) {
    $relative = str_replace($root . '/', '', $path);
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    $logical = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '//') && !str_starts_with($line, '#') && !str_starts_with($line, '*')));
    $source = implode("\n", $logical);
    $decisions = match ($extension) {
        'php' => preg_match_all('/\b(?:if|elseif|for|foreach|while|case|catch|match)\b|&&|\|\||\?\?/', $source),
        'js' => preg_match_all('/\b(?:if|else if|for|while|case|catch)\b|&&|\|\||\?\?/', $source),
        'py' => preg_match_all('/\b(?:if|elif|for|while|case|except)\b|\band\b|\bor\b/', $source),
        default => 0,
    };
    $dependencyPattern = match ($extension) {
        'php' => '/^use\s+[^;]+;/m',
        'js' => '/^import\s+.+?from\s+["\'][^"\']+["\'];?$/m',
        'py' => '/^(?:from\s+\S+\s+import|import\s+)\s*.+$/m',
        default => '/(?!)^/',
    };
    $dependencies = preg_match_all($dependencyPattern, (string) file_get_contents($path));
    $files[$relative] = ['language' => $extension, 'lines' => count($lines), 'logical_lines' => count($logical), 'decision_points' => $decisions, 'dependency_fanout' => $dependencies];
    if ($extension === 'php') {
        $functions = array_merge($functions, phpFunctionMetrics((string) file_get_contents($path), $relative));
    }
    for ($offset = 0; $offset + 7 < count($logical); ++$offset) {
        $window = array_slice($logical, $offset, 8);
        $normalized = preg_replace('/\s+/', ' ', implode("\n", $window));
        if (!is_string($normalized) || strlen($normalized) < 160) {
            continue;
        }
        $blocks[hash('sha256', $normalized)][] = ['file' => $relative, 'logical_line' => $offset + 1];
    }
}
$duplicates = array_filter($blocks, static function (array $locations): bool {
    return count(array_unique(array_column($locations, 'file'))) > 1;
});
ksort($duplicates, SORT_STRING);
uasort($files, static fn(array $left, array $right): int => [$right['decision_points'], $right['logical_lines']] <=> [$left['decision_points'], $left['logical_lines']]);
usort($functions, static fn(array $left, array $right): int => [$right['complexity'], $right['lines']] <=> [$left['complexity'], $left['lines']]);
$budgets = json_decode((string) file_get_contents($root . '/maintainability-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
$maxPhpComplexity = max(array_column($functions, 'complexity'));
$maxPhpFunctionLines = max(array_column($functions, 'lines'));
$maxDependencyFanout = max(array_column($files, 'dependency_fanout'));
$report = [
    'schema_version' => 1,
    'summary' => [
        'files' => count($files),
        'logical_lines' => array_sum(array_column($files, 'logical_lines')),
        'decision_points' => array_sum(array_column($files, 'decision_points')),
        'cross_file_duplicate_blocks' => count($duplicates),
        'max_php_function_complexity' => $maxPhpComplexity,
        'max_php_function_lines' => $maxPhpFunctionLines,
        'max_dependency_fanout' => $maxDependencyFanout,
    ],
    'highest_complexity_files' => array_slice($files, 0, 20, true),
    'highest_complexity_php_functions' => array_slice($functions, 0, 30),
    'cross_file_duplicate_blocks' => array_slice($duplicates, 0, 50, true),
    'prerequisite_static_gates' => ['PHPStan unused/unreachable analysis', 'ESLint no-unused-vars', 'Ruff F and warn-unreachable through mypy'],
];
if (!is_dir(dirname($reportPath)) && !mkdir(dirname($reportPath), 0775, true) && !is_dir(dirname($reportPath))) {
    throw new RuntimeException('Unable to create quality report directory.');
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$failures = [];
foreach (['cross_file_duplicate_blocks', 'max_php_function_complexity', 'max_php_function_lines', 'max_dependency_fanout'] as $metric) {
    if ($report['summary'][$metric] > $budgets[$metric]) {
        $failures[] = sprintf('%s is %d; budget is %d.', $metric, $report['summary'][$metric], $budgets[$metric]);
    }
}
printf("Maintainability report: %d files, %d logical lines, %d decision points, %d cross-file duplicate blocks; PHP max complexity %d and function lines %d.\n", $report['summary']['files'], $report['summary']['logical_lines'], $report['summary']['decision_points'], $report['summary']['cross_file_duplicate_blocks'], $maxPhpComplexity, $maxPhpFunctionLines);
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

/** @return list<array{symbol: string, file: string, line: int, lines: int, complexity: int}> */
function phpFunctionMetrics(string $source, string $relative): array
{
    $tokens = token_get_all($source);
    $metrics = [];
    $stack = [];
    $pending = null;
    $braceDepth = 0;
    $line = 1;
    $decisions = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH, T_BOOLEAN_AND, T_BOOLEAN_OR, T_COALESCE, T_MATCH];
    foreach ($tokens as $index => $token) {
        $tokenLine = is_array($token) ? $token[2] : $line;
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $name = '{closure}@' . $tokenLine;
            for ($offset = $index + 1; isset($tokens[$offset]); ++$offset) {
                if (is_array($tokens[$offset]) && $tokens[$offset][0] === T_STRING) {
                    $name = $tokens[$offset][1];
                    break;
                }
                if ($tokens[$offset] === '(') {
                    break;
                }
            }
            $pending = ['symbol' => $name, 'file' => $relative, 'line' => $tokenLine, 'complexity' => 1];
        }
        if (is_array($token) && in_array($token[0], $decisions, true) && $stack !== []) {
            ++$stack[array_key_last($stack)]['complexity'];
        }
        if ($token === '{') {
            ++$braceDepth;
            if ($pending !== null) {
                $pending['depth'] = $braceDepth;
                $stack[] = $pending;
                $pending = null;
            }
        } elseif ($token === '}') {
            if ($stack !== [] && $stack[array_key_last($stack)]['depth'] === $braceDepth) {
                $function = array_pop($stack);
                unset($function['depth']);
                $function['lines'] = max(1, $tokenLine - $function['line'] + 1);
                $metrics[] = $function;
            }
            --$braceDepth;
        }
        $line = $tokenLine + (is_string($token) ? substr_count($token, "\n") : substr_count($token[1], "\n"));
    }
    return $metrics;
}
