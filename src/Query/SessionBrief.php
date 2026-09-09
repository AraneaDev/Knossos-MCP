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
    ) {}
}
