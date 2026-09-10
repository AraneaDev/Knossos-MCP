<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Everything a session brief could render, already gathered and already sorted.
 *
 * Separated from the renderer so the renderer stays a pure function of this
 * object: every state and every budget is then testable without a database,
 * which matters because these strings are read at the start of every session
 * and are the most expensive thing in the feature to get wrong.
 */
final readonly class SessionBrief
{
    /**
     * @param string $state one of fresh, stale, unverified, missing, unscanned
     * @param list<string> $rules boundary policies, already formatted one per line
     * @param list<string> $notes annotations from earlier sessions
     * @param list<string> $entryPoints graph-derived, rendered only when fresh
     * @param list<string> $hubs graph-derived, rendered only when fresh
     * @param bool $pathAllowed whether $path lies inside a root the CLI knows
     *   about. Gathered for every state and consulted by four of the five
     *   verdicts. Being scanned is no evidence that the root was ever
     *   permitted: `knossos scan` hands the root it was given to the guard as
     *   its own allow-list, so a CLI scan self-authorises any path and leaves
     *   a `stale` or `unverified` project that `scan_project` would refuse.
     *   Only `fresh` ignores the flag, because it asks for nothing that could
     *   be refused. Advisory rather than definitive: a server started with
     *   `--allow-root` flags has roots this check cannot see, so a false
     *   value here can be a false positive. That is acceptable only because
     *   the three root sources are unioned, so the fix the verdict names
     *   (`knossos allow-root`) is a no-op when the root was already allowed
     *   through one of those invisible sources. A false value must never be
     *   read as proof that a scan will fail.
     * @param bool $pathExists whether $path is a directory the CLI can reach.
     *   Separate from $pathAllowed because the two have different remedies and
     *   only one of them has a command behind it. A path that is not there is
     *   not a path `knossos allow-root` would accept either, so collapsing the
     *   two would answer a missing directory with a grant that is itself
     *   refused for the same reason. False forces the verdict to say so and to
     *   recommend nothing; $pathAllowed is then not consulted at all, because
     *   "outside every root" is not a useful thing to say about a directory
     *   that does not exist.
     * @param string|null $queriedPath the path the caller actually asked
     *   about, when that is not $path. $path is the resolved project's root,
     *   and the two differ whenever a project was found by walking parents:
     *   the common, wanted case of a session started in a subdirectory, and
     *   the case this field exists for, a separate repository that merely
     *   lives inside a scanned one. Nothing distinguishes those two from the
     *   database, and guessing (a nested `.git`, say) would be wrong often
     *   enough to be worse than saying which project this is. So the renderer
     *   discloses the ancestry and lets the reader decide. Null, or equal to
     *   $path, means the question was about the project root itself and there
     *   is nothing to disclose.
     * @param bool $queriedPathExists whether $queriedPath is a directory that
     *   is actually there. Distinct from $pathExists, which answers for the
     *   resolved project root and is therefore true whenever a project
     *   resolves at all: an ancestor cannot be scanned and absent. The
     *   disclosure is the one line that makes a claim about the path the
     *   caller named rather than about the project, so it is the one line that
     *   can assert a directory into existence. Meaningless, and ignored, when
     *   $queriedPath is null.
     */
    public function __construct(
        public string $state,
        public ?string $projectId,
        public ?string $projectName,
        public string $path,
        public ?int $ageSeconds,
        public int $changedFiles,
        public int $trackedFiles,
        public array $rules = [],
        public array $notes = [],
        public array $entryPoints = [],
        public array $hubs = [],
        public bool $pathAllowed = true,
        public bool $pathExists = true,
        public ?string $queriedPath = null,
        public bool $queriedPathExists = true,
    ) {}
}
