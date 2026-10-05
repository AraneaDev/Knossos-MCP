<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Store\SnapshotPayload;
use PDO;

/**
 * One snapshot's graph, read with only the columns a comparison of two
 * graphs uses: which component is which (id, kind, names, origin, file and
 * line, and the attributes that excuse it from the dead-code count), which
 * impact edges join them, their roles, the diagnostics' severities and the
 * files' paths.
 *
 * A stored row carries far more (owners, hashes, the scan it came from),
 * and a snapshot holds tens of thousands of rows: reading every column of
 * two whole graphs cost a branch comparison about 425 MB. The
 * active graph is read with a column list; an archived one is inflated and
 * read a row at a time, so its JSON and its decoded rows are never held
 * whole.
 */
final readonly class SnapshotGraphReader
{
    /** The columns kept, per table: everything {@see ProjectCatalogQueryService::branchComparison()} reads. */
    public const COLUMNS = [
        'files' => ['id', 'relative_path'],
        // `attributes_json`: the dead-code count leaves out what the attributes say is reached another way (an override, a runtime-invoked member, a script, a type).
        'nodes' => ['id', 'kind', 'canonical_name', 'display_name', 'origin', 'file_id', 'start_line', 'attributes_json'],
        'edges' => ['kind', 'source_id', 'target_id'],
        'classifications' => ['node_id', 'role'],
        'diagnostics' => ['severity'],
    ];

    /** The most rows one table of the active graph may hold, as the snapshot diff bounds it. */
    private const ROW_LIMIT = 200_000;

    /** Reads through the project's database connection. */
    public function __construct(private PDO $pdo) {}

    /**
     * The active graph's rows, ordered by id as the snapshot diff and the archive order them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function active(string $projectId, string $scanId): array
    {
        $facts = [];
        foreach (self::COLUMNS as $table => $columns) {
            // `+id`: ordered by the id index, SQLite would walk every project's rows; this way it finds the project's and sorts them.
            $statement = $this->pdo->prepare(sprintf('SELECT %s FROM %s WHERE project_id = :project ORDER BY +id LIMIT %d', implode(', ', $columns), $table, self::ROW_LIMIT + 1));
            $statement->execute(['project' => $projectId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > self::ROW_LIMIT) {
                throw new InvalidArgumentException(sprintf('Active snapshot %s exceeds the %d-row %s diff limit.', $scanId, self::ROW_LIMIT, $table));
            }
            $facts[$table] = $rows;
        }

        return $facts;
    }

    /**
     * An archived snapshot's rows, in the payload's order.
     *
     * A compressed payload is inflated a slice at a time and its rows are
     * taken off the front as they arrive, each decoded on its own and cut to
     * its columns: neither the payload's JSON nor its decoded arrays are ever
     * held whole. A payload this reading does not recognise (plain JSON from
     * an earlier version, laid out another way) is decoded whole instead,
     * which gives the same rows.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function archived(string $storedPayload, string $scanId): array
    {
        $facts = str_starts_with($storedPayload, self::PREFIX) ? $this->streamed($storedPayload) : null;

        return $facts ?? $this->decodedWhole($storedPayload, $scanId);
    }

    /** Marks a compressed payload, as {@see SnapshotPayload} writes it. */
    private const PREFIX = 'gzip64:';

    /** How much compressed payload is inflated at a time. */
    private const SLICE = 1 << 20;

    /** The next table's name and the bracket opening its rows. */
    private const TABLE = '/\G\s*,?\s*"([a-z_]+)"\s*:\s*\[/';

    /** One flat row: an object of scalars and strings, nothing nested. */
    private const ROW = '/\G\s*,?\s*(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    /**
     * The rows of a compressed payload, read as it inflates; null when it is
     * not laid out as the archive writes it (or cannot be inflated), so the
     * caller decodes it whole.
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function streamed(string $storedPayload): ?array
    {
        $compressed = base64_decode(substr($storedPayload, strlen(self::PREFIX)), true);
        $inflate = $compressed === false ? false : @inflate_init(ZLIB_ENCODING_GZIP);
        if ($compressed === false || $inflate === false) {
            return null;
        }
        $facts = array_fill_keys(array_keys(self::COLUMNS), []);
        $state = ['phase' => 'head', 'table' => null, 'buffer' => ''];
        for ($at = 0; $at < strlen($compressed); $at += self::SLICE) {
            $chunk = @inflate_add($inflate, substr($compressed, $at, self::SLICE), $at + self::SLICE >= strlen($compressed) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false || !$this->take($state, $chunk, $facts)) {
                return null;
            }
        }

        return $state['phase'] === 'done' && trim($state['buffer']) === '' ? $facts : null;
    }

    /**
     * Read what `$chunk` completes: the head, then each table's rows, cut to
     * the columns kept. What is left unfinished stays in the buffer for the
     * next chunk. False when the text is not what the archive writes.
     *
     * @param array{phase: string, table: ?string, buffer: string} $state
     * @param array<string, list<array<string, mixed>>> $facts
     */
    private function take(array &$state, string $chunk, array &$facts): bool
    {
        $buffer = $state['buffer'] . $chunk;
        $at = 0;
        $length = strlen($buffer);
        while ($at < $length) {
            if ($state['phase'] === 'head') {
                if (preg_match('/\G\{"schema":\d+,"facts":\{/', $buffer, $match, 0, $at) !== 1) {
                    return $length < 64;
                }
                $at += strlen($match[0]);
                $state['phase'] = 'tables';
            } elseif ($state['phase'] === 'tables') {
                if (preg_match('/\G\s*\}\s*\}/', $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'done';
                } elseif (preg_match(self::TABLE, $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'rows';
                    $state['table'] = $match[1];
                } else {
                    break;
                }
            } elseif ($state['phase'] === 'rows') {
                if (preg_match('/\G\s*\]/', $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'tables';
                } elseif (preg_match(self::ROW, $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $columns = self::COLUMNS[$state['table']] ?? null;
                    if ($columns !== null) {
                        $row = json_decode($match[1], true, 4);
                        if (!is_array($row)) {
                            return false;
                        }
                        $facts[$state['table']][] = array_intersect_key($row, array_flip($columns));
                    }
                } else {
                    break;
                }
            } else {
                break;
            }
        }
        $state['buffer'] = substr($buffer, $at);

        // A row is never longer than this: a buffer that grew past it without a match is not a payload this reads.
        return strlen($state['buffer']) < 16 * self::SLICE;
    }

    /**
     * The rows of a payload decoded whole, cut to the columns kept.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function decodedWhole(string $storedPayload, string $scanId): array
    {
        $payload = json_decode(SnapshotPayload::decode($storedPayload), true, 512, JSON_THROW_ON_ERROR);
        $tables = is_array($payload) ? ($payload['facts'] ?? null) : null;
        if (!is_array($tables)) {
            throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
        }
        $facts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $keep = array_flip($columns);
            $facts[$table] = array_map(static fn(array $row): array => array_intersect_key($row, $keep), $tables[$table] ?? []);
        }

        return $facts;
    }
}
