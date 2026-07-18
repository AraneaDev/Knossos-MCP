<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ScanContribution implements JsonSerializable
{
    /**
     * @param list<NodeFact> $nodes
     * @param list<EdgeFact> $edges
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public string $ownerKey,
        public array $nodes = [],
        public array $edges = [],
        public array $diagnostics = [],
    ) {
        if ($ownerKey === '') {
            throw new InvalidArgumentException('Contribution owner key must not be empty.');
        }

        self::assertInstances($nodes, NodeFact::class, 'nodes');
        self::assertInstances($edges, EdgeFact::class, 'edges');
        self::assertInstances($diagnostics, Diagnostic::class, 'diagnostics');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'owner_key' => $this->ownerKey,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @param list<mixed> $values @param class-string $expected */
    private static function assertInstances(array $values, string $expected, string $field): void
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Contribution field "%s" must be a list.', $field));
        }

        foreach ($values as $value) {
            if (!$value instanceof $expected) {
                throw new InvalidArgumentException(sprintf('Contribution field "%s" contains an invalid value.', $field));
            }
        }
    }
}
