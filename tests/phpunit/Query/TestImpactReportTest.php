<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the test-impact report says, beyond which files it found.
 *
 * ChangeImpactQueryService scored 80% under mutation testing and the report's
 * shape carried most of the survivors: the sentence it leads with, the order the
 * files come back in, the classes it names as the route to each file, and where
 * truncation starts. This is the tool whose output decides which tests get run,
 * so its ordering and its cap are the difference between running the right three
 * and the wrong three.
 */
final class TestImpactReportTest extends KnossosTestCase
{
    /** The summary counts the test files and agrees with itself in number. */
    #[Group('query')]
    public function testTheSummaryAgreesInNumberWithWhatItFound(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        self::addTestFile($repository, $ids, 'tests/OneTest.php', ['One']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        assertSame(
            '1 test file statically exercise the change.',
            $queries->testImpact($ids['project'], files: ['src/Checkout.php'])->summary,
        );

        [$pdo, $repository, $ids] = $this->storeFixture();
        self::addTestFile($repository, $ids, 'tests/OneTest.php', ['One']);
        self::addTestFile($repository, $ids, 'tests/TwoTest.php', ['Two']);
        $repository->completeScan($ids['project'], $ids['scan']);

        assertSame(
            '2 test files statically exercise the change.',
            (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'])->summary,
        );
    }

    /**
     * The classes naming the route into a file are sorted, de-duplicated and
     * capped at three, so one crowded file cannot fill the report.
     */
    #[Group('query')]
    public function testTheRouteIntoAFileIsSortedAndCappedAtThree(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Four test classes in one file, declared out of alphabetical order.
        self::addTestFile($repository, $ids, 'tests/CrowdedTest.php', ['Delta', 'Bravo', 'Alpha', 'Charlie']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $file = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'])->data['test_files'][0];

        assertSame(['Alpha', 'Bravo', 'Charlie'], $file['via'], 'Three names, in order, not the first three the walk met.');
    }

    /**
     * The route names are sorted by what is displayed and each appears once.
     *
     * The classes are declared so their display names run opposite to their
     * canonical names, which is the only arrangement that tells a sort from the
     * order the walk produced; and two of them share a display name, which is
     * the only one that tells de-duplication from its absence.
     */
    #[Group('query')]
    public function testTheRouteNamesAreSortedByDisplayAndEachAppearsOnce(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $path = 'tests/MixedTest.php';
        $file = StableId::file($ids['project'], $path);
        $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 60, 1, 'php', '0.1.0', $ids['scan']);
        $owner = 'php:file:src/Checkout.php';
        // Canonical order Aaa, Bbb, Ccc; display order Zulu, Alpha, Alpha.
        foreach ([['Aaa', 'Zulu'], ['Bbb', 'Alpha'], ['Ccc', 'Alpha']] as $index => [$canonical, $display]) {
            $class = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\' . $canonical);
            $repository->saveNode($class, $ids['project'], 'php', 'class', 'Tests\\' . $canonical, $display, null, $file, 5 + $index, 30, 'ast', 'certain', [], $owner, $ids['scan']);
            $repository->saveClassification(
                StableId::classification($ids['project'], $class, 'quality.test_module', 'core.test.modules.v1'),
                $ids['project'],
                $class,
                'quality.test_module',
                'derived',
                'probable',
                'core.test.modules.v1',
                $file,
                5,
                30,
                [],
                $ids['scan'],
            );
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $class, $ids['checkout'], $path . ':' . $index),
                $ids['project'],
                'calls',
                $class,
                $ids['checkout'],
                $file,
                12,
                12,
                'ast',
                'certain',
                [],
                $owner,
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $via = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'])->data['test_files'][0]['via'];

        assertSame(['Alpha', 'Zulu'], $via, 'Sorted by display name, and the repeated name appears once.');
    }

    /**
     * Files at one distance come back alphabetically, whatever order the walk
     * reached them in.
     *
     * Every component of the fixture's changed file is one step away, including
     * the one reached through the Invoice class, so this pins the path tie-break
     * rather than the distance ordering above it.
     */
    #[Group('query')]
    public function testFilesAtOneDistanceComeBackAlphabetically(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        self::addTestFile($repository, $ids, 'tests/ZebraTest.php', ['Zebra']);
        self::addTestFile($repository, $ids, 'tests/AlphaTest.php', ['Alpha']);
        self::addTestFile($repository, $ids, 'tests/MiddleTest.php', ['Middle'], $ids['invoice']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $files = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'])->data['test_files'];

        assertSame(
            ['tests/AlphaTest.php', 'tests/MiddleTest.php', 'tests/ZebraTest.php'],
            array_column($files, 'path'),
        );
        assertSame([1, 1, 1], array_column($files, 'distance'));
    }

    /** Exactly as many files as the limit is not a truncation; one more is. */
    #[Group('query')]
    public function testExactlyTheLimitIsNotATruncation(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        self::addTestFile($repository, $ids, 'tests/AlphaTest.php', ['Alpha']);
        self::addTestFile($repository, $ids, 'tests/BetaTest.php', ['Beta']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $atBound = $queries->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 2);
        assertSame(2, count($atBound->data['test_files']));
        assertSame(false, $atBound->truncated, 'Two files inside a limit of two is the whole answer.');

        $over = $queries->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 1);
        assertSame(1, count($over->data['test_files']));
        assertSame(true, $over->truncated);
    }

    /**
     * One test file holding the named classes, each calling $target.
     *
     * @param array<string, string> $ids
     * @param list<string> $classNames
     */
    private static function addTestFile(GraphRepository $repository, array $ids, string $path, array $classNames, ?string $target = null): void
    {
        $owner = 'php:file:src/Checkout.php';
        $target ??= $ids['checkout'];
        $file = StableId::file($ids['project'], $path);
        $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 60, 1, 'php', '0.1.0', $ids['scan']);
        foreach ($classNames as $index => $name) {
            $class = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\' . $name . 'Test');
            $repository->saveNode($class, $ids['project'], 'php', 'class', 'Tests\\' . $name . 'Test', $name, null, $file, 5 + $index, 30, 'ast', 'certain', [], $owner, $ids['scan']);
            $repository->saveClassification(
                StableId::classification($ids['project'], $class, 'quality.test_module', 'core.test.modules.v1'),
                $ids['project'],
                $class,
                'quality.test_module',
                'derived',
                'probable',
                'core.test.modules.v1',
                $file,
                5,
                30,
                [],
                $ids['scan'],
            );
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $class, $target, $path . ':' . $index),
                $ids['project'],
                'calls',
                $class,
                $target,
                $file,
                12,
                12,
                'ast',
                'certain',
                [],
                $owner,
                $ids['scan'],
            );
        }
    }
}
