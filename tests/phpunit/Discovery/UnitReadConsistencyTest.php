<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\FileContentReader;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Discovery\ProjectUnit;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A manifest's hash and its metadata have to describe the same bytes.
 *
 * They were taken from two separate reads of the same path, one to hash and
 * one to parse, with nothing tying them together. An edit landing between them
 * left the unit carrying the first read's hash beside metadata parsed from the
 * second read's content: `GraphReconciler` persisted that hash while
 * `ScanPlanner` and `ScanAnalysisPipeline` consumed the metadata. Restore the
 * file before the next probe and both oracles compare the restored bytes
 * against a stored hash they match, report nothing, and the graph goes on
 * answering from framework detection and entry points that never described
 * those bytes.
 *
 * Shown with a reader that answers a second read differently, because a
 * filesystem that changes underneath a running scan is the one thing a test
 * cannot arrange. The fake is the seam, not the assertion: what is asserted is
 * that the hash and the metadata agree, which they cannot do if the two reads
 * were ever allowed to differ.
 */
final class UnitReadConsistencyTest extends KnossosTestCase
{
    /**
     * What the scan's own read saw. Everything the unit records has to
     * describe these bytes and no others.
     */
    private const AS_READ = '{"name":"fixture/as-read","require":{"laravel/framework":"^11.0"}}';

    /**
     * What is on disk by the time anything reads the path again, which is what
     * the edit landing mid-scan left behind. Any value the scan records that
     * hashes to this came from a second read rather than from its own.
     */
    private const CHANGED_ON_DISK = '{"name":"fixture/changed","require":{"symfony/console":"^7.0"}}';

    /**
     * One read, and both the hash and the parse come out of it, so a manifest
     * that changed between two reads cannot produce a unit describing one
     * version by hash and the other by metadata.
     */
    #[Group('discovery')]
    public function testAManifestHashAndItsMetadataComeFromOneRead(): void
    {
        $root = $this->tempRootWithManifest();
        try {
            $reader = $this->readerAnsweringWhatTheScanSaw();

            $units = (new ProjectDiscoverer($this->config($root), $reader))->discover($root)->units;

            $unit = $this->composerUnit($units);
            self::assertSame(
                hash('sha256', self::AS_READ),
                $unit->contentHash,
                'The hash has to be of the bytes the metadata below was parsed from. A hash of what is on disk now is a hash of a second read, which is the defect.',
            );
            self::assertSame('fixture/as-read', $unit->metadata['name'], 'And the metadata has to come from those same bytes.');
            self::assertSame(1, $reader->reads, 'One read is what makes the two agree by construction rather than by luck.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The same single buffer feeds the `files` row for a path that is both a
     * manifest and a source file, so the graph cannot hold a file hash from
     * one read beside a unit parsed from another.
     */
    #[Group('discovery')]
    public function testAPathThatIsBothAManifestAndASourceFileAgreesWithItself(): void
    {
        $root = $this->tempRootWithManifest();
        // A tool config is read twice over: as an ordinary source module by
        // the language worker, and as a unit here for the paths it names. It
        // is the case where one path produces both kinds of record.
        file_put_contents($root . '/vite.config.ts', self::CHANGED_ON_DISK);
        try {
            $reader = $this->readerAnsweringWhatTheScanSaw();

            $discovery = (new ProjectDiscoverer($this->config($root), $reader))->discover($root);

            foreach ($discovery->files as $file) {
                if ($file->relativePath !== 'vite.config.ts') {
                    continue;
                }
                foreach ($discovery->units as $unit) {
                    if ($unit->configPath !== 'vite.config.ts') {
                        continue;
                    }
                    self::assertSame($file->contentHash, $unit->contentHash, 'One path read once is one hash, whichever record carries it.');
                    self::assertSame(
                        hash('sha256', self::AS_READ),
                        $file->contentHash,
                        "And that hash is the read's, not a fresh look at the disk: a `files` row fingerprinting its own second read is the same defect wearing the other hat.",
                    );

                    return;
                }
            }
            self::fail('The fixture must produce both a file row and a unit for vite.config.ts, or this test asserts nothing.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A manifest that cannot be read is a dropped unit and a diagnostic, not a failed scan. */
    #[Group('discovery')]
    public function testAnUnreadableManifestIsReportedRatherThanFailingTheScan(): void
    {
        $root = $this->tempRootWithManifest();
        try {
            $discovery = (new ProjectDiscoverer($this->config($root), $this->readerAnsweringNothing()))->discover($root);

            self::assertSame([], $discovery->units, 'Nothing was read, so nothing may be claimed about the manifest.');
            self::assertSame(
                ['DISCOVERY_CONFIG_UNREADABLE'],
                array_values(array_map(static fn(object $diagnostic): string => $diagnostic->code, $discovery->diagnostics)),
                'And the caller is told which file it was, rather than finding a unit silently missing.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A project root holding one manifest, which is all these assertions need. */
    private function tempRootWithManifest(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        // Deliberately not what the reader answers: a test whose disk agrees
        // with the read passes whether or not the two reads were ever unified,
        // which is a test that closes nothing.
        file_put_contents($root . '/composer.json', self::CHANGED_ON_DISK);

        return $root;
    }

    /** Discovery scoped to the temp root, with the defaults every other caller uses. */
    private function config(string $root): DiscoveryConfig
    {
        return new DiscoveryConfig([$root]);
    }

    /** The composer unit, failed on rather than assumed, so a fixture that stops producing one cannot pass silently. */
    private function composerUnit(array $units): ProjectUnit
    {
        foreach ($units as $unit) {
            if ($unit->kind === 'composer') {
                return $unit;
            }
        }
        self::fail('Discovery produced no composer unit, so there is nothing to assert about.');
    }

    /**
     * A reader answering what the scan's read saw, which is deliberately not
     * what is on disk: any second read of the same path sees the other bytes,
     * so a value derived from one is distinguishable from a value derived from
     * the other. That is the whole seam.
     *
     * It counts too, because "the two agree" and "there was only ever one
     * read" are different claims, and the second is what keeps the first true
     * as the code changes.
     */
    private function readerAnsweringWhatTheScanSaw(): object
    {
        return new class implements FileContentReader {
            public int $reads = 0;

            /** Answers the scanned bytes and records that it was asked. */
            public function read(string $absolutePath): ?string
            {
                ++$this->reads;

                return UnitReadConsistencyTest::asRead();
            }
        };
    }

    /** A reader standing in for a manifest that cannot be read at all. */
    private function readerAnsweringNothing(): FileContentReader
    {
        return new class implements FileContentReader {
            /** Fails the way an unreadable file does. */
            public function read(string $absolutePath): ?string
            {
                return null;
            }
        };
    }

    /** The bytes the scan's read saw, reachable from the anonymous reader class. */
    public static function asRead(): string
    {
        return self::AS_READ;
    }
}
