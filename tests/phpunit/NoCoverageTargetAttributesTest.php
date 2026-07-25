<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the repo-wide rule that no test under tests/phpunit/ may carry a
 * PHPUnit coverage-target attribute (`#[CoversClass]`, `#[CoversFunction]`,
 * `#[CoversMethod]`, `#[CoversTrait]`, `#[UsesClass]`, …).
 *
 * WHY, precisely — a single such attribute anywhere in the suite breaks
 * mutation testing for the ENTIRE repository, not just for its own class:
 *
 *  1. `vendor/bin/infection --filter=<file>` (how Chaos-MCP's PHP engine and
 *     .github/workflows/mutation.yml scope a run) makes Infection generate
 *     `phpunitConfiguration.initial.infection.xml` whose `<source><include>`
 *     is narrowed to that ONE file.
 *  2. Under that narrowed source scope every coverage target outside the
 *     filtered file is invalid, so PHPUnit emits the warning
 *     `Class X is not a valid target for code coverage`.
 *  3. Infection unconditionally injects `stopOnDefect="true"` into that same
 *     generated config (InitialConfigBuilder::build ->
 *     XmlConfigurationManipulator::setStopOnFailureOrDefect), and a warning is
 *     a "defect". PHPUnit halts mid-suite and exits non-zero.
 *  4. Infection reads that as "Project tests must be in a passing state" and
 *     aborts before producing any mutation results — with an error message
 *     that points at the coverage driver and the suite's health, i.e. at
 *     entirely the wrong thing.
 *
 * The suite is otherwise coverage-attribute-free by convention (see the class
 * docblocks in ExceptionsTest, NanoDtosTest, ScanDtoTest, …); this test turns
 * that convention into an enforced invariant so the failure mode above cannot
 * silently return.
 *
 * If you want targeted coverage attribution for a class, express it through the
 * `<source>` section of phpunit.xml, not through per-test attributes.
 */
#[Group('conventions')]
final class NoCoverageTargetAttributesTest extends KnossosTestCase
{
    /**
     * PHPUnit attributes that declare a coverage target. `Uses*` is included
     * because it is validated against the same source scope as `Covers*`.
     */
    private const FORBIDDEN_ATTRIBUTES = [
        'CoversClass',
        'CoversFunction',
        'CoversMethod',
        'CoversTrait',
        'CoversNamespace',
        'UsesClass',
        'UsesFunction',
        'UsesMethod',
        'UsesTrait',
    ];

    public function testNoTestFileDeclaresACoverageTargetAttribute(): void
    {
        $offenders = [];

        foreach (self::phpFilesUnderTestDirectory() as $path => $contents) {
            foreach (self::FORBIDDEN_ATTRIBUTES as $attribute) {
                // Match only an attribute in real syntax position — an opening
                // `#[`, then the attribute name followed by its argument list —
                // rather than the bare name anywhere in the file, so the
                // explanatory prose in class docblocks (which names these
                // attributes on purpose) does not trip the guard. Note this
                // file must itself avoid writing one out literally.
                if (preg_match('/#\[[^]]*\b' . $attribute . '\s*\(/', $contents) === 1) {
                    $offenders[] = $path . ': #[' . $attribute . ']';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Coverage-target attributes break `infection --filter` for the whole repository "
            . "(see this class's docblock). Remove them from:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * Sanity check that the scan actually reaches the suite. Without it a broken
     * path or an empty iterator would make the guard above vacuously pass.
     *
     * @return void
     */
    public function testScanCoversTheWholePhpunitTestTree(): void
    {
        $files = self::phpFilesUnderTestDirectory();

        self::assertGreaterThan(100, count($files), 'Expected the guard to scan the whole tests/phpunit/ tree.');
        self::assertArrayHasKey(
            'Classification/TypeScriptFrameworkRoleRuleTest.php',
            $files,
            'Expected a known test file to be scanned.',
        );
    }

    /**
     * @return array<string, string> suite-relative path => file contents
     */
    private static function phpFilesUnderTestDirectory(): array
    {
        $root = self::repositoryRoot() . '/tests/phpunit';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                self::fail('Unreadable test file: ' . $file->getPathname());
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = $contents;
        }

        return $files;
    }
}
