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

final class ContributionDecoder
{
    private function __construct() {}

    /** @param array<string, mixed> $data */
    public static function decode(array $data): ScanContribution
    {
        try {
            $owner = self::string($data, 'owner_key');
            $nodes = self::list($data, 'nodes');
            $edges = self::list($data, 'edges');
            $diagnostics = self::list($data, 'diagnostics');

            return new ScanContribution(
                $owner,
                array_map(self::node(...), $nodes),
                array_map(self::edge(...), $edges),
                array_map(self::diagnostic(...), $diagnostics),
            );
        } catch (WorkerException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', $error->getMessage(), $error);
        }
    }

    /** @param mixed $value */
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

    /** @param mixed $value */
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

    /** @param mixed $value */
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function attributes(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Fact attributes must be an object.');
        }

        return $attributes;
    }

    /** @param mixed $value @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be an object.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $field): string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s must be a non-empty string.', $field));
        }

        return $data[$field];
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
