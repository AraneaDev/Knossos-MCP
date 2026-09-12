<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\RefreshPolicy;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The budget is what makes a default-on refresh safe. Every branch here is a
 * promise that a query cannot be held open longer than the caller's client will
 * wait, and the degraded answer is always better than a timed-out one.
 */
final class RefreshPolicyTest extends KnossosTestCase
{
    /** 10 ms/file times a small drift lands well inside the default budget. */
    #[Group('query')]
    public function testASmallDriftRefreshes(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(seconds: 10, files: 1000);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, 5)->refresh, '10 ms per file times 5 files is 50 ms, well under budget.');
    }

    /** Over budget, the caller needs the drift count to decide whether to rescan itself. */
    #[Group('query')]
    public function testALargeDriftDeclinesWithAReason(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(seconds: 10, files: 1000);
        $decision = (new RefreshPolicy($pdo))->decide($projectId, 900);

        self::assertFalse($decision->refresh);
        self::assertStringContainsString('900', (string) $decision->reason, 'The caller needs the drift count to decide whether to rescan itself.');
    }

    /** A scan that finished inside one second reads as zero duration, which is a legitimate "free" answer. */
    #[Group('query')]
    public function testAnInstantScanAlwaysFitsTheBudget(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(seconds: 0, files: 1000);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, 900)->refresh, 'A scan too fast to time is a scan that costs nothing to repeat.');
    }

    /** Without a scan to learn cost from, the policy must decline rather than guess or divide by zero. */
    #[Group('query')]
    public function testAProjectWithNoScanHistoryDeclines(): void
    {
        $pdo = $this->freshTestDatabase();
        $decision = (new RefreshPolicy($pdo))->decide('unknown-project', 5);

        self::assertFalse($decision->refresh, 'A cost that cannot be measured cannot be capped.');
    }

    /** A drift of zero declines without even reading scan cost, because there is nothing to refresh. */
    #[Group('query')]
    public function testZeroDriftDeclinesWithoutMeasuring(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(seconds: 600, files: 1000);

        self::assertFalse((new RefreshPolicy($pdo))->decide($projectId, 0)->refresh, 'Nothing drifted, so there is nothing to refresh.');
    }

    /**
     * Seeds a project whose completed scan spans $seconds over $files files, so
     * RefreshPolicy's per-file cost estimate is a fixed, known quantity for the
     * arithmetic each test asserts against.
     *
     * @return array{0: PDO, 1: string}
     */
    private function seedScanCosting(int $seconds, int $files): array
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $this->removeTempTree($root);

        $scanIdStatement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $scanIdStatement->execute(['id' => $projectId]);
        $scanId = (string) $scanIdStatement->fetchColumn();

        $pdo->prepare('UPDATE scans SET started_at = :started, finished_at = :finished WHERE id = :id')->execute([
            'started' => gmdate('Y-m-d H:i:s', 1_700_000_000),
            'finished' => gmdate('Y-m-d H:i:s', 1_700_000_000 + $seconds),
            'id' => $scanId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
            'VALUES (:id, :project, :path, :hash, 1, 1, :language, :version, :scan)',
        );
        for ($index = 1; $index < $files; ++$index) {
            $insert->execute([
                'id' => 'f' . $index, 'project' => $projectId, 'path' => 'src/f' . $index . '.php',
                'hash' => hash('sha256', (string) $index), 'language' => 'php', 'version' => '0.1.0', 'scan' => $scanId,
            ]);
        }

        return [$pdo, $projectId];
    }
}
