<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PDO;

/**
 * A large synthetic dependency graph, generated in SQL so a test can build
 * tens of thousands of rows in well under a second.
 *
 * Node i is `node:` followed by i padded to 64 digits, the length of a real
 * id. Every generated edge points from a lower index to a higher one
 * (i -> i + 7k + 1 for each step k), so the graph is acyclic until a test adds
 * a back edge with {@see self::edge()}.
 */
final class SyntheticGraph
{
    /**
     * Inserts `$nodes` class nodes and up to `$steps` edges out of each into the fixture's project.
     *
     * The node and step bounds are spelled into the SQL rather than bound: a
     * bound parameter arrives as text, an integer compares below any text, and
     * the recursion would never end.
     *
     * @param array<string, string> $ids the storeFixture ids: project, file and scan are used
     */
    public static function seed(PDO $pdo, array $ids, int $nodes, int $steps): void
    {
        $last = sprintf('%d', $nodes - 1);
        $stepValues = implode(', ', array_map(static fn(int $k): string => sprintf('(%d)', $k), range(0, $steps - 1)));
        $bind = ['project' => $ids['project'], 'file' => $ids['file'], 'scan' => $ids['scan']];
        $pdo->prepare(
            'INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, ' .
            'start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) ' .
            'WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < ' . $last . ') ' .
            "SELECT 'node:' || printf('%064d', i), :project, 'php', 'class', 'App\\Synthetic' || printf('%05d', i), " .
            "'Synthetic' || printf('%05d', i), NULL, :file, 1, 1, 'ast', 'certain', '{}', 'php:file:src/Checkout.php', :scan FROM seq",
        )->execute($bind);
        $pdo->prepare(
            'INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            'confidence, attributes_json, owner_key, last_scan_id) ' .
            'WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < ' . $last . '), ' .
            'step(k) AS (VALUES ' . $stepValues . ') ' .
            "SELECT 'edge:' || i || ':' || k, :project, CASE k % 2 WHEN 0 THEN 'calls' ELSE 'imports' END, " .
            "'node:' || printf('%064d', i), 'node:' || printf('%064d', i + k * 7 + 1), :file, 1, 1, 'ast', 'certain', " .
            "'{}', 'php:file:src/Checkout.php', :scan FROM seq, step WHERE i + k * 7 + 1 <= " . $last,
        )->execute($bind);
    }

    /** The id of node `$i`. */
    public static function node(int $i): string
    {
        return 'node:' . sprintf('%064d', $i);
    }

    /**
     * Inserts one edge between two synthetic nodes, a back edge that closes a loop or one with attributes of its own.
     *
     * @param array<string, string> $ids the storeFixture ids: project, file and scan are used
     */
    public static function edge(PDO $pdo, array $ids, string $id, string $kind, int $from, int $to, string $attributes = '{}'): void
    {
        $pdo->prepare(
            'INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            "confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, 7, 7, 'ast', 'certain', ?, 'php:file:src/Checkout.php', ?)",
        )->execute([$id, $ids['project'], $kind, self::node($from), self::node($to), $ids['file'], $attributes, $ids['scan']]);
    }
}
