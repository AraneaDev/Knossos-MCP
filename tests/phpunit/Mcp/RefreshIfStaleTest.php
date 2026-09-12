<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RefreshIfStaleTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testStaleGraphIsRescannedBeforeAnswering(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            assertSame('stale', (new StalenessProbe($pdo))->probe($projectId)['state']);

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame('fresh', $result->staleness['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('mcp')]
    public function testFreshGraphSkipsRescanAndFailedRescanWarnsInsteadOfErroring(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $scans = fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $before = $scans();
            $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($before, $scans()); // fresh: no rescan attempted

            // Stale project whose root is outside the scanner's allowed roots:
            // the rescan fails, the query still answers, and a warning explains.
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $confined = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), ['/nonexistent-allowed-root']),
                new ArchitectureQueryService($pdo),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            );
            $result = $confined->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($projectId, $result->projectId);
            $joined = implode(' ', $result->warnings);
            assertSame(true, str_contains($joined, 'refresh_if_stale'));

            assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => 'yes']), InvalidArgumentException::class);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The budget is what makes Task 9's default-on refresh safe. A drift too
     * expensive to repair inline must still answer, from the graph it has, with
     * a warning the caller can act on. An error here would cost the agent the
     * very turn this feature exists to save.
     */
    #[Group('mcp')]
    public function testAnOverBudgetDriftIsDeclinedAndTheStaleGraphStillAnswers(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);

            // Make the recorded scan look expensive rather than lowering the
            // budget to zero: a scan too fast to time costs zero per file, and
            // zero is never over any budget, so a zero budget would not reach
            // the branch under test at all. The 'mixed' fixture scans 3 files,
            // so a 600-second span costs 200,000 ms/file for a 1-file drift,
            // far over the 5000 ms default budget.
            $projectStatement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
            $projectStatement->execute(['id' => $projectId]);
            $scanId = (string) $projectStatement->fetchColumn();

            $finishedStatement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
            $finishedStatement->execute(['id' => $scanId]);
            $started = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $finishedStatement->fetchColumn()) - 600);
            $pdo->prepare('UPDATE scans SET started_at = :started WHERE id = :id')->execute([
                'started' => $started,
                'id' => $scanId,
            ]);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'An over-budget refresh must not scan.');
            assertSame($projectId, $result->projectId);
            assertSame('stale', $result->staleness['state']);
            assertSame(true, str_contains(implode(' ', $result->warnings), 'over the 5000 ms budget'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A stale verdict with no measured change set cannot be costed, so it must decline rather than guess. */
    #[Group('mcp')]
    public function testAStaleGraphWithNoMeasuredDriftDeclines(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            // A newer failed attempt makes the probe report 'stale' without any
            // drift counts, which is the branch under test.
            // started_at must match the schema's own timestamp format (see
            // SqliteValues::now()): hasNewerAttempt() orders by started_at as a
            // string, and a mismatched format (e.g. a space instead of 'T')
            // sorts wrong regardless of which instant is actually later.
            $pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
                "VALUES ('later-attempt', :project, 'incremental', 'failed', 'x', :started)",
            )->execute(['project' => $projectId, 'started' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)]);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
            assertSame(true, str_contains(implode(' ', $result->warnings), 'change set is unknown'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
