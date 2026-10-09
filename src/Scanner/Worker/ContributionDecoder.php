<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Throwable;

/**
 * Validates a worker's reply before it becomes graph facts.
 *
 * Workers are untrusted — they run third-party parsers over arbitrary code — so
 * every field, enum, and limit is checked here. This is the boundary that keeps a
 * malformed contribution out of the database.
 */
final class ContributionDecoder
{
    private function __construct() {}

    /**
     * Validate a worker's reply into a contribution, rejecting anything malformed.
     *
     * With `$degradeMalformedFacts`, a reply whose envelope (owner, lists,
     * content hash, reads, program, flags) is sound but one of whose nodes,
     * edges or diagnostics is not becomes the same file with no facts and an
     * error diagnostic saying why, rather than an error that fails every file
     * of the language. The file is the smallest unit that can go: facts name
     * one another by local id (an edge's source, a `contains` edge to a
     * declaration), so dropping one row would leave an edge to a node no
     * scanner emitted, which fails the whole reconcile. Nothing malformed is
     * kept either way; only how much of the rest goes with it changes. Off,
     * any malformed fact rejects the reply, which a cached payload, already
     * decoded once, never has.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data, bool $degradeMalformedFacts = false): ScanContribution
    {
        try {
            $owner = self::string($data, 'owner_key');
            $nodes = self::list($data, 'nodes');
            $edges = self::list($data, 'edges');
            $diagnostics = self::list($data, 'diagnostics');
            $contentHash = self::contentHash($data);
            $reads = array_key_exists('reads', $data) ? ReadsMap::decode($data['reads']) : null;
            $program = array_key_exists('program', $data) ? self::string($data, 'program') : null;
            $environment = array_key_exists('environment', $data) ? self::string($data, 'environment') : null;
            $listed = !array_key_exists('listed', $data) || self::bool($data, 'listed');
            $readsPartial = array_key_exists('reads_partial', $data) && self::bool($data, 'reads_partial');
            try {
                $facts = [
                    array_map(self::node(...), $nodes),
                    array_map(self::edge(...), $edges),
                    array_map(self::diagnostic(...), $diagnostics),
                ];
            } catch (Throwable $error) {
                if (!$degradeMalformedFacts) {
                    throw $error;
                }
                $facts = [[], [], [self::malformedFacts($owner, $error)]];
            }

            return new ScanContribution($owner, ...$facts, ...[$contentHash, $reads, $program, $environment, $listed, $readsPartial]);
        } catch (WorkerException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', $error->getMessage(), $error);
        }
    }

    /**
     * The diagnostic a file keeps in place of facts that did not validate,
     * anchored on the file its owner key names when that is a valid path.
     */
    private static function malformedFacts(string $owner, Throwable $error): Diagnostic
    {
        $marker = strpos($owner, ':file:');
        $subject = $marker === false ? $owner : substr($owner, $marker + strlen(':file:'));
        try {
            $evidence = $marker === false ? null : new Evidence($subject, 1, 1);
        } catch (Throwable) {
            $evidence = null;
        }

        return new Diagnostic(
            'error',
            'WORKER_CONTRIBUTION_INVALID',
            sprintf(
                'Left out of the graph: the scanner reported a malformed fact for %s (%s). '
                . 'Its facts are omitted and the rest of the language is kept.',
                $subject,
                $error->getMessage(),
            ),
            $evidence,
        );
    }

    /**
     * Validate one node fact from untrusted worker output.
     *
     * @param mixed $value
     */
    private static function node(mixed $value): NodeFact
    {
        $data = self::object($value, 'node');

        return new NodeFact(
            self::string($data, 'local_id'),
            self::string($data, 'kind'),
            self::string($data, 'canonical_name'),
            self::string($data, 'display_name'),
            Origin::from(self::string($data, 'origin')),
            Confidence::from(self::string($data, 'confidence')),
            self::evidence($data['evidence'] ?? null),
            self::attributes($data),
        );
    }

    /**
     * Validate one edge fact, including its confidence and origin enums.
     *
     * @param mixed $value
     */
    private static function edge(mixed $value): EdgeFact
    {
        $data = self::object($value, 'edge');

        return new EdgeFact(
            self::string($data, 'kind'),
            self::string($data, 'source'),
            self::string($data, 'target'),
            Origin::from(self::string($data, 'origin')),
            Confidence::from(self::string($data, 'confidence')),
            self::evidence($data['evidence'] ?? null),
            self::attributes($data),
        );
    }

    /**
     * Validate one diagnostic entry.
     *
     * @param mixed $value
     */
    private static function diagnostic(mixed $value): Diagnostic
    {
        $data = self::object($value, 'diagnostic');

        return new Diagnostic(
            self::string($data, 'severity'),
            self::string($data, 'code'),
            self::string($data, 'message'),
            isset($data['evidence']) ? self::evidence($data['evidence']) : null,
        );
    }
    /** Validate an evidence block, requiring a project-relative path. */

    private static function evidence(mixed $value): Evidence
    {
        $data = self::object($value, 'evidence');
        $start = $data['start_line'] ?? null;
        $end = $data['end_line'] ?? null;
        if (!is_int($start) || !is_int($end)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Evidence lines must be integers.');
        }

        return new Evidence(self::string($data, 'path'), $start, $end);
    }

    /**
     * Validate an attributes object, rejecting a scalar where a map is required.
     *
     * @param array<string, mixed> $data @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Fact attributes must be an object.');
        }

        return $attributes;
    }

    /**
     * A required object field from untrusted input.
     *
     * @param mixed $value @return array<string, mixed>
     */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be an object.', $field));
        }

        return $value;
    }

    /**
     * A required string field from untrusted input.
     *
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $field): string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be a non-empty string.', $field));
        }

        return $data[$field];
    }

    /**
     * A field that is present must hold a boolean.
     *
     * @param array<string, mixed> $data
     */
    private static function bool(array $data, string $field): bool
    {
        if (!is_bool($data[$field] ?? null)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be a boolean.', $field));
        }

        return $data[$field];
    }

    /**
     * The optional hash of the bytes the worker parsed. Absent is allowed; a key
     * that is present must hold a string, and the DTO checks its shape.
     *
     * @param array<string, mixed> $data
     */
    private static function contentHash(array $data): ?string
    {
        if (!array_key_exists('content_hash', $data)) {
            return null;
        }
        if (!is_string($data['content_hash'])) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'content_hash must be a string.');
        }

        return $data['content_hash'];
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private static function list(array $data, string $field): array
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || !array_is_list($data[$field])) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be a list.', $field));
        }

        return $data[$field];
    }
}
