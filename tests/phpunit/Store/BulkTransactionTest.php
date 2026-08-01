<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Rewriting a project's graph pays per-statement foreign-key enforcement on
 * every row it touches, and that enforcement — not the row count — is what a
 * rescan spends its time on: clearing this repository's own graph measured
 * 5.6s with keys enforced per statement against 0.6s with one integrity check
 * at the end. A bulk transaction buys that back without giving up the
 * guarantee, because nothing that violates a key may still be committed.
 */
final class BulkTransactionTest extends KnossosTestCase
{
    #[Group('storage')]
    public function testAChildRowMayBeWrittenBeforeItsParent(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $source = StableId::symbol($ids['project'], 'php', 'class', 'App\\Later');

        $repository->bulkTransaction(function (SqliteGraphRepository $repository) use ($ids, $source): void {
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $source, $ids['invoice'], 'src/Later.php:4'),
                $ids['project'], 'calls', $source, $ids['invoice'], $ids['file'], 4, 4, 'ast', 'certain', [], 'php:file:src/Later.php', $ids['scan'],
            );
            $repository->saveNode(
                $source, $ids['project'], 'php', 'class', 'App\\Later', 'Later', null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Later.php', $ids['scan'],
            );
        });

        assertSame('1', $this->countEdgesFrom($pdo, $source));
    }

    #[Group('storage')]
    public function testATransactionLeavingADanglingReferenceIsRejectedAndCommitsNothing(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $missing = StableId::symbol($ids['project'], 'php', 'class', 'App\\NeverWritten');

        self::assertThrowsWith(
            fn() => $repository->bulkTransaction(function (SqliteGraphRepository $repository) use ($ids, $missing): void {
                $repository->saveEdge(
                    StableId::edge($ids['project'], 'calls', $missing, $ids['invoice'], 'src/Ghost.php:4'),
                    $ids['project'], 'calls', $missing, $ids['invoice'], $ids['file'], 4, 4, 'ast', 'certain', [], 'php:file:src/Ghost.php', $ids['scan'],
                );
            }),
            RuntimeException::class,
        );

        assertSame('0', $this->countEdgesFrom($pdo, $missing));
    }

    #[Group('storage')]
    public function testEnforcementIsRestoredForOrdinaryWritesAfterwards(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->bulkTransaction(static fn(): null => null);
        $missing = StableId::symbol($ids['project'], 'php', 'class', 'App\\StillMissing');

        self::assertThrowsWith(
            fn() => $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $missing, $ids['invoice'], 'src/Ghost.php:9'),
                $ids['project'], 'calls', $missing, $ids['invoice'], $ids['file'], 9, 9, 'ast', 'certain', [], 'php:file:src/Ghost.php', $ids['scan'],
            ),
            \PDOException::class,
        );

        assertSame('1', (string) $pdo->query('SELECT * FROM pragma_foreign_keys')->fetchColumn());
    }

    private function countEdgesFrom(\PDO $pdo, string $sourceId): string
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM edges WHERE source_id = :source');
        $statement->execute(['source' => $sourceId]);

        return (string) $statement->fetchColumn();
    }
}
