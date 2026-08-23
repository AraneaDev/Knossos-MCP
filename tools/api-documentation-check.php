<?php

declare(strict_types=1);

$root = dirname(__DIR__);
// Shared with tools/docstring-report.php on purpose: both gates answer "is this
// documented", and they used to disagree.
require __DIR__ . '/lib/docblocks.php';
$failures = [];
$checked = [];
// Recursive, not glob('/src/*/*.php'): that pattern matches exactly one
// directory deep, so interfaces nested deeper (Mcp/Protocol/ProtocolProfile,
// Scanner/Worker/*Interface) were silently skipped while the gate still
// reported a contract count that read as complete.
$sourceFiles = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)),
    '/\\.php$/',
);
foreach ($sourceFiles as $file) {
    $path = $file->getPathname();
    $source = (string) file_get_contents($path);
    // Tokenised, so the interface is found by declaration rather than by the word
    // appearing in prose ("every interface and enum"), which used to pull a plain
    // class into the gate and name its contracts after the following word.
    $interface = null;
    foreach (declaredTypes($source) as $type) {
        if ($type['kind'] === 'interface') {
            $interface = $type['name'];
            break;
        }
    }
    if ($interface === null) {
        continue;
    }
    foreach (declaredFunctions($source) as $function) {
        if ($function['visibility'] !== 'public') {
            continue;
        }
        $method = $function['name'];
        // documented is computed from the docblock *immediately* preceding the
        // declaration. The previous regex could start at an earlier docblock and
        // swallow everything between, so an annotation-only method borrowed the
        // interface's class summary and passed.
        if (!$function['documented']) {
            $failures[] = $interface . '::' . $method . ' requires a descriptive docblock summary';
        }
        $checked[] = 'php:' . $interface . '::' . $method;
    }
}

$javascript = (string) file_get_contents($root . '/workers/typescript/src/scanner.js');
// `(?:(?!\*\/)[\s\S])*?` keeps each capture inside ONE docblock, for the same
// reason the PHP branch above stopped at the immediately preceding one: a bare
// `.*?` starts at the first `/**` in the file, so an undocumented declaration
// would borrow the class summary and pass.
// `scan(` is a method name, not a file-unique declaration, so its matcher runs
// against the TypeScriptScanner class body alone. Searched over the whole file
// it matched a documented `scan()` in any other class, and satisfied both
// TypeScriptScanner.scan checks while the real method was missing or carried no
// summary — the gate reporting a contract it had not looked at. The body runs
// from the declaration to the first top-level `}`, which is where the
// repository's formatter puts the end of a top-level class; an empty string
// when the class is gone entirely, so that fails the checks rather than
// skipping them.
$scannerClass = preg_match('/^export class TypeScriptScanner\b[^\n]*\{\n([\s\S]*?)\n\}$/m', $javascript, $classBody) === 1
    ? $classBody[1]
    : '';
$scanPattern = '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*scan\s*\(/';
$configPattern = '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*export function discoverConfigFiles\s*\(/';
foreach ([
    'TypeScriptScanner' => [$javascript, '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*export class TypeScriptScanner/'],
    'TypeScriptScanner.scan' => [$scannerClass, $scanPattern],
    'discoverConfigFiles' => [$javascript, $configPattern],
] as $symbol => [$subject, $pattern]) {
    if (preg_match($pattern, $subject, $documentation) !== 1 || !hasSummary($documentation[1])) {
        $failures[] = $symbol . ' requires a descriptive JSDoc summary';
    }
    $checked[] = 'javascript:' . $symbol;
}
foreach ([
    'TypeScriptScanner.scan' => [$scannerClass, $scanPattern],
    'discoverConfigFiles' => [$javascript, $configPattern],
] as $symbol => [$subject, $pattern]) {
    if (preg_match($pattern, $subject, $documentation) !== 1 || !str_contains($documentation[1], '@param') || !str_contains($documentation[1], '@returns')) {
        $failures[] = $symbol . ' requires JSDoc parameter and return contracts';
    }
}

$python = (string) file_get_contents($root . '/workers/python/bin/worker.py');
foreach (['PythonAstFactCollector' => 'class', 'scan' => 'def', 'handle' => 'def'] as $symbol => $kind) {
    $pattern = '/^' . $kind . '\s+' . preg_quote($symbol, '/') . '\b[^\n]*:\n\s+"""[^"\n]+"""/m';
    if (preg_match($pattern, $python) !== 1) {
        $failures[] = $symbol . ' requires an immediate Python docstring summary';
    }
    $checked[] = 'python:' . $symbol;
}

// The wire DTOs the module doc calls out ("Serde mirrors of the DTOs under
// src/Scanner/Protocol"). Each is a single `///` line directly above a derive
// attribute directly above `pub struct Name`, so the pattern tolerates exactly
// one attribute line between the doc comment and the declaration.
$rust = (string) file_get_contents($root . '/workers/rust/src/protocol.rs');
foreach (['Manifest', 'Evidence', 'Node', 'Edge', 'Diagnostic', 'Contribution'] as $symbol) {
    $pattern = '/\/\/\/[^\n]+\n#\[[^\n]*]\n\s*pub struct ' . preg_quote($symbol, '/') . '\b/';
    if (preg_match($pattern, $rust) !== 1) {
        $failures[] = $symbol . ' requires an immediate Rust doc comment summary';
    }
    $checked[] = 'rust:' . $symbol;
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
printf("API documentation passed: %d PHP/JavaScript/Python/Rust contracts.\n", count($checked));
/** Whether a docblock says anything beyond annotations. Mirrored in tools/docstring-report.php, deliberately: two gates disagreeing about what counts as documented would make both untrustworthy. */

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
