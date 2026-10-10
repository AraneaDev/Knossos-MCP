<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * The transitive part of read-set invalidation: from the changed paths out to
 * every owner that must be rebuilt.
 *
 * A rebuilt owner counts as a change to its own file, unless its scanner's
 * reads name every file its facts came from, and so queues its readers. The
 * walk holds what it has visited, so each owner, read group and scanner is
 * expanded at most once.
 */
final class InvalidationWalk
{
    /** @var array<string, true> owners to rebuild */
    private array $invalidated = [];

    /** @var array<string, true> paths counted as changed, the index's and every rebuilt owner's own */
    private array $changed;

    /** @var list<string> changed paths whose owners and readers are not yet visited */
    private array $queue;

    /**
     * Scanner id to whether its incomplete rows have been rebuilt. The first
     * owner a scanner rebuilds also rebuilds its rows whose reads are
     * incomplete, once per scanner, so their readers are reached in turn.
     *
     * @var array<string, bool>
     */
    private array $incompleteDue = [];

    /** @var array<string, true> read groups whose owners have been queued */
    private array $expandedGroups = [];

    /** @var array<string, true> scanners rebuilt whole */
    private array $rebuiltScanners = [];

    /** @param array<string, true> $directReads scanner ids whose rebuilt owners do not count as a change to their own file */
    public function __construct(
        private readonly CachedReads $cached,
        private readonly ReadIndex $index,
        private readonly array $directReads,
    ) {
        $this->changed = $index->changed;
        $this->queue = array_map('strval', array_keys($index->changed));
    }

    /** Rebuild one owner, returning whether it was not already rebuilt. */
    public function invalidate(string $owner): bool
    {
        if (isset($this->invalidated[$owner])) {
            return false;
        }
        $this->invalidated[$owner] = true;
        $ownPath = $this->cached->rows[$owner]['file_path'];
        $scanner = $this->cached->rows[$owner]['scanner_id'];
        if (!isset($this->changed[$ownPath]) && !isset($this->directReads[$scanner])) {
            $this->changed[$ownPath] = true;
            $this->queue[] = $ownPath;
        }
        $this->dueIncomplete($scanner);

        return true;
    }

    /** Mark a scanner's incomplete rows for rebuilding, unless they already were. */
    public function dueIncomplete(string $scanner): void
    {
        if (isset($this->index->incompleteOf[$scanner]) && !array_key_exists($scanner, $this->incompleteDue)) {
            $this->incompleteDue[$scanner] = false;
        }
    }

    /**
     * Rebuilds the incomplete rows of every scanner that has rebuilt an
     * owner since the last call, each scanner once.
     */
    private function fanOutIncomplete(): void
    {
        while (($scanner = array_search(false, $this->incompleteDue, true)) !== false) {
            $this->incompleteDue[$scanner] = true;
            foreach ($this->index->incompleteOf[$scanner] as $incomplete) {
                $this->invalidate($incomplete);
            }
        }
    }

    /**
     * Rebuild every row of a scanner. A scanner that does not attribute reads
     * is rebuilt whole as soon as any of its files is, and every file it
     * rebuilds is a change its readers in other scanners see.
     */
    public function rebuildScanner(string $scanner): void
    {
        if (isset($this->rebuiltScanners[$scanner])) {
            return;
        }
        $this->rebuiltScanners[$scanner] = true;
        foreach ($this->index->rowsOfScanner[$scanner] ?? [] as $owner) {
            $this->invalidate($owner);
        }
    }

    /** Visit the owners and readers of every queued path until nothing is left to rebuild. */
    public function drain(): void
    {
        do {
            $this->fanOutIncomplete();
            $path = array_pop($this->queue);
            if ($path === null) {
                break;
            }
            $owners = ($this->index->ownersOfFile[$path] ?? []) + ($this->index->readersOf[$path] ?? []);
            foreach ($this->index->groupsOf[$path] ?? [] as $group => $true) {
                if (!isset($this->expandedGroups[$group])) {
                    $this->expandedGroups[$group] = true;
                    $owners += $this->index->ownersOfGroup[(string) $group];
                }
            }
            foreach ($owners as $owner => $true) {
                $owner = (string) $owner;
                if ($this->invalidate($owner) && isset($this->index->unattributed[$this->cached->rows[$owner]['scanner_id']])) {
                    $this->rebuildScanner($this->cached->rows[$owner]['scanner_id']);
                }
            }
        } while (true);
    }

    /**
     * Every owner rebuilt so far.
     *
     * @return array<string, true> keyed by owner key
     */
    public function invalidated(): array
    {
        return $this->invalidated;
    }
}
