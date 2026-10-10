<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A cached Python contribution is keyed on the worker's own files, so every
 * module the worker loads must be one of its analysis inputs: an edit to a
 * file outside them would leave stale facts in the cache.
 */
#[Group('scan')]
final class LanguageDescriptorAnalysisInputsTest extends KnossosTestCase
{
    public function testEveryPythonWorkerModuleIsAnAnalysisInput(): void
    {
        $python = null;
        foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
            if ($descriptor->key === 'python') {
                $python = $descriptor;
            }
        }
        self::assertNotNull($python);

        $bin = self::repositoryRoot() . '/workers/python/bin';
        $modules = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($bin, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $entry): bool => !$entry->isDir() || $entry->getFilename() !== '__pycache__',
        ));
        $checked = 0;
        foreach ($modules as $module) {
            if (!$module->isFile() || $module->getExtension() !== 'py') {
                continue;
            }
            $relative = substr($module->getPathname(), strlen(self::repositoryRoot()) + 1);
            $covered = false;
            foreach ($python->analysisInputs as $pattern) {
                $covered = $covered || $pattern === $relative
                    || (str_ends_with($pattern, '/**') && str_starts_with($relative, substr($pattern, 0, -2)));
            }
            self::assertTrue($covered, $relative . ' is not an analysis input of the Python worker');
            ++$checked;
        }

        self::assertGreaterThan(1, $checked);
    }
}
