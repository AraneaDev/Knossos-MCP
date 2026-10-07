<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * The graph's node references, sorted once so a prefix lookup is a binary
 * search instead of a pass over every node.
 *
 * A namespace prefix (`php:class:App\Cards\`) and a directory import
 * (`ts:module:web/`) both name the nodes whose reference starts with a given
 * string. Scanning the whole node map for each such edge made a reconcile
 * quadratic in a project that has many of them.
 */
final readonly class NodeReferenceIndex
{
    /** @var list<string> */
    private array $sorted;

    /** @var array<string, int> reference to its position in the node map */
    private array $position;

    /** @param array<string, string> $nodeMap reference to node id */
    public function __construct(private array $nodeMap)
    {
        $references = array_map('strval', array_keys($nodeMap));
        $this->position = array_flip($references);
        sort($references, SORT_STRING);
        $this->sorted = $references;
    }

    /**
     * Every reference starting with `$prefix`, to its node id, in node map order.
     *
     * @return array<string, string>
     */
    public function withPrefix(string $prefix): array
    {
        $low = 0;
        $high = count($this->sorted);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (strcmp($this->sorted[$middle], $prefix) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        $matches = [];
        for ($index = $low, $count = count($this->sorted); $index < $count && str_starts_with($this->sorted[$index], $prefix); ++$index) {
            $matches[] = $this->sorted[$index];
        }
        usort($matches, fn(string $a, string $b): int => $this->position[$a] <=> $this->position[$b]);
        $found = [];
        foreach ($matches as $reference) {
            $found[$reference] = $this->nodeMap[$reference];
        }

        return $found;
    }
}
