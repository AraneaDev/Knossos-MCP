<?php

declare(strict_types=1);

namespace Knossos\Git;

use Throwable;

/**
 * The commit a scan was taken at, so later drift can be asked of git rather
 * than of the filesystem.
 *
 * Returns null rather than throwing for every failure: a project that is not a
 * repository, a git binary that is absent, a timeout. None of those is an
 * error worth failing a scan over, and each one simply means the walk oracle
 * answers instead.
 */
final readonly class GitHeadResolver
{
    /** Bounded well under the provider's 5000 ms ceiling: this runs on every scan. */
    private const TIMEOUT_MS = 2000;

    private GitProcessRunnerInterface $runner;

    /** Defaults to a real subprocess runner; a test double replaces it without touching callers. */
    public function __construct(?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner();
    }

    /** The root's current HEAD as a sha, or null when git cannot answer. */
    public function resolve(string $projectRoot): ?string
    {
        try {
            $output = trim($this->runner->run(
                ['git', '--no-optional-locks', '--no-pager', '-C', $projectRoot, 'rev-parse', '--verify', 'HEAD'],
                self::TIMEOUT_MS,
                'scan head',
            ));
        } catch (Throwable) {
            return null;
        }
        // Only a sha may be stored. Anything else would later be handed to
        // `git diff` as a revision, where it would fail on every probe.
        return preg_match('/^[a-f0-9]{40,64}$/', $output) === 1 ? $output : null;
    }
}
