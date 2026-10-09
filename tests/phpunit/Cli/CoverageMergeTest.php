<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The merge job enforces the coverage floors from the shards' combined data.
 * A shard that never ran, ran the wrong files, or lost one reports lower
 * coverage without failing anything, so the merge has to refuse unless every
 * test file ran in exactly one shard, and it must not let one shard's data
 * overwrite another's.
 */
final class CoverageMergeTest extends KnossosTestCase
{
    private const FILES = [
        'tests/phpunit/A/OneTest.php',
        'tests/phpunit/A/TwoTest.php',
        'tests/phpunit/B/ThreeTest.php',
        'tests/phpunit/B/FourTest.php',
        'tests/phpunit/C/FiveTest.php',
    ];

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/knossos-coverage-merge-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/raw', 0o700, true);
        mkdir($this->directory . '/into', 0o700, true);
        file_put_contents($this->directory . '/files.txt', implode("\n", self::FILES) . "\n");
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    /** Complete shards merge, and per-process files with the same name in two shards both survive. */
    public function testCompleteShardsMergeWithoutOverwritingEachOther(): void
    {
        $this->writeShards(3);

        [$exit, $output, $errors] = $this->merge();

        assertSame(0, $exit, $errors);
        assertStringContainsString('3 shards, 5 test files each run once', $output);
        foreach ([1, 2, 3] as $shard) {
            assertFileExists($this->directory . "/into/php/pcov-s{$shard}-7.json");
            assertFileExists($this->directory . "/into/js/tmp/s{$shard}-coverage-7-1-0.json");
            assertFileExists($this->directory . "/into/python/.coverage.host{$shard}.7.abc");
            assertFileExists($this->directory . "/into/junit/shard-{$shard}.xml");
            assertSame("{\"shard\":{$shard}}", file_get_contents($this->directory . "/into/php/pcov-s{$shard}-7.json"));
        }
    }

    /** @return iterable<string, array{0: callable(self): void, 1: string}> */
    public static function brokenShards(): iterable
    {
        yield 'a shard never finished' => [
            static fn(self $test) => unlink($test->directory . '/raw/coverage-shard-2/shard.json'),
            'coverage-shard-2 has no valid shard.json',
        ];
        yield 'a shard is missing' => [
            static fn(self $test) => $test->runFixtureCommand(['rm', '-rf', $test->directory . '/raw/coverage-shard-3']),
            'shard 3 of 3 is missing',
        ];
        yield 'the shards disagree on N' => [
            static fn(self $test) => file_put_contents($test->directory . '/raw/coverage-shard-3/shard.json', '{"shard": 3, "of": 4}'),
            'disagree on the shard count',
        ];
        yield 'a shard index appears twice' => [
            static fn(self $test) => file_put_contents($test->directory . '/raw/coverage-shard-3/shard.json', '{"shard": 2, "of": 3}'),
            'shard 2 appears twice',
        ];
        yield 'a shard left no PHP data' => [
            static fn(self $test) => unlink($test->directory . '/raw/coverage-shard-1/php/pcov-7.json'),
            'shard 1 left no PHP coverage data',
        ];
        yield 'a test file ran nowhere' => [
            static fn(self $test) => $test->writeJunit(1, ['tests/phpunit/A/OneTest.php']),
            'tests/phpunit/B/ThreeTest.php ran in no shard (assigned to shard 1)',
        ];
        yield 'a test file ran twice' => [
            static fn(self $test) => $test->writeJunit(2, ['tests/phpunit/A/TwoTest.php', 'tests/phpunit/C/FiveTest.php', 'tests/phpunit/B/FourTest.php']),
            'tests/phpunit/B/FourTest.php ran in shards 2 and 3, not once',
        ];
        yield 'a test file ran in the wrong shard' => [
            static function (self $test): void {
                $test->writeJunit(3, []);
                $test->writeJunit(2, ['tests/phpunit/A/TwoTest.php', 'tests/phpunit/C/FiveTest.php', 'tests/phpunit/B/FourTest.php']);
            },
            'tests/phpunit/B/FourTest.php ran in shard 2, but the selector assigned it to shard 3',
        ];
        yield 'a shard ran a file the suite does not have' => [
            static fn(self $test) => $test->writeJunit(3, ['tests/phpunit/B/FourTest.php', 'tests/phpunit/D/StrayTest.php']),
            'tests/phpunit/D/StrayTest.php ran in shard 3 but is not a test file of this suite',
        ];
        yield 'a shard has no JUnit log' => [
            static fn(self $test) => unlink($test->directory . '/raw/coverage-shard-3/junit.xml'),
            'shard 3 has no readable junit.xml',
        ];
    }

    /** @param callable(self): void $break */
    #[DataProvider('brokenShards')]
    public function testRefusesIncompleteOrInconsistentShards(callable $break, string $reason): void
    {
        // Sorted, the five files deal out as 1: One, Three; 2: Two, Five; 3: Four.
        $this->writeShards(3);
        $break($this);

        [$exit, , $errors] = $this->merge();

        assertNotSame(0, $exit, 'the merge accepted broken shards');
        assertStringContainsString('refusing to merge', $errors);
        assertStringContainsString($reason, $errors);
        assertFileDoesNotExist($this->directory . '/into/php/pcov-s1-7.json', 'a refused merge copied data');
    }

    /** An empty download, or none at all, is refused rather than reported as zero shards. */
    public function testRefusesWhenThereAreNoShards(): void
    {
        [$exit, , $errors] = $this->merge();
        assertNotSame(0, $exit);
        assertStringContainsString('holds no shard directories', $errors);

        $this->runFixtureCommand(['rm', '-rf', $this->directory . '/raw']);
        [$exit, , $errors] = $this->merge();
        assertNotSame(0, $exit);
        assertStringContainsString('is not a directory of shard results', $errors);
    }

    /** Lay out $count complete shards the way the artifact download does, each file in its assigned shard. */
    private function writeShards(int $count): void
    {
        [, $listing] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/phpunit-shard', "--list={$count}", '--files-from=' . $this->directory . '/files.txt']);
        $assigned = [];
        foreach (array_filter(explode("\n", $listing)) as $line) {
            [$file, $shard] = explode("\t", $line);
            $assigned[(int) $shard][] = $file;
        }
        foreach (range(1, $count) as $shard) {
            $base = $this->directory . "/raw/coverage-shard-{$shard}";
            mkdir($base . '/php', 0o777, true);
            mkdir($base . '/js/tmp', 0o777, true);
            mkdir($base . '/python', 0o777, true);
            file_put_contents($base . '/shard.json', json_encode(['shard' => $shard, 'of' => $count]));
            // The same process id in every shard, as containers produce.
            file_put_contents($base . '/php/pcov-7.json', "{\"shard\":{$shard}}");
            file_put_contents($base . '/js/tmp/coverage-7-1-0.json', '{}');
            file_put_contents($base . "/python/.coverage.host{$shard}.7.abc", '');
            $this->writeJunit($shard, $assigned[$shard] ?? []);
        }
    }

    /** @param list<string> $files */
    private function writeJunit(int $shard, array $files): void
    {
        $suites = '';
        foreach ($files as $file) {
            $suites .= sprintf('<testsuite name="%s" file="%s/%s" tests="1"/>', basename($file, '.php'), self::repositoryRoot(), $file);
        }
        file_put_contents(
            $this->directory . "/raw/coverage-shard-{$shard}/junit.xml",
            '<?xml version="1.0"?><testsuites><testsuite name="shard">' . $suites . '</testsuite></testsuites>',
        );
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function merge(): array
    {
        return $this->runFixtureCommandOutput([
            PHP_BINARY,
            self::repositoryRoot() . '/tools/coverage-merge.php',
            $this->directory . '/raw',
            '--into=' . $this->directory . '/into',
            '--files-from=' . $this->directory . '/files.txt',
        ]);
    }
}
