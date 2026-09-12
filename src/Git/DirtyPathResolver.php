<?php

declare(strict_types=1);

namespace Knossos\Git;

use Knossos\Discovery\DiscoveredFile;
use Throwable;

/**
 * Which of the files a scan read held content the recorded commit does not.
 *
 * Deliberately answered from the bytes discovery hashed, not from asking git
 * what is dirty now. Those are two different moments. A tracked file that was
 * modified while discovery read it and restored before anything else ran is
 * clean by every later question git can be asked, while the hash the scan
 * stored is of the modified bytes — and a set that omits it is marked complete
 * and believed. Comparing discovery's own Git blob ids against the commit's
 * tree has no such window: the tree of a captured commit does not change, so
 * the comparison describes exactly the instant each file was read, whenever it
 * happens to be made.
 *
 * Returns null rather than throwing for every failure: a project that is not a
 * repository, a missing binary, a timeout, a tree listing past the runner's
 * output ceiling. None of those is worth failing a scan over, and each one
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
     * The scanned paths whose read bytes differ from $head, or null when git
     * could not answer.
     *
     * A file the commit's tree does not hold at all is left out: it was
     * untracked when the scan read it, so there is no committed content for
     * its stored hash to disagree with, and the oracle's own visibility
     * cross-check is what covers such a file disappearing later.
     *
     * A file whose blob id discovery could not pin down is included, because
     * "cannot be compared" must not resolve to "matched".
     *
     * @param list<DiscoveredFile> $files what discovery read, with the blob id of the bytes it read
     */
    public function resolve(string $projectRoot, string $head, array $files): ?DirtyPathSet
    {
        $tree = $this->tree($projectRoot, $head);
        if ($tree === null) {
            return null;
        }
        $dirty = [];
        foreach ($files as $file) {
            $committed = $tree[$file->relativePath] ?? null;
            if ($committed === null) {
                continue;
            }
            if ($file->gitBlobHash === null || $file->gitBlobHash !== $committed) {
                $dirty[] = $file->relativePath;
            }
        }

        return DirtyPathSet::of($dirty);
    }

    /**
     * The commit's tree as path => blob id, scoped to the scanned root.
     *
     * `-C $projectRoot` with a `.` pathspec is what keeps a monorepo's sibling
     * packages out and makes the reported paths relative to the scanned root,
     * which is the form the graph stores its own paths in. Entries that are
     * not blobs (a submodule's commit entry) are dropped: nothing discovery
     * read is one.
     *
     * @return array<string, string>|null relative path => blob id, or null when git could not answer
     */
    private function tree(string $projectRoot, string $head): ?array
    {
        try {
            $output = $this->runner->run(
                ['git', '--no-optional-locks', '--no-pager', '-C', $projectRoot, 'ls-tree', '-r', '-z', $head, '--', '.'],
                self::TIMEOUT_MS,
                'scan head tree',
            );
        } catch (Throwable $error) {
            // A scan with no recorded set degrades to an oracle that declines,
            // which looks like nothing at all from outside: no warning, just a
            // probe that never gets cheaper. Never stdout, which carries MCP
            // protocol frames.
            error_log('knossos scan head tree: git could not answer (' . $error->getMessage() . ')');

            return null;
        }

        $tree = [];
        foreach (explode("\0", rtrim($output, "\0")) as $entry) {
            // `<mode> SP <type> SP <object> TAB <path>`, and the path may hold
            // anything but a NUL, which is why the split is on the first tab
            // rather than on whitespace.
            $tab = strpos($entry, "\t");
            if ($tab === false) {
                continue;
            }
            $fields = explode(' ', substr($entry, 0, $tab));
            if (count($fields) !== 3 || $fields[1] !== 'blob') {
                continue;
            }
            $tree[substr($entry, $tab + 1)] = $fields[2];
        }

        return $tree;
    }
}
