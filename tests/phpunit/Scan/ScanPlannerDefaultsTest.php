<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use InvalidArgumentException;
use Knossos\Scan\ScanPlanner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a scan falls back to when nothing is configured, and where retention
 * stops being acceptable.
 *
 * ScanPlanner scored 69% under mutation testing. Nothing asserted the values it
 * falls back to when neither the caller nor a configuration file supplies one,
 * so all three could change silently, and the retention range was only ever
 * tested from outside, so its upper bound could move by one.
 */
final class ScanPlannerDefaultsTest extends KnossosTestCase
{
    /** With no caller limits and no configuration file, the built-in defaults apply. */
    #[Group('scan')]
    public function testTheBuiltInDefaultsApplyWhenNothingIsConfigured(): void
    {
        $root = self::emptyRoot();
        try {
            $preparation = $this->planner()->prepare($root, null, null, null, null, null, null);

            assertSame(100_000, $preparation->maxFiles);
            assertSame(2_000_000, $preparation->maxFileBytes);
            assertSame(5, $preparation->snapshotRetention);
        } finally {
            self::removeRoot($root);
        }
    }

    /** A configuration file's limits are preferred to the built-in defaults. */
    #[Group('scan')]
    public function testAConfiguredLimitIsPreferredToTheDefault(): void
    {
        $root = self::emptyRoot();
        file_put_contents($root . '/knossos.json', (string) json_encode([
            'version' => 1,
            'limits' => ['max_files' => 7, 'max_file_bytes' => 4_096],
            'snapshot_retention' => 2,
        ], JSON_THROW_ON_ERROR));
        try {
            $preparation = $this->planner()->prepare($root, null, null, null, null, null, null);

            assertSame(7, $preparation->maxFiles);
            assertSame(4_096, $preparation->maxFileBytes);
            assertSame(2, $preparation->snapshotRetention);
        } finally {
            self::removeRoot($root);
        }
    }

    /** The caller's own limits win over both the file and the defaults. */
    #[Group('scan')]
    public function testTheCallersLimitsWinOverEverything(): void
    {
        $root = self::emptyRoot();
        file_put_contents($root . '/knossos.json', (string) json_encode([
            'version' => 1,
            'limits' => ['max_files' => 7],
        ], JSON_THROW_ON_ERROR));
        try {
            $preparation = $this->planner()->prepare($root, 9, 8_192, null, null, 3, null);

            assertSame(9, $preparation->maxFiles);
            assertSame(8_192, $preparation->maxFileBytes);
            assertSame(3, $preparation->snapshotRetention);
        } finally {
            self::removeRoot($root);
        }
    }

    /** Retention is accepted at both ends of its range and refused one step outside. */
    #[Group('scan')]
    public function testTheRetentionRangeIsAcceptedAtBothEnds(): void
    {
        $root = self::emptyRoot();
        try {
            $planner = $this->planner();

            assertSame(0, $planner->prepare($root, null, null, null, null, 0, null)->snapshotRetention);
            assertSame(20, $planner->prepare($root, null, null, null, null, 20, null)->snapshotRetention);

            foreach ([-1, 21] as $outside) {
                $error = captureThrows(
                    static fn() => $planner->prepare($root, null, null, null, null, $outside, null),
                    InvalidArgumentException::class,
                );
                assertSame('snapshot_retention must be between 0 and 20.', $error->getMessage());
            }
        } finally {
            self::removeRoot($root);
        }
    }

    /**
     * A package.json is part of what the TypeScript configuration hash covers,
     * so editing one invalidates cached TypeScript contributions.
     */
    #[Group('scan')]
    public function testEditingAPackageManifestChangesTheTypescriptHash(): void
    {
        $root = self::emptyRoot();
        try {
            file_put_contents($root . '/package.json', '{"name":"before"}');
            $before = $this->planner()->prepare($root, null, null, null, null, null, null)->configurationHashes['typescript'];

            file_put_contents($root . '/package.json', '{"name":"after"}');
            $after = $this->planner()->prepare($root, null, null, null, null, null, null)->configurationHashes['typescript'];

            assertSame(false, $before === $after, 'A package.json edit must change the TypeScript configuration hash.');
        } finally {
            @unlink($root . '/package.json');
            self::removeRoot($root);
        }
    }

    private function planner(): ScanPlanner
    {
        return new ScanPlanner($this->freshTestDatabase(), [sys_get_temp_dir()]);
    }

    private static function emptyRoot(): string
    {
        $root = sys_get_temp_dir() . '/knossos-planner-defaults-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);

        return $root;
    }

    private static function removeRoot(string $root): void
    {
        @unlink($root . '/knossos.json');
        @rmdir($root);
    }
}
