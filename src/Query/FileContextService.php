<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use PDO;
use Throwable;

/**
 * One file's context in one answer, for the `knossos_context` tool the Claude
 * Code mod gives the model: where the file sits (its boundary), what depends
 * on it (the count and the most connected few), the tests that reach it, and
 * its latest commits.
 *
 * Built from {@see FileDetailService} (dependents and boundary) and
 * {@see ArchitectureQueryService::testImpact()} (tests), each list cut to a
 * few; the commits come from git alone, bounded in count and time, and are
 * simply absent where git is not there or does not answer. Read-only, never
 * scans. The rules a boundary is bound by are the mod's to add: it holds the
 * declared policies already.
 */
final readonly class FileContextService
{
    /** Dependents, tests and commits named each: the answer is read by a model, so it stays short. */
    public const NAMED = 5;

    /** Commits listed. */
    public const COMMITS = 3;

    /** How long git may take for the commits. */
    private const GIT_TIMEOUT_MS = 3000;

    /** How long the test search may take. */
    private const TESTS_TIMEOUT_MS = 1000;

    private GitProcessRunnerInterface $git;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param GitProcessRunnerInterface|null $git how git is run; the hardened runner unless a test stands in
     */
    public function __construct(private PDO $pdo, ?GitProcessRunnerInterface $git = null)
    {
        $this->git = $git ?? new GitProcessRunner();
    }

    /**
     * The context of the file at `$path`, or `unscanned` / `not-found` with no file.
     *
     * @return array<string, mixed>
     */
    public function context(string $path): array
    {
        $detail = (new FileDetailService($this->pdo))->detail($path);
        $base = ['status' => $detail['status'], 'path' => $detail['path'], 'project_id' => $detail['project_id'], 'snapshot_id' => $detail['snapshot_id'], 'file' => null];
        $file = $detail['file'] ?? null;
        if ($detail['status'] !== 'ok' || !is_array($file)) {
            return $base;
        }
        $projectId = (string) $detail['project_id'];
        $relative = (string) $file['path'];
        $root = substr((string) $detail['path'], 0, -strlen($relative) - 1);
        $tests = (new ArchitectureQueryService($this->pdo))->testImpact($projectId, [$relative], limit: self::NAMED, timeoutMs: self::TESTS_TIMEOUT_MS);
        $listed = array_map(static fn(array $t): array => ['path' => (string) $t['path'], 'distance' => (int) $t['distance']], $tests->data['test_files'] ?? []);

        return ['file' => [
            'path' => $relative,
            'language' => $file['language'],
            'lines' => $file['lines'],
            'boundary' => $file['boundary'],
            'components' => (int) $file['components']['count'],
            'dependents' => [
                'count' => (int) $file['dependents']['count'],
                'boundaries' => array_slice($file['dependents']['boundaries'], 0, self::NAMED),
                'top' => array_slice(array_column($file['dependents']['items'], 'path'), 0, self::NAMED),
            ],
            'tests' => ['items' => $listed, 'more' => $tests->truncated],
            'commits' => $this->commits($root, $relative),
        ]] + $base;
    }

    /**
     * The file's latest commits, newest first: the short id, when (Unix
     * seconds) and the subject; empty without git or when it does not answer.
     *
     * @return list<array{rev: string, at: int, subject: string}>
     */
    private function commits(string $root, string $relative): array
    {
        try {
            $out = $this->git->run(['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root, 'log', '-n', (string) self::COMMITS, '--format=%h%x1f%ct%x1f%s', '--', $relative], self::GIT_TIMEOUT_MS, 'file context');
        } catch (Throwable) {
            return [];
        }
        $commits = [];
        foreach (array_filter(explode("\n", $out), static fn(string $line): bool => $line !== '') as $line) {
            $parts = explode("\x1F", $line, 3);
            if (count($parts) === 3) {
                $commits[] = ['rev' => $parts[0], 'at' => (int) $parts[1], 'subject' => mb_substr($parts[2], 0, 120)];
            }
        }

        return $commits;
    }
}
