<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Guards the docstring gate itself.
 *
 * A quality gate that cannot fail is worse than no gate: it reports green while
 * checking nothing, and this repository has already shipped three of those — a
 * coverage run that skipped seven tests as root, an API check blind to interfaces
 * nested two directories deep, and a suite whose deliberate 500 printed a raw
 * SQLSTATE. So these tests assert the measurement is *discriminating*, not merely
 * that the tool exits zero.
 */
final class DocstringCoverageTest extends KnossosTestCase
{
    #[Group('documentation')]
    public function testGatePassesAndReportsInternallyConsistentNumbers(): void
    {
        $root = self::repositoryRoot();
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/docstring-report.php']);
        if ($exit !== 0) {
            throw new RuntimeException($errors);
        }
        assertContains('Docstring coverage:', $output);

        $report = json_decode(
            (string) file_get_contents($root . '/coverage/quality/docstring-coverage.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        // The listed gaps must reconcile with the counts. A report whose list is
        // truncated, or whose totals are computed separately from it, would let the
        // number improve while the work did not.
        assertSame(
            $report['summary']['total'] - $report['summary']['documented'],
            count($report['undocumented']),
        );
        assertSame(
            $report['types']['total'] - $report['types']['documented'],
            count($report['undocumented_types']),
        );
        assertSame(
            $report['summary']['documented'],
            array_sum(array_column($report['areas'], 'documented')),
        );
    }

    #[Group('documentation')]
    public function testEveryTypeExplainsWhyItExists(): void
    {
        $root = self::repositoryRoot();
        $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/docstring-report.php']);
        $report = json_decode(
            (string) file_get_contents($root . '/coverage/quality/docstring-coverage.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // Held at 100% rather than a ratchet: a class docblock is the one piece of
        // documentation a reader cannot reconstruct from the code, and a new type
        // arriving without one is the cheapest possible thing to catch.
        assertSame([], $report['undocumented_types']);
    }

    #[Group('documentation')]
    public function testAnAnnotationOnlyDocblockDoesNotCountAsDocumented(): void
    {
        $root = self::repositoryRoot();
        $fixture = $this->makeFixtureFile(<<<'PHP'
            <?php
            /** Documented. */
            final class WithSummary
            {
                /** Does a thing. */
                public function documented(): void {}

                /** @return void */
                public function annotationOnly(): void {}

                public function bare(): void {}

                public function __construct() {}
            }
            PHP);

        $measured = $this->measure($fixture);

        // The rule that makes the metric mean something: `@return void` restates the
        // signature, so counting it would let coverage rise without a reader
        // learning anything.
        assertSame(['documented'], $measured['documented']);
        assertSame(['annotationOnly', 'bare'], $measured['undocumented']);
        // Constructors are measured through the type docblock instead.
        assertSame(false, in_array('__construct', array_merge($measured['documented'], $measured['undocumented']), true));
    }

    #[Group('documentation')]
    public function testDocblocksAreFoundThroughModifiersAndAttributes(): void
    {
        $root = self::repositoryRoot();
        $fixture = $this->makeFixtureFile(<<<'PHP'
            <?php
            /** Documented. */
            final class Modifiers
            {
                /** Behind an attribute. */
                #[\Deprecated]
                public static function attributed(): void {}

                /** Behind several modifiers. */
                final protected static function modified(): void {}
            }
            PHP);

        // Attributes and modifier lists legitimately sit between a docblock and the
        // `function` keyword; treating either as a wall would under-report coverage
        // and push contributors to delete attributes to satisfy a gate.
        assertSame(['attributed', 'modified'], $this->measure($fixture)['documented']);
    }

    /**
     * Measure one fixture file with the gate's own logic.
     *
     * @return array{documented: list<string>, undocumented: list<string>}
     */
    private function measure(string $path): array
    {
        $root = self::repositoryRoot();
        $script = sprintf(
            '$code = file_get_contents(%s); eval(substr($code, strpos($code, "function declaredFunctions")));'
            . ' $out = ["documented" => [], "undocumented" => []];'
            . ' foreach (declaredFunctions(file_get_contents(%s)) as $f) {'
            . ' $out[$f["documented"] ? "documented" : "undocumented"][] = $f["name"]; }'
            . ' echo json_encode($out);',
            var_export($root . '/tools/docstring-report.php', true),
            var_export($path, true),
        );
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', $script]);
        if ($exit !== 0) {
            throw new RuntimeException($errors);
        }

        return json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeFixtureFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-docstring-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate a docstring fixture file.');
        }
        file_put_contents($path, $contents . "\n");
        $this->fixtures[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }
        $this->fixtures = [];
    }
}
