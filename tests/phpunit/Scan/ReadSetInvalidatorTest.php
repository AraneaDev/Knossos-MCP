<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CachedReads;
use Knossos\Scan\ReadSetInvalidator;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * A cached contribution is current only while every file it read still has the
 * bytes it read, and that includes files that were themselves rescanned because
 * of something they read.
 */
final class ReadSetInvalidatorTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testAChangeReachesEveryOwnerThatTransitivelyReadIt(): void
    {
        $cached = self::cached([
            'x' => self::row('x', ['b' => self::hash('b')]),
            'b' => self::row('b', ['c' => self::hash('c')]),
            'c' => self::row('c', []),
            'y' => self::row('y', ['z' => self::hash('z')]),
        ]);
        $discovered = self::discovered(['x', 'b', 'y']) + ['c' => self::hash('c2'), 'z' => self::hash('z')];

        assertSame(['b', 'c', 'x'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe())));
    }

    #[Group('scan')]
    public function testOwnersThatReadEachOtherAreBothInvalidatedAndTheCallReturns(): void
    {
        $cached = self::cached([
            'a' => self::row('a', ['b' => self::hash('b')]),
            'b' => self::row('b', ['a' => self::hash('a')]),
        ]);
        $discovered = ['a' => self::hash('a2'), 'b' => self::hash('b')];

        assertSame(['a', 'b'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe())));
    }

    #[Group('scan')]
    public function testAProbedMissThatNowExistsInvalidatesTheOwner(): void
    {
        $cached = self::cached(['x' => self::row('x', ['lib.rs' => null])]);
        $discovered = self::discovered(['x']) + ['lib.rs' => self::hash('lib')];

        assertSame(['x'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe())));
    }

    #[Group('scan')]
    public function testAReadFileThatWasDeletedInvalidatesTheOwner(): void
    {
        $cached = self::cached(['x' => self::row('x', ['b' => self::hash('b')])]);

        assertSame(['x'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, self::discovered(['x']), self::noProbe())));
    }

    #[Group('scan')]
    public function testAChangedUndiscoveredGroupReadInvalidatesEveryOwnerOfTheGroup(): void
    {
        $cached = new CachedReads(
            [
                'p' => self::row('p', [], group: 'G'),
                'q' => self::row('q', [], group: 'G'),
                'r' => self::row('r', []),
            ],
            ['p' => [], 'q' => [], 'r' => []],
            ['G' => ['node_modules/t.d.ts' => self::hash('H1')]],
        );
        $probe = static fn(string $path, ?string $stored): bool => $stored === ($path === 'node_modules/t.d.ts' ? self::hash('H2') : null);

        assertSame(['p', 'q'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, self::discovered(['p', 'q', 'r']), $probe)));
    }

    /**
     * A scanner whose added files affect every file it scanned rebuilds all of
     * its rows once one is added, whatever its reads say; other scanners, and
     * a scan that adds nothing, keep what the reads reached.
     */
    #[Group('scan')]
    public function testAnAddedFileRebuildsEveryRowOfAScannerWhoseAddedFilesAffectAll(): void
    {
        $cached = self::cached([
            'a.ts' => self::row('a.ts', [], 'knossos.typescript'),
            'b.ts' => self::row('b.ts', [], 'knossos.typescript'),
            'p.php' => self::row('p.php', []),
        ]);
        $reached = ['a.ts' => true];

        $rebuilt = ReadSetInvalidator::withAddedFilesAffectingAll($cached, $reached, ['knossos.typescript' => ['g.ts']], 'knossos.typescript');

        assertSame(['a.ts', 'b.ts'], self::sortedKeys($rebuilt));
        assertSame($reached, ReadSetInvalidator::withAddedFilesAffectingAll($cached, $reached, [], 'knossos.typescript'));
        assertSame($reached, ReadSetInvalidator::withAddedFilesAffectingAll($cached, $reached, ['knossos.php' => ['q.php']], 'knossos.typescript'));
        assertSame($reached, ReadSetInvalidator::withAddedFilesAffectingAll(null, $reached, ['knossos.typescript' => ['g.ts']], 'knossos.typescript'));
    }

    /**
     * A group the owner names but the store no longer holds leaves nothing to
     * compare its reads against, so the owner cannot be shown to be current.
     */
    #[Group('scan')]
    public function testAnOwnerWhoseReadGroupIsMissingIsInvalidated(): void
    {
        $cached = new CachedReads(
            [
                'p' => self::row('p', [], group: 'gone'),
                'q' => self::row('q', [], group: 'G'),
                'r' => self::row('r', []),
            ],
            ['p' => [], 'q' => [], 'r' => []],
            ['G' => ['tsconfig.json' => self::hash('t')]],
        );
        $discovered = self::discovered(['p', 'q', 'r']) + ['tsconfig.json' => self::hash('t')];

        assertSame(['p'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe())));
    }

    #[Group('scan')]
    public function testAnUnattributedScannerIsInvalidatedWholeWhileAnAttributedOneLosesOnlyTheChangedFile(): void
    {
        $cached = self::cached([
            'm.ts' => self::row('m.ts', [], scanner: 'knossos.typescript', attributed: false),
            'n.ts' => self::row('n.ts', [], scanner: 'knossos.typescript', attributed: false),
            'A.php' => self::row('A.php', [], scanner: 'knossos.php'),
            'B.php' => self::row('B.php', [], scanner: 'knossos.php'),
        ]);

        $typescript = self::discovered(['n.ts', 'A.php', 'B.php']) + ['m.ts' => self::hash('m2')];
        assertSame(['m.ts', 'n.ts'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $typescript, self::noProbe())));

        $php = self::discovered(['m.ts', 'n.ts', 'B.php']) + ['A.php' => self::hash('A2')];
        assertSame(['A.php'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $php, self::noProbe())));
    }

    #[Group('scan')]
    public function testAnAddedFileOfAnUnattributedScannersLanguageInvalidatesThatScannerOnly(): void
    {
        $cached = self::cached([
            'm.ts' => self::row('m.ts', [], scanner: 'knossos.typescript', attributed: false),
            'n.ts' => self::row('n.ts', [], scanner: 'knossos.typescript', attributed: false),
            'A.php' => self::row('A.php', [], scanner: 'knossos.php'),
        ]);
        $discovered = self::discovered(['m.ts', 'n.ts', 'A.php', 'new.ts', 'New.php']);

        assertSame(['m.ts', 'n.ts'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe(), ['knossos.typescript' => ['new.ts']])));
        assertSame([], ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe(), ['knossos.php' => ['New.php']]));
        assertSame([], ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe(), ['knossos.typescript' => []]));
    }

    #[Group('scan')]
    public function testAFileTheFallbackRebuildsReachesItsReadersInAnotherScanner(): void
    {
        $cached = self::cached([
            'm.ts' => self::row('m.ts', [], scanner: 'knossos.typescript', attributed: false),
            'n.ts' => self::row('n.ts', [], scanner: 'knossos.typescript', attributed: false),
            'View.php' => self::row('View.php', ['n.ts' => self::hash('n.ts')]),
            'Other.php' => self::row('Other.php', []),
        ]);
        $discovered = self::discovered(['n.ts', 'View.php', 'Other.php']) + ['m.ts' => self::hash('m2')];

        assertSame(['View.php', 'm.ts', 'n.ts'], self::sortedKeys(ReadSetInvalidator::invalidated($cached, $discovered, self::noProbe())));
    }

    #[Group('scan')]
    public function testEveryRouteIntoAnUnattributedScannerRebuildsAllOfIt(): void
    {
        $rows = [
            'm.ts' => self::row('m.ts', [], scanner: 'knossos.typescript', attributed: false, group: 'G'),
            'n.ts' => self::row('n.ts', ['own.d.ts' => self::hash('own')], scanner: 'knossos.typescript', attributed: false),
            'o.ts' => self::row('o.ts', [], scanner: 'knossos.typescript', attributed: false),
        ];
        $cached = new CachedReads(
            array_map(static function (array $row): array {
                unset($row['reads']);

                return $row;
            }, $rows),
            array_map(static fn(array $row): array => $row['reads'], $rows),
            ['G' => ['node_modules/g.d.ts' => self::hash('g')]],
        );
        $all = ['m.ts', 'n.ts', 'o.ts'];
        $same = static fn(string $path, ?string $stored): bool => $stored === self::hash($path === 'node_modules/g.d.ts' ? 'g' : 'own');

        // A changed undiscovered group read.
        $groupChanged = static fn(string $path, ?string $stored): bool => $path !== 'node_modules/g.d.ts' && $same($path, $stored);
        assertSame($all, self::sortedKeys(ReadSetInvalidator::invalidated($cached, self::discovered($all), $groupChanged)));
        // A changed read of one owner's own.
        $ownChanged = static fn(string $path, ?string $stored): bool => $path !== 'own.d.ts' && $same($path, $stored);
        assertSame($all, self::sortedKeys(ReadSetInvalidator::invalidated($cached, self::discovered($all), $ownChanged)));
        // A deleted file of the scanner.
        assertSame($all, self::sortedKeys(ReadSetInvalidator::invalidated($cached, self::discovered(['m.ts', 'n.ts']), $same)));
        // Nothing changed.
        assertSame([], ReadSetInvalidator::invalidated($cached, self::discovered($all), $same));
    }

    /**
     * Many owners sharing a group with many reads must not cost memory in
     * owners times reads. Four groups of 1,000 reads shared by 250 owners each
     * would index a million owner-read pairs, tens of megabytes, if a group's
     * owners were copied onto every path it holds; walked lazily they stay at
     * a few megabytes. The bound is asserted here rather than left to the
     * process memory limit, which also carries whatever else the run holds,
     * and in a process of its own so that resetting the peak to measure it
     * does not hide the suite's own peak from the run's report.
     */
    #[Group('scan')]
    #[RunInSeparateProcess]
    public function testLargeSharedGroupsStayWithinMemory(): void
    {
        $rows = [];
        $owners = [];
        $groups = [];
        for ($group = 0; $group < 4; ++$group) {
            for ($read = 0; $read < 1000; ++$read) {
                $groups['G' . $group][sprintf('node_modules/g%d/%04d.d.ts', $group, $read)] = self::hash('r' . $read);
            }
            for ($owner = 0; $owner < 250; ++$owner) {
                $path = sprintf('src/g%d/f%03d.ts', $group, $owner);
                $rows[$path] = ['scanner_id' => 'knossos.typescript', 'file_path' => $path, 'content_hash' => self::hash($path), 'read_attribution' => true, 'read_group' => 'G' . $group];
                $owners[$path] = [];
            }
        }
        $cached = new CachedReads($rows, $owners, $groups);
        $discovered = self::discovered(array_keys($rows));
        $probe = static fn(string $path, ?string $stored): bool => $path !== 'node_modules/g3/0007.d.ts' && $stored !== null;
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $invalidated = ReadSetInvalidator::invalidated($cached, $discovered, $probe);

        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        self::assertCount(250, $invalidated);
        self::assertArrayHasKey('src/g3/f000.ts', $invalidated);
    }

    #[Group('scan')]
    public function testNothingChangedInvalidatesNothing(): void
    {
        $cached = self::cached([
            'x' => self::row('x', ['b' => self::hash('b'), 'gone' => null]),
            'b' => self::row('b', []),
        ]);

        assertSame([], ReadSetInvalidator::invalidated($cached, self::discovered(['x', 'b']), self::noProbe()));
    }

    #[Group('scan')]
    public function testAnUndiscoveredPathIsProbedOncePerCall(): void
    {
        $cached = self::cached([
            'p' => self::row('p', ['vendor/a.php' => self::hash('a')]),
            'q' => self::row('q', ['vendor/a.php' => self::hash('a')]),
        ]);
        $calls = 0;
        $probe = static function (string $path, ?string $stored) use (&$calls): bool {
            ++$calls;

            return $stored === self::hash('a');
        };

        assertSame([], ReadSetInvalidator::invalidated($cached, self::discovered(['p', 'q']), $probe));
        assertSame(1, $calls);
    }

    #[Group('scan')]
    public function testLoadRoundTripsOneRowOfEachTable(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pdo->exec("INSERT INTO contribution_cache (project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at, read_attribution, read_group)
            VALUES ('{$ids['project']}', 'a.ts', 'a.ts', '" . self::hash('a') . "', 'knossos.typescript', '1', 'c', '{}', 'now', 1, 'G')");
        $pdo->exec("INSERT INTO contribution_reads (project_id, owner_key, read_path, read_hash) VALUES ('{$ids['project']}', 'a.ts', 'b.ts', NULL)");
        $pdo->exec("INSERT INTO contribution_read_groups (project_id, group_id, read_path, read_hash) VALUES ('{$ids['project']}', 'G', 'tsconfig.json', '" . self::hash('t') . "')");

        $loaded = CachedReads::load($pdo, $ids['project']);

        assertSame(['a.ts' => ['scanner_id' => 'knossos.typescript', 'file_path' => 'a.ts', 'content_hash' => self::hash('a'), 'scanner_version' => '1', 'configuration_hash' => 'c', 'read_attribution' => true, 'read_group' => 'G']], $loaded->rows);
        assertSame(['a.ts' => ['b.ts' => null]], $loaded->ownerReads);
        assertSame(['G' => ['tsconfig.json' => self::hash('t')]], $loaded->groupReads);
        assertSame([], CachedReads::load($pdo, 'another-project')->rows);
        $withoutReads = CachedReads::load($pdo, $ids['project'], false);
        assertSame($loaded->rows, $withoutReads->rows);
        assertSame([], $withoutReads->ownerReads);
        assertSame([], $withoutReads->groupReads);
        assertSame(["knossos.typescript\0a.ts" => ['owner_key' => 'a.ts'] + $loaded->rows['a.ts']], $loaded->byScannerPath());
    }

    /** @param array<string, array{scanner_id: string, file_path: string, content_hash: string, read_attribution: bool, read_group: ?string, reads: array<string, ?string>}> $rows */
    private static function cached(array $rows): CachedReads
    {
        $reads = [];
        foreach ($rows as $owner => $row) {
            $reads[$owner] = $row['reads'];
            unset($rows[$owner]['reads']);
        }

        return new CachedReads($rows, $reads, []);
    }

    /**
     * @param array<string, ?string> $reads
     * @return array{scanner_id: string, file_path: string, content_hash: string, read_attribution: bool, read_group: ?string, reads: array<string, ?string>}
     */
    private static function row(string $path, array $reads, string $scanner = 'knossos.php', bool $attributed = true, ?string $group = null): array
    {
        return ['scanner_id' => $scanner, 'file_path' => $path, 'content_hash' => self::hash($path), 'read_attribution' => $attributed, 'read_group' => $group, 'reads' => $reads];
    }

    /**
     * Every path at the hash its row was cached with.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private static function discovered(array $paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = self::hash($path);
        }

        return $hashes;
    }

    /**
     * Every undiscovered path is absent: only a stored miss still matches.
     *
     * @return callable(string, ?string): bool
     */
    private static function noProbe(): callable
    {
        return static fn(string $path, ?string $stored): bool => $stored === null;
    }

    /**
     * @param array<string, true> $invalidated
     * @return list<string>
     */
    private static function sortedKeys(array $invalidated): array
    {
        $keys = array_map('strval', array_keys($invalidated));
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
