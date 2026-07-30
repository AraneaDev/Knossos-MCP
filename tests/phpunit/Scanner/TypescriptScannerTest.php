<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class TypescriptScannerTest extends KnossosTestCase
{
    /**
     * Discovery classifies an extensionless script by its shebang and routes it
     * here, so the worker has to accept one. TypeScript itself silently drops a
     * root file whose name carries no recognised extension, so the script is
     * offered to the program under a synthetic name — which must never surface:
     * every node, edge, and owner key has to name the file that exists on disk.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerScansAnExtensionlessShebangScriptUnderItsRealPath(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-shebang-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0o755, true);
        mkdir($root . '/src', 0o755, true);
        file_put_contents($root . '/package.json', '{"name":"shebang-fixture"}');
        file_put_contents($root . '/src/helper.js', "export function helper() {\n    return 1;\n}\n");
        file_put_contents(
            $root . '/bin/cli',
            "#!/usr/bin/env node\nimport { helper } from '../src/helper.js';\nexport function run() {\n    return helper();\n}\n",
        );
        // Not JavaScript at all: a shebang naming another interpreter, and a
        // path that merely contains one, must both still be refused.
        file_put_contents($root . '/bin/other', "#!/bin/sh\necho hi\n");

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['bin/cli', 'bin/other'],
            ]));
            $client->shutdown();
        } finally {
            foreach (['bin/cli', 'bin/other', 'src/helper.js', 'package.json'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/bin');
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[str_replace('knossos.typescript:file:', '', $contribution->ownerKey)] = $contribution;
        }
        // Rejections are emitted before the program is built, so sort rather
        // than pin an order the protocol does not promise.
        $owners = array_keys($byOwner);
        sort($owners, SORT_STRING);
        assertSame(['bin/cli', 'bin/other'], $owners);

        $names = array_map(fn(NodeFact $node): string => $node->canonicalName, $byOwner['bin/cli']->nodes);
        assertSame(true, count($names) > 0);
        // The synthetic name must not leak into the graph.
        assertSame([], array_values(array_filter($names, fn(string $n): bool => str_contains($n, 'knossos-shebang'))));
        assertSame(true, count(array_filter($names, fn(string $n): bool => str_contains($n, 'run'))) > 0);
        assertSame([], $byOwner['bin/cli']->diagnostics);

        assertSame([], $byOwner['bin/other']->nodes);
        assertSame(
            ['TS_UNSCANNABLE_FILE'],
            array_map(fn(Diagnostic $d): string => $d->code, $byOwner['bin/other']->diagnostics),
        );
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerDiscoversConfigsAndExtractsCrossProjectArchitecture(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        assertSame('knossos.typescript', $client->initialize()->id);
        $discovery = $client->discover(['root' => $root]);
        assertSame([
            'packages/app/tsconfig.json',
            'packages/shared/tsconfig.json',
            'tsconfig.base.json',
            'tsconfig.json',
        ], $discovery['config_files']);
        assertSame(3, count($discovery['package_files']));

        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $this->typescriptFixtureFiles(),
        ]));
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
        $serviceNames = array_map(fn(NodeFact $node): string => $node->canonicalName, $service->nodes);
        assertArrayContains('packages/app/src/service.ts#PaymentService', $serviceNames);
        assertSame(1, count(array_filter(
            $serviceNames,
            fn(string $name): bool => $name === 'packages/app/src/service.ts#PaymentService::format',
        )));

        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $service->edges,
        );
        assertArrayContains([
            'implements',
            'ts:class:packages/app/src/service.ts#PaymentService',
            'ts:interface:packages/shared/src/contracts.ts#Payable',
        ], $edgeTuples);
        assertArrayContains([
            'injects',
            'ts:class:packages/app/src/service.ts#PaymentService',
            'ts:class:packages/shared/src/contracts.ts#UserRepository',
        ], $edgeTuples);
        assertArrayContains([
            'constructs',
            'ts:method:packages/app/src/service.ts#PaymentService::pay',
            'ts:class:packages/shared/src/contracts.ts#Invoice',
        ], $edgeTuples);
        assertArrayContains([
            'calls',
            'ts:method:packages/app/src/service.ts#PaymentService::pay',
            'ts:method:packages/shared/src/contracts.ts#UserRepository::save',
        ], $edgeTuples);

        $sharedImports = array_values(array_filter(
            $service->edges,
            fn(EdgeFact $edge): bool => $edge->kind === 'imports'
                && $edge->targetReference === 'ts:module:packages/shared/src/contracts.ts',
        ));
        assertSame(1, count($sharedImports));
        assertSame([false, true], $sharedImports[0]->attributes['type_only_variants']);

        $shared = $byOwner['knossos.typescript:file:packages/shared/src/contracts.ts'];
        assertSame(1, count(array_filter(
            $shared->nodes,
            fn(NodeFact $node): bool => $node->canonicalName === 'packages/shared/src/contracts.ts#Payable',
        )));
        assertSame(false, file_exists($root . '/packages/app/src/EXECUTED'));
        $client->shutdown();
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerCapturesEsmCommonjsTsxExternalAndCompilerFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $this->typescriptFixtureFiles(),
        ]));
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
        assertSame(1, count(array_filter(
            $service->nodes,
            fn(NodeFact $node): bool => $node->kind === 'package' && $node->canonicalName === 'rxjs',
        )));
        assertContains('TS2307', implode(' ', array_map(
            fn(Diagnostic $diagnostic): string => $diagnostic->code,
            $service->diagnostics,
        )));

        $index = $byOwner['knossos.typescript:file:packages/app/src/index.ts'];
        assertContains('re_exports', implode(' ', array_map(fn(EdgeFact $edge): string => $edge->kind, $index->edges)));
        assertSame(true, (bool) array_values(array_filter(
            $index->edges,
            fn(EdgeFact $edge): bool => ($edge->attributes['dynamic'] ?? false) === true,
        ))[0]->attributes['dynamic']);

        $legacy = $byOwner['knossos.typescript:file:packages/app/src/legacy.cjs'];
        assertSame(true, (bool) array_values(array_filter(
            $legacy->edges,
            fn(EdgeFact $edge): bool => ($edge->attributes['commonjs'] ?? false) === true,
        ))[0]->attributes['commonjs']);

        $view = $byOwner['knossos.typescript:file:packages/app/src/view.tsx'];
        assertArrayContains('packages/app/src/view.tsx#CheckoutView', array_map(
            fn(NodeFact $node): string => $node->canonicalName,
            $view->nodes,
        ));

        $invalid = $byOwner['knossos.typescript:file:packages/app/src/invalid.ts'];
        assertContains('TS2322', implode(' ', array_map(
            fn(Diagnostic $diagnostic): string => $diagnostic->code,
            $invalid->diagnostics,
        )));
        $client->shutdown();
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerOutputIsDeterministicBoundedAndPathSafe(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        $request = ['root' => $root, 'files' => ['packages/app/src/service.ts']];
        $first = iterator_to_array($client->scan($request));
        $second = iterator_to_array($client->scan($request));
        assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));

        $error = captureThrows(
            fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../package.json']])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);

        // A file over the byte cap is well-formed, so it costs only itself: the
        // request succeeds and the file arrives as a diagnostic-only contribution.
        $limited = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($limited->scan([
            'root' => $root,
            'files' => ['packages/app/src/service.ts'],
            'limits' => ['max_file_bytes' => 1],
        ]));
        assertSame(1, count($contributions));
        assertSame([], $contributions[0]->nodes);
        assertSame('TS_UNSCANNABLE_FILE', $contributions[0]->diagnostics[0]->code);
    }
}
