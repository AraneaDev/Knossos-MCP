<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\FileDetailService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * One file as the architecture pane shows it, addressed by its own path: the
 * files that depend on it with how much and in which boundary, and what it
 * declares, or a status saying why there is nothing to show.
 */
final class FileDetailServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testAFileNamesWhoDependsOnItAndWhatItDeclares(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new FileDetailService($pdo))->detail($root . '/src/Core/Greeter.php');
            assertSame('ok', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame(realpath($root . '/src/Core/Greeter.php'), $d['path']);
            $file = $d['file'];
            assertSame('src/Core/Greeter.php', $file['path']);
            assertSame('php', $file['language']);
            assertSame(13, $file['lines']);
            // Its own label, the declared boundary; its dependents' are where a change reaches.
            assertSame('Core', $file['boundary']);
            $dependents = $file['dependents'];
            assertSame(['src/Edge/Caller.php', 'tests/GreeterTest.php'], array_column($dependents['items'], 'path'));
            assertSame(2, $dependents['count']);
            assertSame(false, $dependents['truncated']);
            assertSame('Edge', $dependents['items'][0]['boundary']);
            assertGreaterThanOrEqual(1, $dependents['items'][0]['edges']);
            assertSame(true, in_array('Edge', $dependents['boundaries'], true));
            $components = $file['components'];
            assertSame(false, $components['truncated']);
            assertSame($components['count'], count($components['items']));
            // The most used first: the class, which the caller constructs.
            assertSame(
                ['name' => 'Greeter', 'canonical_name' => 'App\Greeter', 'kind' => 'class', 'line' => 7, 'boundary' => 'Core'],
                array_diff_key($components['items'][0], ['used_by' => true]),
            );
            assertGreaterThanOrEqual(1, $components['items'][0]['used_by']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A script's own module node is the file, and a built-in it names (`external_*`, filed under it) is not something it declares. */
    #[Group('query')]
    public function testAScriptDeclaresNeitherItselfNorTheBuiltInsItCalls(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Edge/script.php', "<?php\n\nfunction helper(): string\n{\n    return sprintf('%s', strtoupper('x'));\n}\n\necho helper();\n");
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $components = (new FileDetailService($pdo))->detail($root . '/src/Edge/script.php')['file']['components'];
            assertSame(['helper'], array_column($components['items'], 'name'));
            assertSame(1, $components['count']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAFileNobodyDependsOnListsNoDependents(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $file = (new FileDetailService($pdo))->detail($root . '/tests/GreeterTest.php')['file'];
            assertSame(['count' => 0, 'truncated' => false, 'boundaries' => [], 'items' => []], $file['dependents']);
            assertGreaterThanOrEqual(1, $file['components']['count']);
            // The test depends on what it tests: the other side of the same relationship.
            assertSame(['src/Core/Greeter.php'], array_column($file['uses']['items'], 'path'));
            assertSame(1, $file['uses']['count']);
            assertSame(false, $file['uses']['truncated']);
            assertSame('Core', $file['uses']['items'][0]['boundary']);
            assertGreaterThanOrEqual(1, $file['uses']['items'][0]['edges']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file deleted since the scan is still in the graph it describes; one the graph never held is not found. */
    #[Group('query')]
    public function testAFileTheGraphDoesNotHoldIsNotFound(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            unlink($root . '/src/Edge/Caller.php');
            assertSame('ok', (new FileDetailService($pdo))->detail($root . '/src/Edge/Caller.php')['status']);
            $d = (new FileDetailService($pdo))->detail($root . '/src/Nope.php');
            assertSame('not-found', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame(null, $d['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathNoScannedProjectContainsIsUnscanned(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new FileDetailService($pdo))->detail('/nowhere/at/all.php');
            assertSame(['status' => 'unscanned', 'path' => '/nowhere/at/all.php', 'project_id' => null, 'snapshot_id' => null, 'file' => null], $d);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The lists stop at their bound; the counts go on and the flags say so. */
    #[Group('query')]
    public function testTheListsAreBoundedAndSaySo(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $methods = implode("\n", array_map(static fn(int $i): string => "    public function m{$i}(): void {}", range(1, 14)));
            file_put_contents($root . '/src/Core/Wide.php', "<?php\n\nnamespace App;\n\nfinal class Wide\n{\n{$methods}\n}\n");
            for ($i = 1; $i <= 11; ++$i) {
                file_put_contents($root . "/src/Edge/User{$i}.php", "<?php\n\nnamespace App;\n\nfinal class User{$i}\n{\n    public function go(): void { (new Wide())->m1(); }\n}\n");
            }
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $file = (new FileDetailService($pdo))->detail($root . '/src/Core/Wide.php')['file'];
            assertSame(11, $file['dependents']['count']);
            assertCount(10, $file['dependents']['items']);
            assertSame(true, $file['dependents']['truncated']);
            assertSame(15, $file['components']['count']);
            assertCount(12, $file['components']['items']);
            assertSame(true, $file['components']['truncated']);
            // A file depending on twelve others lists ten of them, by how much, and counts all twelve.
            $calls = implode(' ', array_map(static fn(int $i): string => "(new User{$i}())->go();", range(1, 11)));
            file_put_contents($root . '/src/Edge/Hub.php', "<?php\n\nnamespace App;\n\nfinal class Hub\n{\n    public function run(): void { {$calls} (new Wide())->m2(); (new Wide())->m3(); }\n}\n");
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $uses = (new FileDetailService($pdo))->detail($root . '/src/Edge/Hub.php')['file']['uses'];
            assertSame(12, $uses['count']);
            assertCount(10, $uses['items']);
            assertSame(true, $uses['truncated']);
            assertSame('src/Core/Wide.php', $uses['items'][0]['path']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
