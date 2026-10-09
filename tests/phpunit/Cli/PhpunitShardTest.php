<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * CI runs the PHPUnit suite once, split over several coverage shards, and a
 * merge job enforces the floors from the combined data. A shard that silently
 * drops a test file lowers the coverage it reports without failing anything,
 * so the selector has to cover the whole suite, exactly once, the same way on
 * every runner, and hand each shard the same PHPUnit settings the full suite
 * runs with.
 */
final class PhpunitShardTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/knossos-phpunit-shard-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    /** For every shard count, the shards are disjoint, together hold every file, and none is empty. */
    public function testShardsPartitionTheSuiteForEveryCount(): void
    {
        $files = $this->fixtureFiles(23);
        $list = $this->writeList($files);

        foreach (range(1, 6) as $count) {
            $seen = [];
            foreach (range(1, $count) as $shard) {
                $selected = $this->selectedFiles($list, $shard, $count);
                assertNotSame([], $selected, "shard {$shard}/{$count} is empty");
                foreach ($selected as $file) {
                    assertTrue(!isset($seen[$file]), "{$file} is in two shards of {$count}");
                    $seen[$file] = $shard;
                }
            }
            $union = array_keys($seen);
            sort($union, SORT_STRING);
            assertSame($this->sorted($files), $union, "the {$count} shards do not hold the whole suite");
        }
    }

    /** Fewer files than shards still assigns every file once; only the surplus shards are empty. */
    public function testMoreShardsThanFilesStillCoversEveryFile(): void
    {
        $files = $this->fixtureFiles(3);
        $list = $this->writeList($files);

        $selected = [];
        foreach (range(1, 6) as $shard) {
            $selected = [...$selected, ...$this->selectedFiles($list, $shard, 6)];
        }
        assertSame($this->sorted($files), $this->sorted($selected));
    }

    /** The assignment depends only on the file names: input order and repeated runs change nothing. */
    public function testAssignmentIsDeterministic(): void
    {
        $files = $this->fixtureFiles(17);
        $forward = $this->writeList($files, 'forward.txt');
        $reversed = $this->writeList(array_reverse($files), 'reversed.txt');

        $first = $this->shard(['--list=4', '--files-from=' . $forward]);
        assertSame($first, $this->shard(['--list=4', '--files-from=' . $forward]));
        assertSame($first, $this->shard(['--list=4', '--files-from=' . $reversed]));
    }

    /** `--list` names every file with its shard, agreeing with what each shard's config runs. */
    public function testListAgreesWithTheGeneratedConfigs(): void
    {
        $files = $this->fixtureFiles(11);
        $list = $this->writeList($files);

        $listed = [];
        foreach (array_filter(explode("\n", $this->shard(['--list=3', '--files-from=' . $list]))) as $line) {
            [$file, $shard] = explode("\t", $line);
            $listed[$shard][] = $file;
        }
        assertCount(3, $listed);
        foreach (range(1, 3) as $shard) {
            assertSame($listed[(string) $shard], $this->selectedFiles($list, $shard, 3));
        }
    }

    /** Neighbouring files land in different shards, so a directory of slow tests is spread out. */
    public function testNeighbouringFilesAreSpreadAcrossShards(): void
    {
        $list = $this->writeList($this->fixtureFiles(8));

        $shards = [];
        foreach (array_filter(explode("\n", $this->shard(['--list=4', '--files-from=' . $list]))) as $line) {
            $shards[] = (int) explode("\t", $line)[1];
        }
        assertSame([1, 2, 3, 4, 1, 2, 3, 4], $shards);
    }

    /** A shard's config runs with the suite's own bootstrap, strictness, memory limit and source set. */
    public function testGeneratedConfigKeepsTheSuiteSettings(): void
    {
        $root = self::repositoryRoot();
        $list = $this->writeList($this->fixtureFiles(5));
        $out = $this->directory . '/shard.xml';
        $this->shard(['--shard=2/3', '--files-from=' . $list, '--out=' . $out]);

        $original = $this->load($root . '/phpunit.xml');
        $generated = $this->load($out);
        $phpunit = $generated->documentElement;
        assertInstanceOf(DOMElement::class, $phpunit);
        $originalRoot = $original->documentElement;
        assertInstanceOf(DOMElement::class, $originalRoot);

        assertSame($root . '/' . $originalRoot->getAttribute('bootstrap'), $phpunit->getAttribute('bootstrap'));
        assertSame('true', $phpunit->getAttribute('failOnRisky'));
        assertSame('true', $phpunit->getAttribute('failOnWarning'));
        assertSame($originalRoot->getAttribute('failOnRisky'), $phpunit->getAttribute('failOnRisky'));
        assertSame($originalRoot->getAttribute('failOnWarning'), $phpunit->getAttribute('failOnWarning'));

        $xpath = new DOMXPath($generated);
        $originalXpath = new DOMXPath($original);
        assertSame(
            $originalXpath->evaluate('string(/phpunit/php/ini[@name="memory_limit"]/@value)'),
            $xpath->evaluate('string(/phpunit/php/ini[@name="memory_limit"]/@value)'),
        );
        $sources = [];
        foreach ($xpath->query('/phpunit/source/include/directory') ?: [] as $directory) {
            $sources[] = $directory->textContent;
        }
        $originalSources = [];
        foreach ($originalXpath->query('/phpunit/source/include/directory') ?: [] as $directory) {
            $originalSources[] = $root . '/' . $directory->textContent;
        }
        assertNotSame([], $originalSources);
        assertSame($originalSources, $sources);

        assertSame(1.0, $xpath->evaluate('count(/phpunit/testsuites/testsuite)'));
        assertSame(0.0, $xpath->evaluate('count(/phpunit/testsuites/testsuite/directory)'));
        $selected = [];
        foreach ($xpath->query('/phpunit/testsuites/testsuite/file') ?: [] as $file) {
            $selected[] = $file->textContent;
        }
        assertSame(array_map(static fn(string $file): string => $root . '/' . $file, $this->selectedFiles($list, 2, 3)), $selected);
    }

    /** Malformed arguments stop the selector before it writes anything. */
    public function testRejectsMalformedArguments(): void
    {
        $list = $this->writeList($this->fixtureFiles(3));
        foreach ([
            ['--shard=0/3', '--files-from=' . $list, '--out=' . $this->directory . '/x.xml'],
            ['--shard=4/3', '--files-from=' . $list, '--out=' . $this->directory . '/x.xml'],
            ['--shard=1/0', '--files-from=' . $list, '--out=' . $this->directory . '/x.xml'],
            ['--shard=a/b', '--files-from=' . $list, '--out=' . $this->directory . '/x.xml'],
            ['--shard=1/3', '--files-from=' . $list],
            ['--list=0', '--files-from=' . $list],
            ['--files-from=' . $list],
            ['--shard=1/2', '--files-from=' . $this->directory . '/missing.txt', '--out=' . $this->directory . '/x.xml'],
            ['--bogus'],
        ] as $arguments) {
            [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/phpunit-shard', ...$arguments]);
            assertNotSame(0, $exit, implode(' ', $arguments) . ' was accepted');
            assertStringContainsString('phpunit-shard', $errors);
        }
        assertTrue(!is_file($this->directory . '/x.xml'), 'a rejected run wrote a config');
    }

    /** Without a list file the selector asks PHPUnit for the suite, and covers every test file in the tree. */
    public function testDefaultListIsTheWholeSuite(): void
    {
        $root = self::repositoryRoot();
        $listed = [];
        foreach (array_filter(explode("\n", $this->shard(['--list=4']))) as $line) {
            $listed[] = explode("\t", $line)[0];
        }

        $expected = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests/phpunit', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
                $expected[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        assertSame($this->sorted($expected), $listed);
    }

    /** @return list<string> */
    private function fixtureFiles(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; ++$index) {
            $files[] = sprintf('tests/phpunit/Dir%d/Case%02dTest.php', $index % 3, $index);
        }

        return $files;
    }

    /** @param list<string> $files */
    private function writeList(array $files, string $name = 'files.txt'): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, implode("\n", $files) . "\n");

        return $path;
    }

    /**
     * The files shard $shard of $count runs, read back from its generated config.
     *
     * @return list<string>
     */
    private function selectedFiles(string $list, int $shard, int $count): array
    {
        $out = $this->directory . "/shard-{$shard}-of-{$count}.xml";
        $this->shard(["--shard={$shard}/{$count}", '--files-from=' . $list, '--out=' . $out]);
        $xpath = new DOMXPath($this->load($out));
        $prefix = self::repositoryRoot() . '/';
        $files = [];
        foreach ($xpath->query('/phpunit/testsuites/testsuite/file') ?: [] as $file) {
            $files[] = substr($file->textContent, strlen($prefix));
        }

        return $files;
    }

    /** @param list<string> $arguments */
    private function shard(array $arguments): string
    {
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/phpunit-shard', ...$arguments]);
        assertSame(0, $exit, $errors);

        return $output;
    }

    private function load(string $path): DOMDocument
    {
        $document = new DOMDocument();
        assertTrue($document->load($path), "{$path} is not XML");

        return $document;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function sorted(array $files): array
    {
        usort($files, strcmp(...));

        return $files;
    }
}
