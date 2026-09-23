<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `const f = () => {}` at module level is a function, written the way much
 * modern TypeScript writes one. It is a node like `function f() {}`: a
 * dead-code candidate, the source of the calls in its body, and the target of
 * the references to it.
 */
#[Group('typescript-scanner')]
final class TypescriptFunctionBindingTest extends KnossosTestCase
{
    public function testModuleLevelFunctionBindingsAreFunctionNodes(): void
    {
        $scan = $this->scan();

        foreach ([
            'exportedArrow' => ['const', true],
            'helper' => ['const', false],
            'reassignable' => ['let', false],
            'legacy' => ['var', false],
            'typedAs' => ['const', true],
            'typedSatisfies' => ['const', true],
            'make' => ['const', true],
            'unusedArrow' => ['const', false],
            'pairFn' => ['const', true],
        ] as $name => [$binding, $exported]) {
            $node = $scan->node('src/bindings.ts#' . $name);
            self::assertNotNull($node, $name);
            self::assertSame('function', $node->kind, $name);
            self::assertSame($name, $node->displayName);
            self::assertSame($binding, $node->attributes['binding'] ?? null, $name);
            self::assertSame($exported, $node->attributes['exported'] ?? null, $name);
        }
        self::assertTrue($scan->hasEdge('contains', 'ts:module:src/bindings.ts', 'ts:function:src/bindings.ts#helper'));
        // Not a function, nested, wrapped, destructured, ambient or unnamed: unchanged.
        foreach (['pairValue', 'inner', 'outer.inner', 'wrapped', 'length', 'ambientFn', 'mapped', 'default'] as $name) {
            self::assertNull($scan->node('src/bindings.ts#' . $name), $name);
        }
    }

    public function testAnExportedObjectBindingIsExported(): void
    {
        $api = $this->scan()->node('src/bindings.ts#api');

        self::assertNotNull($api);
        self::assertSame('variable', $api->kind);
        self::assertTrue($api->attributes['exported'] ?? null);
    }

    private function scan(): FunctionBindingScan
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/function-bindings';
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/api/items/route.ts', 'src/Card.tsx', 'src/bindings.ts', 'src/handler.ts', 'src/user.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        return new FunctionBindingScan($contributions);
    }
}

/** The facts of one scan of the function-bindings fixture. */
final readonly class FunctionBindingScan
{
    /** @param list<ScanContribution> $contributions */
    public function __construct(private array $contributions) {}

    public function node(string $canonical): ?NodeFact
    {
        foreach ($this->nodes() as $node) {
            if ($node->canonicalName === $canonical) {
                return $node;
            }
        }

        return null;
    }

    /** How many nodes carry `canonical`. */
    public function nodesNamed(string $canonical): int
    {
        return count(array_filter($this->nodes(), static fn(NodeFact $node): bool => $node->canonicalName === $canonical));
    }

    public function hasEdge(string $kind, string $source, string $target): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->kind === $kind && $edge->sourceReference === $source && $edge->targetReference === $target) {
                return true;
            }
        }

        return false;
    }

    /** @return list<NodeFact> */
    public function nodes(): array
    {
        return array_merge(...array_map(static fn(ScanContribution $c): array => $c->nodes, $this->contributions));
    }

    /** @return list<EdgeFact> */
    public function edges(): array
    {
        return array_merge(...array_map(static fn(ScanContribution $c): array => $c->edges, $this->contributions));
    }
}
