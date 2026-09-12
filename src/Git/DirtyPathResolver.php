<?php

declare(strict_types=1);

namespace Knossos\Git;

use Throwable;

/**
 * Which tracked paths the working tree holds differently from a given commit.
 *
 * Asked once per scan, against the commit that scan recorded, so the graph
 * knows which of its stored hashes may be of uncommitted content. See
 * {@see DirtyPathSet} for what that buys the drift oracle.
 *
 * Returns null rather than throwing for every failure: a project that is not
 * a repository, a missing binary, a timeout, output past the runner's
 * ceiling. None of those is worth failing a scan over, and each one simply
 * leaves the scan with no recorded set, which the oracle declines on rather
 * than decides from.
 */
final readonly class DirtyPathResolver
{
    /** Bounded like every other scan-time git call: this runs on every scan. */
    private const TIMEOUT_MS = 2000;

    private GitProcessRunnerInterface $runner;

    /** Defaults to a real subprocess runner; a test double replaces it without touching callers. */
    public function __construct(?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner();
    }

    /**
     * Paths differing from $head, relative to $projectRoot, or null when git
     * could not answer.
     *
     * Spelled exactly like the drift oracle's own changed-file listing —
     * `--relative` so paths are rooted where the graph's are, the pathspec so
     * a monorepo's sibling packages stay out, `--no-renames` so a move
     * arrives as both of its paths — because the two sets are unioned later
     * and a spelling difference between them would be a silent mismatch.
     */
    public function resolve(string $projectRoot, string $head): ?DirtyPathSet
    {
        try {
            $output = $this->runner->run(
                [
                    'git', '--no-optional-locks', '--no-pager', '-C', $projectRoot,
                    'diff', '--name-only', '-z', '--no-ext-diff', '--no-renames', '--relative', $head, '--', $projectRoot,
                ],
                self::TIMEOUT_MS,
                'scan dirty paths',
            );
        } catch (Throwable $error) {
            // A scan with no recorded set degrades to an oracle that declines,
            // which looks like nothing at all from outside: no warning, just a
            // probe that never gets cheaper. Never stdout, which carries MCP
            // protocol frames.
            error_log('knossos scan dirty paths: git could not answer (' . $error->getMessage() . ')');

            return null;
        }

        return DirtyPathSet::of(array_values(array_filter(explode("\0", rtrim($output, "\0")))));
    }
}
