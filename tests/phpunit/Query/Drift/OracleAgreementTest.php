<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two oracles answer the same question, so they must give the same answer.
 *
 * The git side is faked, because CI has no git binary. What this pins is the
 * mapping from a set of edits to the three counts, which is where the two
 * implementations could silently disagree. It does not prove git itself
 * reports what the fake claims; nothing runnable in CI could prove that.
 */
final class OracleAgreementTest extends KnossosTestCase
{
    /** One modification, one deletion, one addition: the smallest set that exercises all three counts at once. */
    #[Group('query')]
    public function testBothOraclesAgreeOnTheSameEdits(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php', 'src/b.php']);
        try {
            $scanId = $this->activeScanId($pdo, $projectId);
            $finishedAt = $this->finishedAt($pdo, $scanId);
            $pdo->prepare('UPDATE scans SET git_head = :head WHERE id = :id')
                ->execute(['head' => str_repeat('a', 40), 'id' => $scanId]);

            // One modification, one deletion, one addition, on disk.
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");
            unlink($root . '/src/b.php');
            file_put_contents($root . '/src/c.php', "<?php\n");
            touch($root . '/src', time() + 60);

            $git = new GitDriftOracle($pdo, $this->fakeRunner([
                str_repeat('a', 40) . "\n",
                "M\0src/a.php\0D\0src/b.php\0",
                "src/c.php\0",
            ]));
            $walk = new WalkDriftOracle($pdo);

            self::assertEquals(
                $git->drift($projectId, $scanId, $root, $finishedAt),
                $walk->drift($projectId, $scanId, $root, $finishedAt),
                'The same edits must produce the same counts whichever oracle answers.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The active scan id, looked up by parameter binding rather than string interpolation. */
    private function activeScanId(PDO $pdo, string $projectId): string
    {
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return (string) $statement->fetchColumn();
    }

    /** A scan's finish time, looked up by parameter binding rather than string interpolation. */
    private function finishedAt(PDO $pdo, string $scanId): string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);

        return (string) $statement->fetchColumn();
    }

    /** A runner that hands back each of $outputs in call order, standing in for rev-parse, diff and ls-files. */
    private function fakeRunner(array $outputs): GitProcessRunnerInterface
    {
        return new class ($outputs) implements GitProcessRunnerInterface {
            private int $call = 0;

            /** @param list<string> $outputs */
            public function __construct(private readonly array $outputs) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->outputs[$this->call++] ?? '';
            }
        };
    }
}
