<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every bound the project configuration advertises, at the bound itself.
 *
 * ProjectConfigurationLoader scored 78% under mutation testing, and the pattern
 * in what survived is consistent: the existing tests refuse values well outside
 * each limit and never accept the limit itself, so every comparison could move
 * by one and stay green. A limit is only pinned by the pair, the largest value
 * that is allowed and the smallest that is not.
 */
final class ProjectConfigurationBoundsTest extends KnossosTestCase
{
    /** Each numeric limit is accepted at both ends of its range and refused one step outside. */
    #[Group('config')]
    public function testEveryNumericLimitIsAcceptedAtItsBoundsAndRefusedOutside(): void
    {
        foreach ([
            ['limits' => ['max_files' => 1]],
            ['limits' => ['max_files' => 100_000]],
            ['limits' => ['max_file_bytes' => 1]],
            ['limits' => ['max_file_bytes' => 100_000_000]],
            ['limits' => ['worker_memory_mb' => 64]],
            ['limits' => ['worker_memory_mb' => 65_536]],
            ['snapshot_retention' => 0],
            ['snapshot_retention' => 20],
        ] as $inside) {
            self::assertLoads($inside);
        }

        foreach ([
            ['limits' => ['max_files' => 0]],
            ['limits' => ['max_files' => 100_001]],
            ['limits' => ['max_file_bytes' => 0]],
            ['limits' => ['max_file_bytes' => 100_000_001]],
            ['limits' => ['worker_memory_mb' => 63]],
            ['limits' => ['worker_memory_mb' => 65_537]],
            ['snapshot_retention' => -1],
            ['snapshot_retention' => 21],
        ] as $outside) {
            self::assertRefused($outside);
        }
    }

    /** Each bounded list accepts exactly its limit and refuses one entry more. */
    #[Group('config')]
    public function testEveryListCapAcceptsItsLimitAndRefusesOneMore(): void
    {
        // Framework hints are validated against the allow-list before they are
        // de-duplicated, so twenty of the same name is twenty entries.
        self::assertLoads(['frameworks' => array_fill(0, 20, 'react')]);
        self::assertRefused(['frameworks' => array_fill(0, 21, 'react')]);

        self::assertLoads(['boundaries' => self::boundaries(50)]);
        self::assertRefused(['boundaries' => self::boundaries(51)]);

        self::assertLoads(['policies' => self::policies(50)]);
        self::assertRefused(['policies' => self::policies(51)]);

        self::assertLoads(['dead_code_suppressions' => array_fill(0, 200, 'src/Legacy.php')]);
        self::assertRefused(['dead_code_suppressions' => array_fill(0, 201, 'src/Legacy.php')]);
    }

    /** An ignore pattern of exactly five hundred bytes is allowed. */
    #[Group('config')]
    public function testAnIgnorePatternOfExactlyFiveHundredBytesIsAllowed(): void
    {
        self::assertLoads(['ignores' => [str_repeat('a', 500)]]);
        self::assertRefused(['ignores' => [str_repeat('a', 501)]]);
    }

    /** A quality budget of exactly a hundred thousand is allowed. */
    #[Group('config')]
    public function testAQualityBudgetOfExactlyAHundredThousandIsAllowed(): void
    {
        self::assertLoads(['quality_budgets' => ['new_cycles' => 100_000]]);
        self::assertLoads(['quality_budgets' => ['new_cycles' => 0]]);
        self::assertRefused(['quality_budgets' => ['new_cycles' => 100_001]]);
        self::assertRefused(['quality_budgets' => ['new_cycles' => -1]]);
    }

    /**
     * Traversal is checked after separators are normalised, so a backslash
     * cannot smuggle a parent segment past the guard.
     */
    #[Group('config')]
    public function testTraversalIsCheckedAfterSeparatorsAreNormalised(): void
    {
        self::assertRefused(['ignores' => ['src\\..\\..\\etc']]);
        self::assertRefused(['boundaries' => [['name' => 'B', 'path_prefix' => 'src\\..\\..\\etc']]]);
    }

    /** A misspelled boundary key is reported rather than ignored. */
    #[Group('config')]
    public function testAnUnknownBoundaryKeyIsRefused(): void
    {
        self::assertRefused(['boundaries' => [['name' => 'B', 'path_prefix' => 'src', 'pathPrefix' => 'src']]]);
    }

    /** A policy needs both an id and a from_boundary, and neither may be empty. */
    #[Group('config')]
    public function testAPolicyNeedsBothAnIdAndAFromBoundary(): void
    {
        self::assertLoads(['policies' => [['id' => 'p', 'from_boundary' => 'core', 'deny_targets' => ['web']]]]);
        self::assertRefused(['policies' => [['id' => '', 'from_boundary' => 'core', 'deny_targets' => ['web']]]]);
        self::assertRefused(['policies' => [['id' => 'p', 'from_boundary' => '', 'deny_targets' => ['web']]]]);
        self::assertRefused(['policies' => [['id' => 'p', 'from_boundary' => 'core']]]);
    }

    /** @return list<array<string, string>> */
    private static function boundaries(int $count): array
    {
        return array_map(
            static fn(int $index): array => ['name' => 'B' . $index, 'path_prefix' => 'src/' . $index],
            range(1, $count),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function policies(int $count): array
    {
        return array_map(
            static fn(int $index): array => ['id' => 'p' . $index, 'from_boundary' => 'core', 'deny_targets' => ['web']],
            range(1, $count),
        );
    }

    /** @param array<string, mixed> $configuration */
    private static function assertLoads(array $configuration): void
    {
        $root = self::writeConfiguration($configuration);
        try {
            // The file name, not merely a configuration object: a loader that
            // silently fell back to defaults would return one of those too.
            assertSame('knossos.json', ProjectConfigurationLoader::load($root, [$root])->path, json_encode($configuration));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @param array<string, mixed> $configuration */
    private static function assertRefused(array $configuration): void
    {
        $root = self::writeConfiguration($configuration);
        try {
            assertThrows(
                static fn() => ProjectConfigurationLoader::load($root, [$root]),
                DiscoveryException::class,
                json_encode($configuration),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @param array<string, mixed> $configuration */
    private static function writeConfiguration(array $configuration): string
    {
        $root = sys_get_temp_dir() . '/knossos-config-bounds-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        file_put_contents(
            $root . '/knossos.json',
            (string) json_encode(['version' => 1] + $configuration, JSON_THROW_ON_ERROR),
        );

        return $root;
    }
}
