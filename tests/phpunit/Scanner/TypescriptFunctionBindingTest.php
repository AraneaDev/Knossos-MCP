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
            'annotated' => ['const', true],
            'disposer' => ['using', false],
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

    public function testCallsAndReferencesReachTheBindingAndItsBodyIsTheirSource(): void
    {
        $scan = $this->scan();
        $arrow = 'ts:function:src/bindings.ts#exportedArrow';
        $helper = 'ts:function:src/bindings.ts#helper';

        // The body's calls and `new` are the binding's, not the module's.
        self::assertTrue($scan->hasEdge('calls', $arrow, $helper));
        self::assertTrue($scan->hasEdge('constructs', $helper, 'ts:class:src/bindings.ts#Widget'));
        self::assertFalse($scan->hasEdge('calls', 'ts:module:src/bindings.ts', $helper));
        // A call from another file, through an import, resolves to the binding.
        self::assertTrue($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', $arrow));
        // Typed by an annotation or a cast, the call's signature is the type's,
        // not the arrow's; the callee is still the binding.
        self::assertTrue($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:function:src/bindings.ts#typedAs'));
        self::assertTrue($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:function:src/bindings.ts#annotated'));
        // A type sharing the value's name merges with it into one symbol; a
        // call names the value, whichever was declared first.
        self::assertTrue($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:function:src/bindings.ts#parsed'));
        self::assertTrue($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:function:src/bindings.ts#area'));
        self::assertFalse($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:type_alias:src/bindings.ts#parsed'));
        self::assertFalse($scan->hasEdge('calls', 'ts:function:src/user.ts#callIt', 'ts:type_alias:src/bindings.ts#area'));
        self::assertTrue($scan->hasEdge('constructs', 'ts:function:src/user.ts#callIt', 'ts:class:src/bindings.ts#Gadget'));
        // A dynamic import in the body is the function's own value import.
        $load = $scan->edge('imports', 'ts:function:src/bindings.ts#load', 'ts:module:src/handler.ts');
        self::assertNotNull($load);
        self::assertFalse($load->attributes['type_only'] ?? null);
        // Passed as a value, it is referenced.
        self::assertTrue($scan->hasEdge('references', 'ts:module:src/bindings.ts', $helper));
    }

    public function testRolesAndReturnContractsBelongToTheFunctionNode(): void
    {
        $scan = $this->scan();

        // One node, the function, carries the component role.
        $card = $scan->node('src/Card.tsx#Card');
        self::assertNotNull($card);
        self::assertSame('function', $card->kind);
        self::assertSame(['react.component'], $card->attributes['typescript_framework_roles'] ?? null);
        self::assertSame(1, $scan->nodesNamed('src/Card.tsx#Card'));
        // Through `satisfies` too.
        self::assertSame(['react.component'], $scan->node('src/Card.tsx#Wrapped')?->attributes['typescript_framework_roles'] ?? null);
        // `export const GET = async () => ...` in a route file handles the route.
        self::assertNotNull($scan->node('GET /api/items => app/api/items/route.ts#GET'));
        self::assertTrue($scan->hasEdge('routes_to', 'ts:route:GET /api/items => app/api/items/route.ts#GET', 'ts:function:app/api/items/route.ts#GET'));
        // The arrow's declared return type is its contract.
        self::assertTrue($scan->hasEdge('returns', 'ts:function:src/bindings.ts#make', 'ts:interface:src/handler.ts#Handler'));
    }

    public function testABoundCalledOrAppliedMemberIsUsed(): void
    {
        $scan = $this->scan();
        $complete = 'ts:method:src/level.ts#Level::complete';

        // `this.formatTime.bind(this)` and `this.helper.call(this)` hand the
        // method on; neither is a call the checker resolves to it.
        self::assertTrue($scan->hasEdge('references', $complete, 'ts:method:src/level.ts#Level::formatTime'));
        self::assertTrue($scan->hasEdge('references', $complete, 'ts:method:src/level.ts#Level::helper'));
        self::assertTrue($scan->hasEdge('references', 'ts:module:src/level.ts', 'ts:function:src/level.ts#standalone'));
        // On a receiver no type describes, the member's name is what may be
        // reached, not `bind`.
        $untyped = $scan->node('src/level.ts')?->attributes['unresolved_member_calls'] ?? [];
        self::assertContains('formatTime', $untyped);
        self::assertNotContains('bind', $untyped);
    }

    private function scan(): FunctionBindingScan
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/function-bindings';
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/api/items/route.ts', 'src/Card.tsx', 'src/bindings.ts', 'src/handler.ts', 'src/level.ts', 'src/user.ts'],
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
        return $this->edge($kind, $source, $target) !== null;
    }

    public function edge(string $kind, string $source, string $target): ?EdgeFact
    {
        foreach ($this->edges() as $edge) {
            if ($edge->kind === $kind && $edge->sourceReference === $source && $edge->targetReference === $target) {
                return $edge;
            }
        }

        return null;
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
