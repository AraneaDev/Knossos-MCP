<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The compiler binds `require('./local')` to a module only in JavaScript, so
 * a TypeScript file loading one lazily imported nothing, and the module and
 * everything it declared read as unreferenced.
 */
#[Group('typescript-scanner')]
final class TypescriptRequireTest extends KnossosTestCase
{
    public function testARelativeRequireInTypeScriptImportsTheModule(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-require',
                'files' => ['src/storage.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $imports = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'imports') {
                    $imports[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }

        // A module the project does not hold is no import.
        sort($imports);
        self::assertSame([
            'ts:function:src/storage.ts#createBackend -> ts:module:src/local.ts',
            'ts:function:src/storage.ts#loadBoth -> ts:module:src/both.js',
            'ts:function:src/storage.ts#loadServer -> ts:module:src/lazy/server.ts',
        ], $imports);
    }

    /**
     * `const { LocalStorageBackend } = require('./local') as typeof import('./local')`
     * binds a local name, and `new LocalStorageBackend()` constructs the class
     * the name holds. An enum read only through its members, `TokenType.And`,
     * is used as surely as a function that is called.
     */
    public function testAClassFromADestructuredRequireAndAnEnumReadThroughItsMembersAreUsed(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-require',
                'files' => ['src/lexer.ts', 'src/local.ts', 'src/parser.ts', 'src/storage.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (in_array($edge->kind, ['constructs', 'references'], true)) {
                    $edges[] = $edge->kind . ' ' . $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }

        self::assertContains('constructs ts:function:src/storage.ts#createBackend -> ts:class:src/local.ts#LocalStorageBackend', $edges);
        self::assertContains('references ts:function:src/parser.ts#isAnd -> ts:enum:src/lexer.ts#TokenType', $edges);
        self::assertSame([], array_values(array_filter($edges, static fn(string $edge): bool => str_contains($edge, '#Unused'))));
    }
}
