<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Mock-based unit tests for Git process providers.
 *
 * Uses anonymous-class mocks of GitProcessRunnerInterface to test
 * argument validation, git-output parsing, and edge cases without
 * requiring a real Git repository on disk.
 */
#[Group('git')]
final class ProcessGitProviderTest extends KnossosTestCase
{
    private string $existingDir;

    protected function setUp(): void
    {
        $this->existingDir = sys_get_temp_dir();
    }

    // ── ProcessGitWorkingTreeProvider ────────────────────────────────

    public function testChangesRejectsInvalidMaxFiles(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->changes($this->existingDir, null, 0, 100), RuntimeException::class);
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1001, 100), RuntimeException::class);
    }

    public function testChangesRejectsInvalidTimeout(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1, 0), RuntimeException::class);
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1, 5001), RuntimeException::class);
    }

    public function testChangesRejectsNonExistentRoot(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->changes('/knossos/does-not-exist-12345', null, 1, 100),
            RuntimeException::class,
        );
    }

    public function testChangesRejectsInvalidBaseRef(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->changes($this->existingDir, '--bad-ref', 1, 100),
            RuntimeException::class,
        );
    }

    public function testChangesRejectsBaseRefThatDoesNotResolve(): void
    {
        $mock = $this->mockRunner('not-a-commit-hash');
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        assertThrows(
            fn() => $provider->changes($this->existingDir, 'HEAD~1', 10, 100),
            RuntimeException::class,
        );
    }

    public function testChangesReturnsEmptyForCleanWorkingTree(): void
    {
        $mock = $this->mockRunner('a'.str_repeat('b', 39) . "\n");
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, 'HEAD', 10, 100);

        assertSame([], $result['paths']);
        assertSame([], $result['renames']);
        assertSame(false, $result['truncated']);
    }

    public function testChangesWithMovedAndUntrackedFiles(): void
    {
        // Mock that returns appropriate output based on the command pattern
        $mock = new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $subcommand = $command[5] ?? '';
                // rev-parse: returns a 40-char hex hash
                if ($subcommand === 'rev-parse') {
                    return 'abcdef1234567890abcdef1234567890abcdef12';
                }
                // git diff: returns changes
                if ($subcommand === 'diff') {
                    return "M\x0src/InvoiceService.php\x0" .
                        "R100\x0src/OldCheckout.php\x0src/Checkout.php\x0";
                }
                // git ls-files: returns untracked
                return "src/NEW_FILE.php\x0src/another.php\x0";
            }
        };

        // baseRef !== null → rev-parse + diff
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, 'HEAD', 10, 100);

        assertSame(['src/Checkout.php', 'src/InvoiceService.php', 'src/OldCheckout.php'], $result['paths']);
        assertSame([['from' => 'src/OldCheckout.php', 'to' => 'src/Checkout.php']], $result['renames']);
        assertSame(false, $result['truncated']);

        // baseRef = null → diff + ls-files (no rev-parse)
        $result2 = $provider->changes($this->existingDir, null, 10, 100);

        $this->assertContains('src/NEW_FILE.php', $result2['paths']);
        $this->assertContains('src/another.php', $result2['paths']);
    }

    public function testChangesTruncatesWhenExceedingMaxFiles(): void
    {
        $mock = $this->mockRunner(
            "M\x0src/a.php\x0" .
            "M\x0src/b.php\x0" .
            "M\x0src/c.php\x0"
        );
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, null, 2, 100);

        $this->assertCount(2, $result['paths']);
        assertSame(true, $result['truncated']);
    }

    public function testChangesFiltersInvalidPathsFromOutput(): void
    {
        // Mock returns diff output and empty ls-files output to avoid
        // ls-files tokens creating spurious paths from diff status codes.
        $mock = new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $subcommand = $command[5] ?? '';
                if ($subcommand === 'ls-files') {
                    return '';  // no untracked files
                }
                // diff output with an empty path after M status
                // Empty string fails RelativePath::assertValid
                return "M\x0src/valid.php\x0M\x0\x0";
            }
        };
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, null, 10, 100);

        assertSame(['src/valid.php'], $result['paths']);
    }

    // ── ProcessGitHistoryProvider ────────────────────────────────────

    public function testHistoryRejectsInvalidSinceDays(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 0, 10, 100), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 3651, 10, 100), RuntimeException::class);
    }

    public function testHistoryRejectsInvalidMaxCommits(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 30, 0, 100), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 30, 5001, 100), RuntimeException::class);
    }

    public function testHistoryRejectsInvalidTimeout(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 30, 10, 0), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 30, 10, 5001), RuntimeException::class);
    }

    public function testHistoryRejectsNonExistentRoot(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->history('/knossos/does-not-exist-12345', 30, 10, 100),
            RuntimeException::class,
        );
    }

    public function testHistoryReturnsEmptyForEmptyLog(): void
    {
        $mock = $this->mockRunner('');
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame([], $result['files']);
        assertSame(0, $result['commits_examined']);
        assertSame(false, $result['truncated']);
    }

    public function testHistoryParsesCommitsAndAggregatesFiles(): void
    {
        $gitLog = implode("\n", [
            "KNOSSOS_COMMIT\x1fabc123\x1f2026-07-20T10:00:00+00:00\x1fa@test.dev",
            "src/InvoiceService.php",
            "src/Checkout.php",
            '',
            "KNOSSOS_COMMIT\x1fdef456\x1f2026-07-19T09:00:00+00:00\x1fb@test.dev",
            "src/InvoiceService.php",
            '',
            "KNOSSOS_COMMIT\x1fghi789\x1f2026-07-18T08:00:00+00:00\x1fa@test.dev",
            "src/Order.php",
            '',
        ]);
        $mock = $this->mockRunner($gitLog);
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame(3, $result['commits_examined']);
        assertSame(false, $result['truncated']);
        $this->assertArrayHasKey('src/InvoiceService.php', $result['files']);
        assertSame(2, $result['files']['src/InvoiceService.php']['commit_count']);
        assertSame(['a@test.dev', 'b@test.dev'], $result['files']['src/InvoiceService.php']['authors']);
        $this->assertArrayHasKey('src/Checkout.php', $result['files']);
        $this->assertArrayHasKey('src/Order.php', $result['files']);
    }

    public function testHistoryTruncatesWhenExceedingMaxCommits(): void
    {
        $lines = [];
        for ($i = 0; $i < 5; ++$i) {
            $hash = str_pad((string) $i, 40, 'a');
            $lines[] = "KNOSSOS_COMMIT\x1f{$hash}\x1f2026-07-20T10:00:00+00:00\x1fa@test.dev";
            $lines[] = "src/file{$i}.php";
            $lines[] = '';
        }
        $mock = $this->mockRunner(implode("\n", $lines));
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 3, 100);

        assertSame(3, $result['commits_examined']);
        assertSame(true, $result['truncated']);
    }

    public function testHistorySkipsMalformedCommitLines(): void
    {
        // Malformed commit line (3 parts instead of 4) comes FIRST — its path
        // leaks into an undefined $current (null) and is dropped.
        $gitLog = implode("\n", [
            // Short line — only 3 parts instead of 4
            "KNOSSOS_COMMIT\x1fabc123\x1f2026-07-20T10:00:00+00:00",
            "src/skipped_path.php",
            '',
            "KNOSSOS_COMMIT\x1fdef456\x1f2026-07-19T09:00:00+00:00\x1fb@test.dev",
            "src/valid.php",
            '',
        ]);
        $mock = $this->mockRunner($gitLog);
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame(1, $result['commits_examined']);
        $this->assertArrayHasKey('src/valid.php', $result['files']);
        $this->assertArrayNotHasKey('src/skipped_path.php', $result['files']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function mockRunner(string $returnValue): GitProcessRunnerInterface
    {
        return new class($returnValue) implements GitProcessRunnerInterface {
            public function __construct(private readonly string $returnValue) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->returnValue;
            }
        };
    }
}
