<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CachedReads;
use Knossos\Scan\ReadSetInvalidator;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

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

        assertSame(['a.ts' => ['scanner_id' => 'knossos.typescript', 'file_path' => 'a.ts', 'content_hash' => self::hash('a'), 'read_attribution' => true, 'read_group' => 'G']], $loaded->rows);
        assertSame(['a.ts' => ['b.ts' => null]], $loaded->ownerReads);
        assertSame(['G' => ['tsconfig.json' => self::hash('t')]], $loaded->groupReads);
        assertSame([], CachedReads::load($pdo, 'another-project')->rows);
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
