<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Store\SnapshotPayload;
use PDO;

/**
 * One snapshot's graph, read with only the columns a comparison of two
 * graphs uses: which component is which (id, language, kind, names, origin,
 * file and line, and the attributes that excuse it from the dead-code count),
 * which edges join them (with the attributes of an import, which say whether
 * it is erased at runtime), their roles, the diagnostics' severities, the
 * boundaries and the files' paths.
 *
 * Its readers are the branch comparison, the quality gate and the trend
 * report ({@see ProjectCatalogQueryService::branchComparison()},
 * {@see ProjectCatalogQueryService::qualityGate()},
 * {@see ProjectCatalogQueryService::architectureTrends()}).
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
    /** The columns kept, per table: everything the comparisons, the gate and the trends read. */
    public const COLUMNS = [
        'files' => ['id', 'relative_path'],
        // `attributes_json`: the dead-code count leaves out what the attributes say is reached another way (an override, a runtime-invoked member, a script, a type).
        // `language`: a component is identified across two graphs by language, kind and name; a name alone is not unique.
        'nodes' => ['id', 'language', 'kind', 'canonical_name', 'display_name', 'origin', 'file_id', 'start_line', 'attributes_json'],
        // `attributes_json` only for the kinds {@see ErasedTypeEdge} reads, null for every other edge: a cycle over a type-only import is not one.
        'edges' => ['kind', 'source_id', 'target_id', 'attributes_json'],
        'classifications' => ['node_id', 'role'],
        'diagnostics' => ['severity'],
        // Counted by the trend report.
        'boundaries' => ['id'],
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
            $select = array_map(static fn(string $column): string => $table === 'edges' && $column === 'attributes_json'
                ? sprintf("CASE WHEN kind IN ('%s') THEN attributes_json END AS attributes_json", implode("', '", ErasedTypeEdge::KINDS))
                : $column, $columns);
            $statement = $this->pdo->prepare(sprintf('SELECT %s FROM %s WHERE project_id = :project ORDER BY +id LIMIT %d', implode(', ', $select), $table, self::ROW_LIMIT + 1));
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
     * A compressed payload is decoded and inflated a few kilobytes at a time
     * and its rows are taken off the front as they arrive, each decoded on its
     * own and cut to its columns: neither the payload's JSON, nor its binary
     * form, nor its decoded arrays are ever held whole, and no step's output
     * depends on how well the payload compresses. The stored base64 string
     * itself is held, at its compressed size: PDO's SQLite driver on PHP 8.3
     * has no incremental blob reads (a PARAM_LOB column is the whole value
     * behind a stream), so there is nothing smaller to read it as. A payload this reading does not recognise (plain JSON from
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

    /**
     * How much stored payload (base64 text, a multiple of 4) is decoded and
     * inflated in one step: 4,096 characters, 3,072 compressed bytes.
     *
     * Small on purpose. What one step inflates to is its input times the
     * compression ratio, and zlib's ratio reaches about 1,032:1; a megabyte
     * per step let a payload of repeated attributes inflate whole in one call
     * (41 MB at once for 20,001 identical 2 KB attributes). At 3 KB a step
     * produces at most about 3 MB, whatever the payload holds.
     */
    private const SLICE = 4096;

    /** The longest unfinished text kept between steps: no row is longer, so a buffer past it is not a payload this reads. */
    private const MAX_BUFFER = 16 << 20;

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
        $inflate = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            return null;
        }
        $facts = array_fill_keys(array_keys(self::COLUMNS), []);
        $state = ['phase' => 'head', 'table' => null, 'buffer' => ''];
        $length = strlen($storedPayload);
        for ($at = strlen(self::PREFIX); $at < $length; $at += self::SLICE) {
            $compressed = base64_decode(substr($storedPayload, $at, self::SLICE), true);
            $chunk = $compressed === false ? false : @inflate_add($inflate, $compressed, $at + self::SLICE >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
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
                        $facts[$state['table']][] = self::kept($state['table'], $row, $columns);
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
        return strlen($state['buffer']) < self::MAX_BUFFER;
    }

    /**
     * One archived row cut to the columns kept, with an edge's attributes
     * dropped unless its kind is one {@see ErasedTypeEdge} reads, exactly as
     * the active graph's query reads them.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private static function kept(string $table, array $row, array $columns): array
    {
        $row = array_intersect_key($row, array_flip($columns));
        if ($table === 'edges' && array_key_exists('attributes_json', $row) && !in_array($row['kind'] ?? null, ErasedTypeEdge::KINDS, true)) {
            $row['attributes_json'] = null;
        }

        return $row;
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
            $facts[$table] = array_map(static fn(array $row): array => self::kept($table, $row, $columns), $tables[$table] ?? []);
        }

        return $facts;
    }
}
