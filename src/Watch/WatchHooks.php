<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;

/**
 * What a watcher that shares its graph with other writers is told about
 * them. Every hook is optional; a plain `knossos watch` sets none.
 *
 * - `current` says whether the graph already holds the given paths (every
 *   path when null) at the fingerprint's hashes: another writer scanned
 *   them, so the watcher's own scan would only repeat it.
 * - `activeSnapshot` reads the project's active snapshot cheaply, so the
 *   watcher notices another writer's scan and says so.
 * - `alive` says whether whoever started the watcher is still there; false
 *   stops it, so a watcher outliving its session never keeps polling.
 * - `heartbeat` runs every `heartbeatMs` while the watcher polls (a lock's
 *   heartbeat, say), with the snapshot the watcher knows.
 */
final readonly class WatchHooks
{
    /**
     * @param string|null $projectId the project the graph holds, for a result when no scan runs
     * @param (Closure(array<string, string>, list<string>|null): bool)|null $current
     * @param (Closure(): ?string)|null $activeSnapshot
     * @param (Closure(): bool)|null $alive
     * @param (Closure(?string, string): void)|null $heartbeat called with the snapshot id and the watcher's phase
     */
    public function __construct(
        public ?string $projectId = null,
        public ?Closure $current = null,
        public ?Closure $activeSnapshot = null,
        public ?Closure $alive = null,
        public ?Closure $heartbeat = null,
        public int $heartbeatMs = 15_000,
    ) {}
}
