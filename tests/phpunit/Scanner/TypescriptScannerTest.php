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

        $limited = $this->typescriptWorkerClient();
        $error = captureThrows(
            fn() => iterator_to_array($limited->scan([
                'root' => $root,
                'files' => ['packages/app/src/service.ts'],
                'limits' => ['max_file_bytes' => 1],
            ])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
    }
}
