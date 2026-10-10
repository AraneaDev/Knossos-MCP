<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\AnalysisHash;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A cached Python contribution is keyed on the worker's own files, so every
 * module the worker loads must be one of its analysis inputs: an edit to a
 * file outside them would leave stale facts in the cache. Bytecode an
 * interpreter writes beside those modules decides nothing and must not count.
 */
#[Group('scan')]
final class LanguageDescriptorAnalysisInputsTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-stale-analysis-inputs-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/workers/python/bin', $this->root . '/workers/python/bin');
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
    }

    public function testAnEditToAPackageModuleChangesThePythonAnalysisHash(): void
    {
        $inputs = $this->pythonInputs();
        $package = $this->root . '/workers/python/bin/knossos_python';
        $modules = glob($package . '/*.py') ?: [];
        self::assertGreaterThan(1, count($modules));

        foreach ($modules as $module) {
            $before = AnalysisHash::of($this->root, $inputs);
            file_put_contents($module, "\n# edited\n", FILE_APPEND);

            self::assertNotSame($before, AnalysisHash::of($this->root, $inputs), basename($module));
        }
    }

    public function testBytecodeBesideThePackageLeavesThePythonAnalysisHashAlone(): void
    {
        $inputs = $this->pythonInputs();
        $cache = $this->root . '/workers/python/bin/knossos_python/__pycache__';
        $before = AnalysisHash::of($this->root, $inputs);

        @mkdir($cache, 0o777, true);
        file_put_contents($cache . '/collector.cpython-312.pyc', 'bytecode');
        assertSame($before, AnalysisHash::of($this->root, $inputs));

        file_put_contents($cache . '/collector.cpython-312.pyc', 'other bytecode');
        assertSame($before, AnalysisHash::of($this->root, $inputs));
    }

    /** @return list<string> */
    private function pythonInputs(): array
    {
        foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
            if ($descriptor->key === 'python') {
                return $descriptor->analysisInputs;
            }
        }
        self::fail('No Python descriptor.');
    }
}
