<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The cache rows are written by difference so that a contribution reused from
 * the previous scan keeps its row, and with it the reads that decide when it
 * goes stale.
 */
final class ContributionCacheWriteTest extends KnossosTestCase
{
    #[Group('store')]
    public function testReadsAreStoredPerOwnerIncludingAProbedMiss(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', ['b.ts' => self::hash('b'), 'missing.ts' => null]),
            self::entry('c.ts', []),
        ]);

        assertSame(
            [['a.ts', 'b.ts', self::hash('b')], ['a.ts', 'missing.ts', null]],
            array_map('array_values', $pdo->query('SELECT owner_key, read_path, read_hash FROM contribution_reads ORDER BY owner_key, read_path')->fetchAll(PDO::FETCH_ASSOC)),
        );
    }

    #[Group('store')]
    public function testAnEntryFromCacheKeepsItsRowAndReadsWhileAChangedOneIsReplaced(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', ['x.ts' => self::hash('x')]),
            self::entry('b.ts', ['y.ts' => self::hash('y')]),
        ]);
        $pdo->exec("UPDATE contribution_cache SET updated_at = 'marker'");

        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', ['ignored.ts' => self::hash('ignored')], fromCache: true),
            self::entry('b.ts', ['z.ts' => self::hash('z')], hash: self::hash('b2')),
        ]);

        assertSame('marker', $this->updatedAt($pdo, 'a.ts'));
        assertSame(['x.ts'], $this->readPaths($pdo, 'a.ts'));
        self::assertNotSame('marker', $this->updatedAt($pdo, 'b.ts'));
        assertSame(['z.ts'], $this->readPaths($pdo, 'b.ts'));
        assertSame(self::hash('b2'), (string) $pdo->query("SELECT content_hash FROM contribution_cache WHERE owner_key = 'b.ts'")->fetchColumn());
    }

    #[Group('store')]
    public function testAnOwnerMissingFromTheWriteIsDeletedWithItsReadsUnderABulkTransaction(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', ['x.ts' => self::hash('x')]),
            self::entry('b.ts', ['y.ts' => self::hash('y')]),
        ]);

        $repository->bulkTransaction(static function (SqliteGraphRepository $repository) use ($ids): void {
            $repository->replaceContributionCache($ids['project'], [self::entry('a.ts', [], fromCache: true)]);
        });

        assertSame(['a.ts'], $pdo->query('SELECT owner_key FROM contribution_cache')->fetchAll(PDO::FETCH_COLUMN));
        assertSame(['a.ts'], $pdo->query('SELECT DISTINCT owner_key FROM contribution_reads')->fetchAll(PDO::FETCH_COLUMN));
    }

    #[Group('store')]
    public function testAReadGroupIsStoredOnceAndDroppedWhenNoEntryReferencesIt(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $group = ['tsconfig.json' => self::hash('t'), 'gone.d.ts' => null];
        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', [], readGroup: 'g1'),
            self::entry('b.ts', [], readGroup: 'g1'),
        ], ['g1' => $group]);
        assertSame('2', (string) $pdo->query("SELECT COUNT(*) FROM contribution_read_groups WHERE group_id = 'g1'")->fetchColumn());

        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', [], fromCache: true, readGroup: 'g1'),
            self::entry('b.ts', [], readGroup: 'g2'),
        ], ['g1' => $group, 'g2' => ['other.json' => self::hash('o')]]);
        assertSame(['g1', 'g2'], $pdo->query('SELECT DISTINCT group_id FROM contribution_read_groups ORDER BY group_id')->fetchAll(PDO::FETCH_COLUMN));

        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', [], fromCache: true, readGroup: 'g1'),
            self::entry('b.ts', [], fromCache: true, readGroup: 'g2'),
        ]);
        $repository->replaceContributionCache($ids['project'], [
            self::entry('a.ts', [], fromCache: true, readGroup: 'g1'),
        ]);
        assertSame(['g1'], $pdo->query('SELECT DISTINCT group_id FROM contribution_read_groups')->fetchAll(PDO::FETCH_COLUMN));
    }

    #[Group('store')]
    public function testAnEntryNamingAnUnknownReadGroupIsRefusedAndWritesNothing(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();

        self::assertThrowsWith(
            fn() => $repository->replaceContributionCache($ids['project'], [self::entry('a.ts', [], readGroup: 'nowhere')]),
            \InvalidArgumentException::class,
        );
        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contribution_cache')->fetchColumn());
    }

    #[Group('store')]
    public function testAnEntryRejectsAMalformedReadPathOrHash(): void
    {
        self::assertThrowsWith(fn() => self::entry('a.ts', ['../escape' => self::hash('x')]), \InvalidArgumentException::class);
        self::assertThrowsWith(fn() => self::entry('a.ts', ['b.ts' => 'NOTHEX']), \InvalidArgumentException::class);
    }

    /** @param array<string, ?string> $reads */
    private static function entry(string $owner, array $reads, bool $fromCache = false, ?string $readGroup = null, ?string $hash = null): ContributionCacheEntry
    {
        return new ContributionCacheEntry(
            $owner,
            $hash ?? self::hash($owner),
            'knossos.typescript',
            '1.0.0',
            'config',
            new ScanContribution($owner),
            $reads,
            $readGroup,
            $reads !== [],
            $fromCache,
        );
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    private function updatedAt(PDO $pdo, string $owner): string
    {
        return (string) $pdo->query(sprintf("SELECT updated_at FROM contribution_cache WHERE owner_key = '%s'", $owner))->fetchColumn();
    }

    /** @return list<string> */
    private function readPaths(PDO $pdo, string $owner): array
    {
        return $pdo->query(sprintf("SELECT read_path FROM contribution_reads WHERE owner_key = '%s' ORDER BY read_path", $owner))->fetchAll(PDO::FETCH_COLUMN);
    }
}
