<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Closure;
use Knossos\Store\SqliteGraphWriter;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Re-saving a row replaces every column a scan owns.
 *
 * A rescan writes most rows again under the same id. Each upsert names the
 * columns it replaces, and until this test a column could be dropped from
 * that list with every test green: the rescan would then keep the old value
 * for good, a stale line number or confidence served as current. The batch
 * writers also skip a row nothing changed in, so a missing term in that
 * comparison would silently skip a real change.
 *
 * Each case saves a row with one set of values, saves it again under the
 * same key with a second set, and reads the row back.
 */
final class SqliteGraphWriterUpsertTest extends KnossosTestCase
{
    /**
     * Every writer that upserts, with the table, the key, and two versions of
     * each replaced column.
     *
     * @return iterable<string, array{string}>
     */
    public static function writers(): iterable
    {
        foreach (['saveNode', 'saveNodes', 'saveEdge', 'saveEdges', 'saveFile', 'saveFiles', 'saveClassifications', 'saveBoundary'] as $writer) {
            yield $writer => [$writer];
        }
    }

    /**
     * The writers that skip a row nothing changed in: the batch writers, and
     * the single boundary save, which compares the same way.
     *
     * @return iterable<string, array{string}>
     */
    public static function comparingWriters(): iterable
    {
        foreach (['saveNodes', 'saveEdges', 'saveFiles', 'saveClassifications', 'saveBoundary'] as $writer) {
            yield $writer => [$writer];
        }
    }

    #[DataProvider('writers')]
    #[Group('store')]
    public function testASecondSaveReplacesEveryColumn(string $writer): void
    {
        [$pdo, $case] = $this->case($writer);

        $case['save']($case['first']);
        $case['save']($case['second']);

        assertSame(self::stored($case['second']), $this->read($pdo, $case), $writer);
    }

    /**
     * A writer that skips a row nothing changed in has to compare every column
     * it replaces: a change to any one of them alone must still land.
     */
    #[DataProvider('comparingWriters')]
    #[Group('store')]
    public function testASaveThatComparesNoticesAChangeInAnyOneColumn(string $writer): void
    {
        [, $probe] = $this->case($writer);
        foreach (array_keys($probe['compared']) as $column) {
            [$pdo, $case] = $this->case($writer);
            $changed = $case['first'];
            $changed[$column] = $case['second'][$column];

            $case['save']($case['first']);
            $case['save']($changed);

            assertSame(self::stored($changed), $this->read($pdo, $case), sprintf('%s: a change to %s alone was not written.', $writer, $column));
        }
    }

    /**
     * A row saved again unchanged is left alone, scan stamp included: the
     * pruner stamps surviving rows itself, and rewriting identical rows was
     * the write amplification the comparison exists to avoid.
     */
    #[DataProvider('comparingWriters')]
    #[Group('store')]
    public function testASaveThatComparesLeavesAnUnchangedRowAlone(string $writer): void
    {
        [$pdo, $case] = $this->case($writer);
        $case['save']($case['first']);

        $case['save'](['last_scan_id' => $case['second']['last_scan_id']] + $case['first']);

        assertSame(self::stored($case['first']), $this->read($pdo, $case));
    }

    /**
     * The batch node writer owns parent_id like every other column, and the
     * facts it writes carry none, so re-saving a node through it clears a
     * parent a single-row save had set, even when nothing else changed.
     */
    #[Group('store')]
    public function testABatchNodeSaveReplacesTheParentToo(): void
    {
        [$pdo, $case] = $this->case('saveNode');
        $case['save']($case['first']);

        (new SqliteGraphWriter(new SqliteStatementCache($pdo)))->saveNodes(
            [['id' => 'node-under-test', 'attributes' => ['a' => 1]] + $case['first']],
            (string) $pdo->query('SELECT project_id FROM nodes WHERE id = \'node-under-test\'')->fetchColumn(),
            $case['first']['last_scan_id'],
        );

        assertSame(null, $pdo->query("SELECT parent_id FROM nodes WHERE id = 'node-under-test'")->fetchColumn());
    }

    /**
     * One writer's fixture: a store with a second scan and file to point at,
     * the two versions of the row, and a save closure taking the columns.
     *
     * @return array{0: PDO, 1: array{table: string, key: array<string, string>, first: array<string, mixed>, second: array<string, mixed>, compared: array<string, true>, save: Closure(array<string, mixed>): void}}
     */
    private function case(string $writer): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->createScan('scan-2', $ids['project'], 'full', 'h2');
        $otherFile = StableId::file($ids['project'], 'src/Other.php');
        $repository->saveFile($otherFile, $ids['project'], 'src/Other.php', 'aa', 1, 1, 'php', '1', $ids['scan']);
        $write = new SqliteGraphWriter(new SqliteStatementCache($pdo));
        $project = $ids['project'];

        $case = match ($writer) {
            'saveNode', 'saveNodes' => [
                'table' => 'nodes',
                'key' => ['id' => 'node-under-test'],
                'first' => ['language' => 'php', 'kind' => 'class', 'canonical_name' => 'App\\A', 'display_name' => 'A', 'parent_id' => $writer === 'saveNode' ? $ids['checkout'] : null, 'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 4, 'origin' => 'ast', 'confidence' => 'certain', 'attributes_json' => '{"a":1}', 'owner_key' => 'owner-1', 'last_scan_id' => $ids['scan']],
                'second' => ['language' => 'ts', 'kind' => 'function', 'canonical_name' => 'App\\B', 'display_name' => 'B', 'parent_id' => $writer === 'saveNode' ? $ids['invoice'] : null, 'file_id' => $otherFile, 'start_line' => 2, 'end_line' => 5, 'origin' => 'heuristic', 'confidence' => 'possible', 'attributes_json' => '{"b":2}', 'owner_key' => 'owner-2', 'last_scan_id' => 'scan-2'],
                'save' => $writer === 'saveNode'
                    ? static fn(array $c) => $write->saveNode('node-under-test', $project, $c['language'], $c['kind'], $c['canonical_name'], $c['display_name'], $c['parent_id'], $c['file_id'], $c['start_line'], $c['end_line'], $c['origin'], $c['confidence'], json_decode($c['attributes_json'], true), $c['owner_key'], $c['last_scan_id'])
                    : static fn(array $c) => $write->saveNodes([['id' => 'node-under-test', 'attributes' => json_decode($c['attributes_json'], true)] + $c], $project, $c['last_scan_id']),
            ],
            'saveEdge', 'saveEdges' => [
                'table' => 'edges',
                'key' => ['id' => 'edge-under-test'],
                'first' => ['kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'], 'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 4, 'origin' => 'ast', 'confidence' => 'certain', 'attributes_json' => '{"a":1}', 'owner_key' => 'owner-1', 'last_scan_id' => $ids['scan']],
                'second' => ['kind' => 'extends', 'source_id' => $ids['invoice'], 'target_id' => $ids['checkout'], 'file_id' => $otherFile, 'start_line' => 2, 'end_line' => 5, 'origin' => 'heuristic', 'confidence' => 'possible', 'attributes_json' => '{"b":2}', 'owner_key' => 'owner-2', 'last_scan_id' => 'scan-2'],
                'save' => $writer === 'saveEdge'
                    ? static fn(array $c) => $write->saveEdge('edge-under-test', $project, $c['kind'], $c['source_id'], $c['target_id'], $c['file_id'], $c['start_line'], $c['end_line'], $c['origin'], $c['confidence'], json_decode($c['attributes_json'], true), $c['owner_key'], $c['last_scan_id'])
                    : static fn(array $c) => $write->saveEdges([['id' => 'edge-under-test', 'attributes' => json_decode($c['attributes_json'], true)] + $c], $project, $c['last_scan_id']),
            ],
            'saveFile', 'saveFiles' => [
                'table' => 'files',
                'key' => ['relative_path' => 'src/Under/Test.php'],
                'first' => ['content_hash' => 'aaaa', 'size' => 10, 'mtime' => 100, 'language' => 'php', 'scanner_version' => '1.0', 'line_count' => 5, 'last_scan_id' => $ids['scan']],
                'second' => ['content_hash' => 'bbbb', 'size' => 20, 'mtime' => 200, 'language' => 'ts', 'scanner_version' => '2.0', 'line_count' => 9, 'last_scan_id' => 'scan-2'],
                'save' => $writer === 'saveFile'
                    ? static fn(array $c) => $write->saveFile('file-under-test', $project, 'src/Under/Test.php', $c['content_hash'], $c['size'], $c['mtime'], $c['language'], $c['scanner_version'], $c['last_scan_id'], $c['line_count'])
                    : static fn(array $c) => $write->saveFiles([['id' => 'file-under-test', 'relative_path' => 'src/Under/Test.php'] + $c], $project, $c['last_scan_id']),
            ],
            'saveClassifications' => [
                'table' => 'classifications',
                'key' => ['id' => 'classification-under-test'],
                'first' => ['node_id' => $ids['checkout'], 'role' => 'application.controller', 'origin' => 'ast', 'confidence' => 'certain', 'rule_id' => 'rule-1', 'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 4, 'attributes_json' => '{"a":1}', 'last_scan_id' => $ids['scan']],
                'second' => ['node_id' => $ids['invoice'], 'role' => 'application.service', 'origin' => 'heuristic', 'confidence' => 'possible', 'rule_id' => 'rule-2', 'file_id' => $otherFile, 'start_line' => 2, 'end_line' => 5, 'attributes_json' => '{"b":2}', 'last_scan_id' => 'scan-2'],
                'save' => static fn(array $c) => $write->saveClassifications([['id' => 'classification-under-test', 'attributes' => json_decode($c['attributes_json'], true)] + $c], $project, $c['last_scan_id']),
            ],
            'saveBoundary' => [
                'table' => 'boundaries',
                'key' => ['id' => 'boundary-under-test'],
                'first' => ['name' => 'Domain', 'matcher_json' => '{"path_prefix":"src/Domain"}', 'source' => 'explicit', 'last_scan_id' => $ids['scan']],
                'second' => ['name' => 'Core', 'matcher_json' => '{"path_prefix":"src/Core"}', 'source' => 'inferred', 'last_scan_id' => 'scan-2'],
                'save' => static fn(array $c) => $write->saveBoundary('boundary-under-test', $project, $c['name'], json_decode($c['matcher_json'], true), $c['source'], $c['last_scan_id']),
            ],
        };
        // The columns the skip-unchanged comparison must cover: every replaced
        // column except the scan stamp, and parent_id, which the batch writer
        // never sets (the reconciler links parents in a second pass).
        $case['compared'] = array_fill_keys(array_diff(array_keys($case['first']), ['last_scan_id', 'parent_id']), true);

        return [$pdo, $case];
    }

    /**
     * The row as stored, limited to the case's columns.
     *
     * @param array{table: string, key: array<string, string>, first: array<string, mixed>} $case
     * @return array<string, string|null>
     */
    private function read(PDO $pdo, array $case): array
    {
        [$column, $value] = [array_key_first($case['key']), reset($case['key'])];
        $statement = $pdo->prepare(sprintf('SELECT %s FROM %s WHERE %s = ?', implode(', ', array_keys($case['first'])), $case['table'], $column));
        $statement->execute([$value]);

        return self::stored($statement->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Values as SQLite hands them back: strings, or null.
     *
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private static function stored(array $row): array
    {
        return array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row);
    }
}
