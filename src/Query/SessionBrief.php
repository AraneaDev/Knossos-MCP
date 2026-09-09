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
    ) {}
}
