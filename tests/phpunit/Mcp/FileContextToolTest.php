<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Mcp\{NextStepPlanner, ResultEnricher, ToolService};
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\{ArchitectureQueryService, StalenessProbe};
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\{assertContains, assertSame};

/** The `file_context` tool: one file's context from the server itself, so no second tool host has to answer it. */
final class FileContextToolTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testAFileIsAnsweredWithItsContextAndTheDeclaredRulesThatBindItsBoundary(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            // Declared after the scan: the rules are read live, as the briefs read them.
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'boundaries' => [
                ['name' => 'Core', 'path_prefix' => 'src/Core/'],
                ['name' => 'Edge', 'path_prefix' => 'src/Edge/'],
            ], 'policies' => [
                ['id' => 'core-stays-inward', 'from_boundary' => 'Core', 'deny_targets' => ['Edge']],
                ['id' => 'edge-rule', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']],
            ]]));
            $tools = self::tools($pdo, $root);
            assertContains('file_context', array_column($tools->definitions(), 'name'));

            $envelope = $tools->call('file_context', ['project_id' => $projectId, 'path' => 'src/Core/Greeter.php']);
            assertSame($projectId, $envelope->projectId);
            $file = $envelope->data['file'];
            assertSame(['src/Core/Greeter.php', 'Core', 2], [$file['path'], $file['boundary'], $file['dependents']['count']]);
            assertSame(['tests/GreeterTest.php'], array_column($file['tests']['items'], 'path'));
            // Only the rules whose source is the file's own boundary.
            assertSame(['core-stays-inward'], array_column($envelope->data['policies'], 'id'));

            // An absolute path under the root is the same file.
            assertSame('src/Core/Greeter.php', $tools->call('file_context', ['project_id' => $projectId, 'path' => $root . '/src/Core/Greeter.php'])->data['file']['path']);
            assertSame('not-found', $tools->call('file_context', ['project_id' => $projectId, 'path' => 'src/Nope.php'])->data['status']);
            $this->assertRejected($tools, $projectId, '../outside.php');
            $this->assertRejected($tools, $projectId, '/etc/passwd');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A link inside the project to a file in another scanned project answers
     * nothing: the file is that project's, and its rules are not this one's.
     */
    #[Group('mcp')]
    public function testALinkToAnotherProjectsFileIsNotAnsweredForThisOne(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $other = sys_get_temp_dir() . '/knossos-stale-other-' . bin2hex(random_bytes(4));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $other);
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$other]))->scan($other);
            symlink($other . '/src/Core/Greeter.php', $root . '/src/Linked.php');
            $answer = self::tools($pdo, $root)->call('file_context', ['project_id' => $projectId, 'path' => 'src/Linked.php']);
            self::assertSame([$projectId, 'not-found', null], [$answer->projectId, $answer->data['status'], $answer->data['file']]);
        } finally {
            $this->removeTempTree($other);
            $this->removeTempTree($root);
        }
    }

    private function assertRejected(ToolService $tools, string $projectId, string $path): void
    {
        try {
            $tools->call('file_context', ['project_id' => $projectId, 'path' => $path]);
        } catch (InvalidArgumentException $error) {
            assertSame(true, str_contains($error->getMessage(), 'outside the project'), $error->getMessage());
            return;
        }
        self::fail(sprintf('%s was answered, though it lies outside the project.', $path));
    }

    private static function tools(PDO $pdo, string $root): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
    }
}
