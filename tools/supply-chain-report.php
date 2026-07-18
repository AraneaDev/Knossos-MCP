<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "usage: supply-chain-report.php RUNTIME_ID QUALITY_ID OUTPUT\n");
    exit(2);
}

$root = dirname(__DIR__);
$materials = [];
foreach (['Dockerfile', 'composer.lock', 'package-lock.json', 'workers/php/composer.lock', 'workers/typescript/package-lock.json'] as $path) {
    $materials[] = ['uri' => $path, 'digest' => ['sha256' => hash_file('sha256', $root . '/' . $path)]];
}
$statement = [
    '_type' => 'https://in-toto.io/Statement/v1',
    'subject' => [
        ['name' => 'knossos-mcp:runtime', 'digest' => ['sha256' => str_replace('sha256:', '', $argv[1])]],
        ['name' => 'knossos-mcp:quality', 'digest' => ['sha256' => str_replace('sha256:', '', $argv[2])]],
    ],
    'predicateType' => 'https://slsa.dev/provenance/v1',
    'predicate' => [
        'buildDefinition' => [
            'buildType' => 'https://knossos.local/build/docker-v1',
            'externalParameters' => ['runtime_target' => 'runtime', 'quality_target' => 'quality'],
            'resolvedDependencies' => $materials,
        ],
        'runDetails' => [
            'builder' => ['id' => 'https://knossos.local/pinned-quality-container'],
            'metadata' => ['invocationId' => hash('sha256', $argv[1] . "\n" . $argv[2])],
        ],
    ],
];
file_put_contents($argv[3], json_encode($statement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
