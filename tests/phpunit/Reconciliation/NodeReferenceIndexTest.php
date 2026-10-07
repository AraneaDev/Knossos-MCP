<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\NodeReferenceIndex;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A prefix lookup over the graph's node references returns what a filter over
 * every reference returns, in the graph's own order.
 */
#[Group('reconciliation')]
final class NodeReferenceIndexTest extends KnossosTestCase
{
    public function testEveryPrefixFindsWhatAPlainFilterFinds(): void
    {
        $nodeMap = [];
        foreach (['php:class:App\\Cards\\Help', 'php:class:App\\Cardsets\\Deck', 'php:class:App\\Cards\\Parts\\Header', 'ts:module:web/a.js', 'php:class:App\\Cards', 'ts:module:web/deep/b.js', 'ts:module:webs/c.js', 'php:class:Ápp\\Ünicode', 'php:class:App\\Cards\\Ace'] as $index => $reference) {
            $nodeMap[$reference] = 'n' . $index;
        }
        $index = new NodeReferenceIndex($nodeMap);

        foreach (['php:class:App\\Cards\\', 'php:class:App\\Cards', 'php:class:', 'ts:module:web/', 'ts:module:', 'php:class:Ápp\\', 'zzz', '', 'a', 'php:class:App\\Cards\\Parts\\Header'] as $prefix) {
            $expected = array_filter($nodeMap, static fn(string $reference): bool => str_starts_with($reference, $prefix), ARRAY_FILTER_USE_KEY);

            assertSame($expected, $index->withPrefix($prefix), $prefix);
        }
    }

    public function testAnEmptyGraphFindsNothing(): void
    {
        assertSame([], (new NodeReferenceIndex([]))->withPrefix('php:class:'));
    }
}
