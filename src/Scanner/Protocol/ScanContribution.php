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
     * @param ?array<string, ?string> $reads the files these facts were derived
     *        from, each a SHA-256 or null for a path probed and not found; null
     *        when the worker does not report it
     * @param ?string $program the program these facts were derived in, as the
     *        worker names it; null when the worker does not report one
     * @param ?string $environment lowercase SHA-256 hex digest of the global
     *        declarations that program held; null when the worker does not report it
     */
    public function __construct(
        public string $ownerKey,
        public array $nodes = [],
        public array $edges = [],
        public array $diagnostics = [],
        public ?string $contentHash = null,
        public ?array $reads = null,
        public ?string $program = null,
        public ?string $environment = null,
    ) {
        if ($ownerKey === '') {
            throw new InvalidArgumentException('Contribution owner key must not be empty.');
        }
        if ($contentHash !== null && preg_match('/\A[0-9a-f]{64}\z/', $contentHash) !== 1) {
            throw new InvalidArgumentException('Contribution content hash must be lowercase SHA-256 hex.');
        }
        if ($program === '') {
            throw new InvalidArgumentException('Contribution program must not be empty.');
        }
        if ($environment !== null && preg_match('/\A[0-9a-f]{64}\z/', $environment) !== 1) {
            throw new InvalidArgumentException('Contribution environment must be lowercase SHA-256 hex.');
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
        // An object even when empty, so a file that read nothing else survives the cache.
        if ($this->reads !== null) {
            $wire['reads'] = (object) $this->reads;
        }
        // Kept in the cached payload, so a reused contribution says which
        // program it came from and what that program declared globally.
        if ($this->program !== null) {
            $wire['program'] = $this->program;
        }
        if ($this->environment !== null) {
            $wire['environment'] = $this->environment;
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
