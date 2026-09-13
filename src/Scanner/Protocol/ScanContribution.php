<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Everything one worker produced for one unit of work.
 *
 * Facts are returned as a batch rather than streamed so a contribution either
 * applies whole or not at all, which is what lets reconciliation replace one
 * scanner's facts without disturbing another's.
 */
final readonly class ScanContribution implements JsonSerializable
{
    /**
     * @param list<NodeFact> $nodes
     * @param list<EdgeFact> $edges
     * @param list<Diagnostic> $diagnostics
     * @param ?string $contentHash lowercase SHA-256 hex of the raw bytes these
     *        facts were parsed from; null when the worker does not report it or
     *        never read the file
     */
    public function __construct(
        public string $ownerKey,
        public array $nodes = [],
        public array $edges = [],
        public array $diagnostics = [],
        public ?string $contentHash = null,
    ) {
        if ($ownerKey === '') {
            throw new InvalidArgumentException('Contribution owner key must not be empty.');
        }
        if ($contentHash !== null && preg_match('/\A[0-9a-f]{64}\z/', $contentHash) !== 1) {
            throw new InvalidArgumentException('Contribution content hash must be lowercase SHA-256 hex.');
        }

        self::assertInstances($nodes, NodeFact::class, 'nodes');
        self::assertInstances($edges, EdgeFact::class, 'edges');
        self::assertInstances($diagnostics, Diagnostic::class, 'diagnostics');
    }

    /**
     * The wire shape of the contribution.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $wire = [
            'owner_key' => $this->ownerKey,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'diagnostics' => $this->diagnostics,
        ];
        // Only when set, so every contribution from a worker that does not report
        // it, and every payload already in the cache, keeps its exact bytes.
        if ($this->contentHash !== null) {
            $wire['content_hash'] = $this->contentHash;
        }

        return $wire;
    }

    /**
     * Reject a list containing anything but the expected fact type.
     *
     * @param list<mixed> $values @param class-string $expected
     */
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
