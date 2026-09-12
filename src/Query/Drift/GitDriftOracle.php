<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use PDO;
use Throwable;

/**
 * Drift since the scan's commit, asked of Git rather than of the filesystem.
 *
 * Exact where the walk is approximate, and independent of repository size: the
 * walk's ceilings exist because it must stat every tracked file, and this asks
 * one question instead. It answers only when the scan recorded a commit that
 * still resolves; every other case returns null and the walk takes over.
 */
final readonly class GitDriftOracle implements DriftOracle
{
    private const TIMEOUT_MS = 2000;

    private GitProcessRunnerInterface $runner;

    /** Defaults to a real subprocess runner; a test double replaces it without touching callers. */
    public function __construct(
        private PDO $pdo,
        ?GitProcessRunnerInterface $runner = null,
    ) {
        $this->runner = $runner ?? new GitProcessRunner();
    }

    /**
     * Drift since the scan's commit, read from `git diff` and `git ls-files`
     * rather than from a filesystem walk.
     *
     * `$finishedAt` is unused: git's own history already fixes the moment to
     * compare against (the recorded commit), so there is no wall-clock
     * boundary left for this oracle to need. It stays in the signature only
     * because {@see DriftOracle} is shared with the walk, which does need it.
     *
     * Every failure path (no recorded head, an unparseable one, a commit that
     * no longer resolves, git unavailable or over its timeout) returns null
     * rather than a zero count, so an oracle that could not check never
     * reports a graph as fresh it never verified.
     */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
    {
        $statement = $this->pdo->prepare('SELECT git_head FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);
        $head = $statement->fetchColumn();
        if (!is_string($head) || preg_match('/^[a-f0-9]{40,64}$/', $head) !== 1) {
            return null;
        }
        try {
            // Verify first. Handing a garbage-collected or rebased-away commit
            // to `diff` fails anyway, but failing here says why, and says it
            // before the more expensive call.
            $this->run($root, ['rev-parse', '--verify', $head . '^{commit}']);
            $statusOutput = $this->run($root, ['diff', '--name-status', '-z', '--no-ext-diff', '--find-renames', $head, '--']);
            $untrackedOutput = $this->run($root, ['ls-files', '--others', '--exclude-standard', '-z', '--']);
        } catch (Throwable) {
            return null;
        }
        $counts = self::tally($statusOutput);

        return new DriftCounts(
            $counts['changed'],
            $counts['added'] + self::countEntries($untrackedOutput),
            $counts['deleted'],
        );
    }

    /**
     * Runs one git subcommand against $root under the fixed timeout and
     * operation label every call here shares.
     *
     * @param list<string> $arguments
     */
    private function run(string $root, array $arguments): string
    {
        return $this->runner->run(
            array_merge(['git', '--no-optional-locks', '--no-pager', '-C', $root], $arguments),
            self::TIMEOUT_MS,
            'drift query',
        );
    }

    /**
     * Split `--name-status -z` output three ways.
     *
     * A rename is one addition and one deletion rather than one change: the
     * component moved, which is exactly the kind of drift a graph must be
     * rebuilt for, and calling it a modification would understate it.
     *
     * @return array{changed: int, added: int, deleted: int}
     */
    private static function tally(string $output): array
    {
        $tokens = explode("\0", rtrim($output, "\0"));
        $changed = 0;
        $added = 0;
        $deleted = 0;
        $total = count($tokens);
        for ($index = 0; $index < $total;) {
            $status = $tokens[$index++];
            if ($status === '') {
                continue;
            }
            $paired = str_starts_with($status, 'R') || str_starts_with($status, 'C');
            // Consume the path operand(s) this status carries, so the next
            // iteration reads a status and not a path: a single-letter status
            // owns one path, R/C own two (the old path and the new one).
            ++$index;
            if ($paired) {
                ++$index;
                ++$added;
                ++$deleted;
                continue;
            }
            match ($status[0]) {
                'A' => ++$added,
                'D' => ++$deleted,
                default => ++$changed,
            };
        }

        return ['changed' => $changed, 'added' => $added, 'deleted' => $deleted];
    }

    /** Non-empty NUL-separated entries, which is how every `-z` listing is counted. */
    private static function countEntries(string $output): int
    {
        return count(array_filter(
            explode("\0", rtrim($output, "\0")),
            static fn(string $entry): bool => $entry !== '',
        ));
    }
}
