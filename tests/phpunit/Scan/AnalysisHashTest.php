<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\AnalysisHash;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The cache key follows the bytes of the worker's own files.
 */
#[Group('scan')]
final class AnalysisHashTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-analysis-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/__tests__', 0o777, true);
        mkdir($this->root . '/src/tests', 0o777, true);
        mkdir($this->root . '/src/node_modules/dep', 0o777, true);
        mkdir($this->root . '/src/__pycache__', 0o777, true);
        mkdir($this->root . '/bin', 0o777, true);
        file_put_contents($this->root . '/src/a.js', 'a');
        file_put_contents($this->root . '/src/b.js', 'b');
        file_put_contents($this->root . '/bin/worker', 'w');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Group('scan')]
    public function testTheSameFilesGiveTheSameHashAndAnEditChangesIt(): void
    {
        $before = AnalysisHash::of($this->root, ['src/**', 'bin/worker']);

        assertSame($before, AnalysisHash::of($this->root, ['src/**', 'bin/worker']));

        file_put_contents($this->root . '/src/a.js', 'A');

        self::assertNotSame($before, AnalysisHash::of($this->root, ['src/**', 'bin/worker']));
    }

    #[Group('scan')]
    public function testTestAndDependencyDirectoriesDoNotAffectIt(): void
    {
        $before = AnalysisHash::of($this->root, ['src/**']);

        foreach (['src/__tests__/t.js', 'src/tests/t.js', 'src/node_modules/dep/index.js', 'src/__pycache__/a.cpython-312.pyc'] as $ignored) {
            file_put_contents($this->root . '/' . $ignored, 'changed');
        }

        assertSame($before, AnalysisHash::of($this->root, ['src/**']));
    }

    #[Group('scan')]
    public function testANestedFileCountsAndAMissingPathIsStableAndDiffersFromAnEmptyFile(): void
    {
        $tree = AnalysisHash::of($this->root, ['src/**']);
        mkdir($this->root . '/src/deep', 0o777, true);
        file_put_contents($this->root . '/src/deep/c.js', 'c');
        self::assertNotSame($tree, AnalysisHash::of($this->root, ['src/**']));

        $missing = AnalysisHash::of($this->root, ['bin/absent']);
        assertSame($missing, AnalysisHash::of($this->root, ['bin/absent']));

        file_put_contents($this->root . '/bin/absent', '');
        self::assertNotSame($missing, AnalysisHash::of($this->root, ['bin/absent']));
    }

    /** A long-running process sees a worker file edited between two calls. */
    #[Group('scan')]
    public function testAnEditBetweenTwoCallsInOneProcessChangesTheHash(): void
    {
        $first = AnalysisHash::of($this->root, ['src/**']);
        file_put_contents($this->root . '/src/a.js', 'edited');

        self::assertNotSame($first, AnalysisHash::of($this->root, ['src/**']));
    }

    #[Group('scan')]
    public function testPatternsWithoutAnInstallationRootAreRefused(): void
    {
        self::assertThrowsWith(fn() => AnalysisHash::of('', ['src/**']), \InvalidArgumentException::class);
        assertSame(AnalysisHash::of('', []), AnalysisHash::of('', []));
    }
}
