<?php

declare(strict_types=1);

/**
 * Docstring coverage gate.
 *
 * Reports the share of named functions and methods carrying a docblock with an
 * actual summary, per area, and fails when an area drops below its floor in
 * maintainability-budgets.json.
 *
 * Two deliberate choices about what is measured:
 *
 * - A docblock counts only when it has prose, not merely annotations. The same
 *   rule the API contract gate uses: `/** @return int *\/` tells a reader nothing
 *   a signature has not already said, so counting it would let the number rise
 *   while the documentation did not.
 * - Tests are excluded. They are 72% of all functions in the tree, so including
 *   them would let test docblocks mask an undocumented `src`, and this repo
 *   documents behaviour in test *names* plus inline rationale by convention.
 *
 * Closures and arrow functions are skipped: they have no name to document and
 * would only pad the denominator. Constructors are skipped too, and covered by a
 * separate type-level metric instead — demanding prose on
 * `__construct(private PDO $pdo) {}` produces ceremony, whereas requiring the
 * *class* to explain why it exists produces documentation. Parameter semantics
 * still belong in a constructor `@param`; that is simply not what this counts.
 */
$root = dirname(__DIR__);
// Shared with tools/api-documentation-check.php: one definition of "documented",
// so the two gates cannot drift apart again.
require __DIR__ . '/lib/docblocks.php';
$reportPath = $root . '/coverage/quality/docstring-coverage.json';

/** Areas measured independently, so a well-documented one cannot mask a bare one. */
const AREAS = [
    'src' => 'src',
    'workers-php' => 'workers/php/src',
    'tools' => 'tools',
];

$budgets = json_decode((string) file_get_contents($root . '/maintainability-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
$floors = $budgets['min_docstring_coverage'] ?? [];

$areas = [];
$undocumented = [];
$undocumentedTypes = [];
foreach (AREAS as $label => $relative) {
    $directory = $root . '/' . $relative;
    if (!is_dir($directory)) {
        continue;
    }
    $documented = 0;
    $total = 0;
    $typeDocumented = 0;
    $typeTotal = 0;
    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (declaredTypes($source) as $type) {
            ++$typeTotal;
            if ($type['documented']) {
                ++$typeDocumented;
                continue;
            }
            $undocumentedTypes[] = sprintf('%s:%d %s', str_replace($root . '/', '', $file->getPathname()), $type['line'], $type['name']);
        }
        foreach (declaredFunctions($source) as $function) {
            // Constructors are covered by the type metric instead; see the header.
            if ($function['name'] === '__construct') {
                continue;
            }
            ++$total;
            if ($function['documented']) {
                ++$documented;
                continue;
            }
            $undocumented[] = sprintf('%s:%d %s', str_replace($root . '/', '', $file->getPathname()), $function['line'], $function['name']);
        }
    }
    $areas[$label] = [
        'documented' => $documented,
        'total' => $total,
        'percent' => $total === 0 ? 100.0 : round($documented / $total * 100, 2),
        'types_documented' => $typeDocumented,
        'types_total' => $typeTotal,
        'types_percent' => $typeTotal === 0 ? 100.0 : round($typeDocumented / $typeTotal * 100, 2),
    ];
}

sort($undocumented, SORT_STRING);
sort($undocumentedTypes, SORT_STRING);
$documentedTotal = array_sum(array_column($areas, 'documented'));
$functionTotal = array_sum(array_column($areas, 'total'));
$report = [
    'schema_version' => 1,
    'summary' => [
        'documented' => $documentedTotal,
        'total' => $functionTotal,
        'percent' => $functionTotal === 0 ? 100.0 : round($documentedTotal / $functionTotal * 100, 2),
    ],
    'types' => [
        'documented' => array_sum(array_column($areas, 'types_documented')),
        'total' => array_sum(array_column($areas, 'types_total')),
    ],
    'areas' => $areas,
    // Listed in full rather than truncated: this is the work queue for raising
    // the floor, and a silently trimmed list would understate it.
    'undocumented' => $undocumented,
    'undocumented_types' => $undocumentedTypes,
];
if (!is_dir(dirname($reportPath)) && !mkdir(dirname($reportPath), 0775, true) && !is_dir(dirname($reportPath))) {
    throw new RuntimeException('Unable to create quality report directory.');
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$failures = [];
foreach ($areas as $label => $area) {
    $floor = $floors[$label] ?? null;
    if (is_numeric($floor) && $area['percent'] < (float) $floor) {
        $failures[] = sprintf(
            'docstring coverage for %s is %.2f%% (%d/%d); floor is %.2f%%. Undocumented symbols are listed in %s.',
            $label,
            $area['percent'],
            $area['documented'],
            $area['total'],
            (float) $floor,
            str_replace($root . '/', '', $reportPath),
        );
    }
}
$typeTotalAll = $report['types']['total'];
$typeDocumentedAll = $report['types']['documented'];
$typePercent = $typeTotalAll === 0 ? 100.0 : round($typeDocumentedAll / $typeTotalAll * 100, 2);
$report['types']['percent'] = $typePercent;
$typeFloor = $budgets['min_type_docblock_coverage'] ?? null;
if (is_numeric($typeFloor) && $typePercent < (float) $typeFloor) {
    $failures[] = sprintf(
        'type docblock coverage is %.2f%% (%d/%d); floor is %.2f%%. A type without a docblock never says why it exists.',
        $typePercent,
        $typeDocumentedAll,
        $typeTotalAll,
        (float) $typeFloor,
    );
}
$overallFloor = $floors['overall'] ?? null;
if (is_numeric($overallFloor) && $report['summary']['percent'] < (float) $overallFloor) {
    $failures[] = sprintf('docstring coverage overall is %.2f%%; floor is %.2f%%.', $report['summary']['percent'], (float) $overallFloor);
}

printf(
    "Docstring coverage: %.2f%% methods (%d/%d), %.2f%% types (%d/%d)%s.\n",
    $report['summary']['percent'],
    $documentedTotal,
    $functionTotal,
    $typePercent,
    $typeDocumentedAll,
    $typeTotalAll,
    $areas === [] ? '' : ' — ' . implode(', ', array_map(
        static fn(string $label, array $a): string => sprintf('%s %.2f%%', $label, $a['percent']),
        array_keys($areas),
        $areas,
    )),
);
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
