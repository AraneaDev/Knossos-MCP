<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Discovery\FileFingerprint;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;

final class StoreTest extends KnossosTestCase
{
    #[Group('store')]
    public function testMigrationsAreVersionedAndIdempotent(): void
    {
        $pdo = SqliteConnection::open(':memory:');
        $runner = new MigrationRunner($pdo, self::repositoryRoot() . '/migrations');

        assertSame(['001_initial_graph', '002_classifications', '003_boundary_memberships', '004_contribution_cache', '005_scan_locks', '006_http_sessions', '007_scan_snapshots', '008_occurrence_edges', '009_file_line_count', '010_language_scoped_node_uniqueness', '011_annotations', '012_add_missing_indexes', '013_edges_fk_child_indexes'], $runner->migrate());
        assertSame([], $runner->migrate());
        assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $edgeSchema = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'edges'")->fetchColumn();
        assertSame(false, str_contains($edgeSchema, 'UNIQUE (project_id, kind, source_id, target_id, owner_key)'));
    }

    #[Group('store')]
    public function testOccurrenceEdgeMigrationPreservesLegacyRowsAndPermitsRepeatedRelations(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-edge-migration-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        copy(self::repositoryRoot() . '/migrations/001_initial_graph.sql', $directory . '/001_initial_graph.sql');
        // The fixture writer persists line_count and language, so the baseline
        // includes migrations 009 and 010.
        copy(self::repositoryRoot() . '/migrations/009_file_line_count.sql', $directory . '/009_file_line_count.sql');
        copy(self::repositoryRoot() . '/migrations/010_language_scoped_node_uniqueness.sql', $directory . '/010_language_scoped_node_uniqueness.sql');

        try {
            [$pdo, $repository, $ids] = $this->storeFixture($directory);
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

            copy(self::repositoryRoot() . '/migrations/008_occurrence_edges.sql', $directory . '/008_occurrence_edges.sql');
            assertSame(['008_occurrence_edges'], (new MigrationRunner($pdo, $directory))->migrate());
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:13'),
                $ids['project'],
                'calls',
                $ids['checkout'],
                $ids['invoice'],
                $ids['file'],
                13,
                13,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
            assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
            assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            unset($repository, $pdo);
            foreach (glob($directory . '/*.sql') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Group('store')]
    public function testFileSqliteConnectionsEnableWal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-store-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate temporary database path.');
        }

        try {
            $pdo = SqliteConnection::open($path);
            assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
            unset($pdo);
        } finally {
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    #[Group('store')]
    public function testGraphRepositoryPersistsAndTraversesFacts(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();

        $byCanonical = $repository->findNodesByName($ids['project'], 'App\\Checkout');
        assertSame($ids['checkout'], $byCanonical[0]['id']);
        $byDisplay = $repository->findNodesByName($ids['project'], 'InvoiceService');
        assertSame($ids['invoice'], $byDisplay[0]['id']);

        $outgoing = $repository->outgoing($ids['project'], $ids['checkout'], 'calls');
        assertSame($ids['invoice'], $outgoing[0]['target_id']);
        $incoming = $repository->incoming($ids['project'], $ids['invoice']);
        assertSame($ids['checkout'], $incoming[0]['source_id']);

        $repository->completeScan($ids['project'], $ids['scan']);
        assertSame($ids['scan'], $repository->findProject($ids['project'])['active_scan_id']);
    }

    #[Group('store')]
    public function testRepositoryHotWritesReusePreparedStatements(): void
    {
        [, $repository, $ids] = $this->storeFixture();
        $property = new ReflectionProperty($repository, 'statements');
        $before = $property->getValue($repository);
        $repository->saveFile(
            $ids['file'],
            $ids['project'],
            'src/Checkout.php',
            hash('sha256', 'checkout-updated'),
            43,
            2,
            'php',
            '1.0.0',
            $ids['scan'],
        );
        $after = $property->getValue($repository);
        assertSame(count($before), count($after));
        assertSame(1, count(array_filter(array_keys($after), fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO files'))));
    }

    #[Group('store')]
    public function testRepositoryTransactionRollsBackAllWrites(): void
    {
        [, $repository, $ids] = $this->storeFixture();

        assertThrows(
            function () use ($repository, $ids): void {
                $repository->transaction(function (SqliteGraphRepository $store) use ($ids): void {
                    $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\RolledBack');
                    $store->saveNode(
                        $id,
                        $ids['project'],
                        'php',
                        'class',
                        'App\\RolledBack',
                        'RolledBack',
                        null,
                        $ids['file'],
                        20,
                        30,
                        'ast',
                        'certain',
                        [],
                        'php:file:src/RolledBack.php',
                        $ids['scan'],
                    );
                    throw new RuntimeException('force rollback');
                });
            },
            RuntimeException::class,
        );

        assertSame([], $repository->findNodesByName($ids['project'], 'App\\RolledBack'));
    }

    #[Group('store')]
    public function testGraphLookupAndTraversalQueriesUseIndexes(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        $nodePlan = $pdo->query(
            "EXPLAIN QUERY PLAN SELECT * FROM nodes WHERE project_id = '" . $ids['project'] . "' " .
            "AND canonical_name = 'App\\Checkout'",
        )->fetchAll();
        $edgePlan = $pdo->query(
            "EXPLAIN QUERY PLAN SELECT * FROM edges WHERE project_id = '" . $ids['project'] . "' " .
            "AND source_id = '" . $ids['checkout'] . "' AND kind = 'calls'",
        )->fetchAll();

        assertContains('nodes_project_canonical_idx', implode(' ', array_column($nodePlan, 'detail')));
        assertContains('edges_project_source_idx', implode(' ', array_column($edgePlan, 'detail')));
    }

    #[Group('store')]
    public function testStableIdsAreDeterministicAndRouteMethodsAreOrderIndependent(): void
    {
        $project = StableId::project('shop');
        assertSame($project, StableId::project('shop'));
        assertSame(
            StableId::route($project, ['POST', 'GET'], '/checkout', 'CheckoutController'),
            StableId::route($project, ['GET', 'POST'], '/checkout', 'CheckoutController'),
        );
        assertNotSame(
            StableId::symbol($project, 'php', 'class', 'Checkout'),
            StableId::symbol($project, 'typescript', 'class', 'Checkout'),
        );
    }

    #[Group('store')]
    public function testFileFingerprintCountsPhysicalLinesAndPreservesTheContentHash(): void
    {
        $cases = [
            ['', 0],
            ["line\n", 1],
            ['line', 1],
            ["a\nb", 2],
            ["a\nb\n", 2],
            ["a\r\nb\r\n", 2],
            ["\n", 1],
            ["\n\n\n", 3],
        ];
        foreach ($cases as [$content, $expected]) {
            $path = tempnam(sys_get_temp_dir(), 'knossos-fp-');
            if ($path === false) {
                throw new RuntimeException('Unable to allocate fingerprint fixture.');
            }
            try {
                file_put_contents($path, $content);
                $fingerprint = FileFingerprint::compute($path);
                assertSame($expected, $fingerprint?->lineCount);
                assertSame(hash('sha256', $content), $fingerprint?->contentHash);
            } finally {
                @unlink($path);
            }
        }
        // Large bounded file: exactly N newline-terminated physical lines.
        $large = tempnam(sys_get_temp_dir(), 'knossos-fp-large-');
        if ($large === false) {
            throw new RuntimeException('Unable to allocate large fingerprint fixture.');
        }
        try {
            file_put_contents($large, str_repeat("x\n", 5000));
            assertSame(5000, FileFingerprint::compute($large)?->lineCount);
        } finally {
            @unlink($large);
        }
        // An unreadable path returns null rather than throwing mid-discovery.
        assertSame(null, FileFingerprint::compute(sys_get_temp_dir() . '/knossos-missing-' . bin2hex(random_bytes(6))));
    }

    /**
     * Kills ArrayItemRemoval mutants on src/Store/StableId.php lines
     * 20/48/58/63/68. Each surviving factory method drops $projectId from
     * the parts array, so two distinct projects collide. Pairs of
     * `assertNotSame($alpha, $beta)` per factory pin the project-id
     * sensitivity; an extra triplet for `route()` pins uri / action /
     * projectId sensitivity beyond what the pre-existing
     * `testStableIdsAreDeterministicAndRouteMethodsAreOrderIndependent`
     * covers (order-independence only, not distinct-input divergence).
     */
    #[Group('store')]
    public function testStableIdPartsAreAllConsumedByEveryFactory(): void
    {
        $alpha = 'project-alpha';
        $beta = 'project-beta';

        assertNotSame(
            StableId::file($alpha, 'src/CheckoutService.php'),
            StableId::file($beta, 'src/CheckoutService.php'),
        );
        assertNotSame(
            StableId::scan($alpha, 'nonce-1'),
            StableId::scan($beta, 'nonce-1'),
        );
        assertNotSame(
            StableId::edge($alpha, 'calls', 'src-a', 'src-b', 'src/X.php:13'),
            StableId::edge($beta, 'calls', 'src-a', 'src-b', 'src/X.php:13'),
        );
        assertNotSame(
            StableId::classification($alpha, 'App\\Foo', 'domain', 'rule-x'),
            StableId::classification($beta, 'App\\Foo', 'domain', 'rule-x'),
        );
        assertNotSame(
            StableId::boundary($alpha, 'typescript:tsconfig.json', '{"compilerOptions":{}}'),
            StableId::boundary($beta, 'typescript:tsconfig.json', '{"compilerOptions":{}}'),
        );

        // route(): projectId-sensitive, uri-sensitive, action-sensitive.
        assertNotSame(
            StableId::route($alpha, ['GET'], '/checkout', 'CheckoutController'),
            StableId::route($alpha, ['GET'], '/invoice', 'CheckoutController'),
        );
        assertNotSame(
            StableId::route($alpha, ['GET'], '/checkout', 'CheckoutController'),
            StableId::route($alpha, ['GET'], '/checkout', 'InvoiceController'),
        );
        assertNotSame(
            StableId::route($alpha, ['GET'], '/checkout', 'CheckoutController'),
            StableId::route($beta, ['GET'], '/checkout', 'CheckoutController'),
        );
    }

    /**
     * Kills the Foreach_ mutant on src/Store/StableId.php line 74. The
     * mutant iterates over `[]` instead of `$parts`, so the empty-part
     * check inside `make()` never fires; an empty-string call into any
     * factory must throw.
     */
    #[Group('store')]
    public function testStableIdRejectsEmptyParts(): void
    {
        assertThrows(fn() => StableId::project(''), InvalidArgumentException::class);
        assertThrows(fn() => StableId::file('p', ''), InvalidArgumentException::class);
        assertThrows(
            fn() => StableId::route('p', [], '/x', 'A'),
            InvalidArgumentException::class,
        );
    }

    /**
     * Kills the BitwiseOr mutant on src/Store/StableId.php line 80. The
     * mutant collapses
     * `(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`
     * into `(A | B) & C`, which on the actual flag values evaluates to 0;
     * the resulting default encoding escapes slashes and unicode, so the
     * sha256 diverges for any input containing `/` or a non-ASCII character.
     */
    #[Group('store')]
    public function testStableIdHashEncodesJsonWithAllThreeFlags(): void
    {
        $slashCase = json_encode(
            ['p', 'src/CheckoutService.php'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        assertSame(
            'file_' . hash('sha256', $slashCase),
            StableId::file('p', 'src/CheckoutService.php'),
        );

        $unicodePayload = json_encode(
            ['p', 'name-' . "\u{1F600}"],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        assertSame(
            'file_' . hash('sha256', $unicodePayload),
            StableId::file('p', 'name-' . "\u{1F600}"),
        );
    }
}
