<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;
use Throwable;

/**
 * What each recorded scan changed (the `scan_ledger` table).
 *
 * A turn brief reports what a turn did by comparing the graph before its own
 * scan with the graph after it. When another writer scanned the turn's edits
 * first (the live watcher, the pane's rescan, another session's turn brief),
 * the graph before the brief's scan already holds them, so that comparison
 * would report nothing. Each writer that records here keeps, per scan, the
 * snapshot it started from, the one it produced, each changed file's content
 * hash before it, and (when the writer computed them) the policy violations
 * those files held before it. {@see self::since()} reads them back in order
 * from the snapshot a turn or a session started at.
 *
 * A session with a live watcher records a scan for every save, so a day's
 * session outgrows any fixed number of entries. Past {@see self::KEPT} the
 * oldest entries are merged into one {@see ScanLedgerSpan} rather than
 * dropped: a session that began among them is still answered, and storage
 * stays bounded. The merge runs in the recording's own transaction, under
 * the write lease every recording writer holds, so no other recording can
 * interleave with it.
 */
final readonly class ScanLedger
{
    /** Entries kept per project before the oldest are merged into a span. */
    public const KEPT = 200;

    /** Entries left after a merge, the span included: the next merge is due this many scans later. */
    public const COMPACTED = 150;

    /**
     * The most changed files one entry (or span) lists. A branch switch can
     * change thousands; such an entry keeps no paths, only that it was cut,
     * and a turn whose start it spans gets no policy verdict ({@see self::since()}).
     */
    public const MAX_FILES = 2000;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /** The project's active snapshot id, or null when it has none. */
    public function activeSnapshot(string $projectId): ?string
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id FROM projects WHERE id = ?');
        $statement->execute([$projectId]);
        $id = $statement->fetchColumn();

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Every tracked file's content hash.
     *
     * @return array<string, string> relative path => content hash
     */
    public function hashes(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT relative_path, content_hash FROM files WHERE project_id = ?');
        $statement->execute([$projectId]);
        $hashes = [];
        // An all-digit path comes back from FETCH_KEY_PAIR as an integer key; strval keeps it a path.
        foreach ($statement->fetchAll(PDO::FETCH_KEY_PAIR) as $path => $hash) {
            $hashes[(string) $path] = (string) $hash;
        }

        return $hashes;
    }

    /**
     * Records one scan: which files it changed and what each held before.
     *
     * A scan that moved no snapshot and changed nothing says nothing and is
     * not recorded. One that changed more than {@see self::MAX_FILES} files
     * is recorded as cut, without them. Past {@see self::KEPT} entries the
     * oldest are merged ({@see self::compact()}), in the same transaction.
     *
     * @param array<string, string> $before every tracked file's hash before the scan
     * @param array<string, string> $after every tracked file's hash after it
     * @param array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}>|null $baselines
     *        the violations each file held before the scan, by path, for the files the writer checked; null when it checked none
     */
    public function record(string $projectId, ?string $from, string $to, array $before, array $after, ?array $baselines = null): void
    {
        $changed = [];
        foreach ($after as $path => $hash) {
            if (($before[$path] ?? null) !== $hash) {
                $changed[(string) $path] = $before[$path] ?? null;
            }
        }
        foreach (array_diff_key($before, $after) as $path => $hash) {
            $changed[(string) $path] = $hash;
        }
        if ($changed === [] && $from === $to) {
            return;
        }
        $cut = count($changed) > self::MAX_FILES;
        $kept = $baselines === null || $cut ? null : array_intersect_key($baselines, $changed);
        $changes = $cut ? ['before' => (object) [], 'baselines' => null, 'truncated' => true] : ['before' => (object) $changed, 'baselines' => $kept === null ? null : (object) $kept];
        // One transaction for the entry and the merge it may set off: a reader never sees half a merge.
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $insert = $this->pdo->prepare('INSERT INTO scan_ledger(project_id, from_snapshot, to_snapshot, recorded_at, changes_json) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$projectId, $from, $to, time(), self::json($changes)]);
            $this->compact($projectId);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $failure) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $failure;
        }
    }

    /**
     * What the recorded scans since snapshot `$since` changed, as of before
     * the first of them that touched each file.
     *
     * `before` maps every file a scan since then changed to its hash before
     * that scan (null: the scan added it). `baselines` maps those files to the
     * violations they held then (null: that scan did not check them, or it
     * was merged into a span). `scans` maps them to the snapshot each recorded
     * scan that changed them produced, in recording order, so a reader can
     * tell whose scan took a change in (a span's earlier scans by their key,
     * {@see ScanLedgerSpan::key()}). `chain` lists those scans themselves, in
     * recording order: the snapshot each produced, when it was recorded (Unix
     * seconds) and how many files it changed; a span is one item, with how
     * many scans it `merged`.
     *
     * `$since` may also lie inside a span, between two of its scans: then the
     * span's files that a later scan of it changed are reported, with the
     * hash before the span's first change to them, and `approximate` is true:
     * a file changed just before `$since` and again after it reads as it was
     * before the earlier change, and none of them carries a policy baseline.
     *
     * Null altogether when the ledger cannot account for every scan since:
     * none recorded starts at (or passes) `$since`, one in between was not
     * recorded or was recorded cut ({@see self::MAX_FILES}), or the last
     * recorded one is not the project's active snapshot.
     *
     * @return array{before: array<string, string|null>, baselines: array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}|null>, scans: array<string, list<string>>, chain: list<array{snapshot_id: string, at: int, files: int, merged?: int}>, approximate: bool}|null
     */
    public function since(string $projectId, string $since): ?array
    {
        return $this->readingAsOne(fn(): ?array => $this->sinceNow($projectId, $since));
    }

    /**
     * {@see self::since()}, read inside whatever transaction the caller holds.
     *
     * @return array{before: array<string, string|null>, baselines: array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}|null>, scans: array<string, list<string>>, chain: list<array{snapshot_id: string, at: int, files: int, merged?: int}>, approximate: bool}|null
     */
    private function sinceNow(string $projectId, string $since): ?array
    {
        $active = $this->activeSnapshot($projectId);
        if ($active === $since) {
            return ['before' => [], 'baselines' => [], 'scans' => [], 'chain' => [], 'approximate' => false];
        }
        $rows = array_values(array_filter($this->rows($projectId), static fn(array $row): bool => !$row['cut']));
        $found = $active === null ? null : self::reaching($rows, $since, $active);
        if ($found === null) {
            return null;
        }
        [$chain, $start] = $found;
        $before = [];
        $baselines = [];
        $scans = [];
        $order = [];
        foreach ($chain as $n => $index) {
            $row = $rows[$index];
            if (isset($row['changes']['span'])) {
                $part = ScanLedgerSpan::after(ScanLedgerSpan::of($row), $n === 0 ? $start : -1);
                $order[] = ['snapshot_id' => $row['to'], 'at' => $row['at'], 'files' => count($part['before']), 'merged' => $part['steps']];
                $changed = $part['before'];
                $named = $part['scans'];
                $held = [];
            } else {
                $order[] = ['snapshot_id' => $row['to'], 'at' => $row['at'], 'files' => count($row['changes']['before'])];
                $changed = $row['changes']['before'];
                $named = array_map(static fn(): array => [$row['to']], $changed);
                $held = $row['changes']['baselines'] ?? [];
            }
            foreach ($changed as $path => $hash) {
                $path = (string) $path;
                $scans[$path] = [...$scans[$path] ?? [], ...$named[$path] ?? []];
                if (array_key_exists($path, $before)) {
                    continue;
                }
                $before[$path] = $hash === null ? null : (string) $hash;
                $baselines[$path] = $held[$path] ?? null;
            }
        }

        return ['before' => $before, 'baselines' => $baselines, 'scans' => $scans, 'chain' => $order, 'approximate' => $start >= 0];
    }

    /**
     * The oldest snapshot {@see self::since()} can still answer for, and
     * when the scan that left it was recorded (Unix seconds; for a span, its
     * first scan); null when none leads to the active snapshot.
     *
     * @return array{snapshot_id: string, at: int}|null
     */
    public function reach(string $projectId): ?array
    {
        return $this->readingAsOne(fn(): ?array => $this->reachNow($projectId));
    }

    /**
     * {@see self::reach()}, read inside whatever transaction the caller holds.
     *
     * @return array{snapshot_id: string, at: int}|null
     */
    private function reachNow(string $projectId): ?array
    {
        $active = $this->activeSnapshot($projectId);
        if ($active === null) {
            return null;
        }
        $rows = array_values(array_filter($this->rows($projectId), static fn(array $row): bool => !$row['cut']));
        foreach ($rows as $i => $row) {
            if ($row['from'] !== '' && self::chain($rows, $row['from'], $active, $i - 1) !== null) {
                return ['snapshot_id' => $row['from'], 'at' => (int) ($row['changes']['span']['began'] ?? $row['at'])];
            }
        }

        return null;
    }

    /**
     * `$read` in one read transaction (unless the caller already holds one):
     * the active snapshot and the entries are then one moment's, and a writer
     * that moves the project on and merges or drops entries in between
     * cannot leave a chain that leads nowhere.
     *
     * @template T
     * @param Closure(): T $read
     * @return T
     */
    private function readingAsOne(Closure $read): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $read();
        }
        $this->pdo->beginTransaction();
        try {
            return $read();
        } finally {
            // Nothing was written: ending the read is all a rollback does here.
            $this->pdo->rollBack();
        }
    }

    /**
     * Keeps the project's entries bounded without losing what a session can
     * still ask for. Past {@see self::KEPT} entries: every entry no chain to
     * the active snapshot can pass through goes (it can never answer
     * {@see self::since()}), then the oldest of the rest are merged into one
     * span, down to {@see self::COMPACTED} entries. The merge only joins
     * entries that follow each other, and drops one that repeats scans the
     * span holds; should two not follow each other, the merge stops there,
     * and past {@see self::KEPT} the oldest entries are dropped as before.
     * The same rows always merge into the same span.
     */
    private function compact(string $projectId): void
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM scan_ledger WHERE project_id = ?');
        $count->execute([$projectId]);
        if ((int) $count->fetchColumn() <= self::KEPT) {
            return;
        }
        $rows = $this->rows($projectId);
        $live = self::live($rows, $this->activeSnapshot($projectId));
        $gone = [];
        $kept = [];
        foreach ($rows as $i => $row) {
            if ($live[$i]) {
                $kept[] = $row;
            } else {
                $gone[] = $row['id'];
            }
        }
        if (count($kept) > self::KEPT) {
            $span = ScanLedgerSpan::of($kept[0]);
            $last = count($kept) - self::COMPACTED;
            for ($i = 1; $i <= $last; ++$i) {
                $next = ScanLedgerSpan::of($kept[$i]);
                $joined = ScanLedgerSpan::repeats($span, $next) ? $span : ScanLedgerSpan::join($span, $next);
                if ($joined === null) {
                    break;
                }
                $span = $joined;
                $gone[] = $kept[$i]['id'];
            }
            $update = $this->pdo->prepare('UPDATE scan_ledger SET from_snapshot = ?, to_snapshot = ?, recorded_at = ?, changes_json = ? WHERE id = ?');
            $update->execute([$span['from'] === '' ? null : $span['from'], $span['to'], $span['at'], self::json(ScanLedgerSpan::encode($span)), $kept[0]['id']]);
        }
        foreach (array_chunk($gone, 500) as $ids) {
            $this->pdo->prepare('DELETE FROM scan_ledger WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
        }
        $prune = $this->pdo->prepare('DELETE FROM scan_ledger WHERE project_id = ? AND id <= (SELECT id FROM scan_ledger WHERE project_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?)');
        $prune->execute([$projectId, $projectId, self::KEPT]);
    }

    /**
     * Which entries a chain to `$active` can pass through: one that ends
     * there, or at a snapshot a later such entry starts from. An entry
     * recorded cut never can.
     *
     * @param list<array{id: int, from: string, to: string, at: int, cut: bool, changes: array<string, mixed>}> $rows
     * @return list<bool>
     */
    private static function live(array $rows, ?string $active): array
    {
        $live = array_fill(0, count($rows), false);
        $leads = [];
        for ($i = count($rows) - 1; $i >= 0; --$i) {
            $row = $rows[$i];
            $live[$i] = $active !== null && !$row['cut'] && ($row['to'] === $active || isset($leads[$row['to']]));
            if ($live[$i]) {
                $leads[$row['from']] = true;
            }
        }

        return $live;
    }

    /**
     * The entries that lead from `$since` to `$active` and, when `$since` is
     * a snapshot inside the first of them (a span), the step it stands at
     * (-1: it is where the first one starts); null when none lead there.
     * A chain that starts at `$since` wins over one through a span.
     *
     * @param list<array{id: int, from: string, to: string, at: int, cut: bool, changes: array<string, mixed>}> $rows
     * @return array{0: list<int>, 1: int}|null
     */
    private static function reaching(array $rows, string $since, string $active): ?array
    {
        $chain = self::chain($rows, $since, $active, -1);
        if ($chain !== null) {
            return $chain === [] ? null : [$chain, -1];
        }
        foreach ($rows as $i => $row) {
            $step = isset($row['changes']['span']) ? ScanLedgerSpan::stepOf(ScanLedgerSpan::of($row), $since) : null;
            $rest = $step === null ? null : self::chain($rows, $row['to'], $active, $i);
            if ($step !== null && $rest !== null) {
                return [[$i, ...$rest], $step];
            }
        }

        return null;
    }

    /**
     * The project's entries in recording order, decoded; `cut` for one
     * recorded without its files.
     *
     * @return list<array{id: int, from: string, to: string, at: int, cut: bool, changes: array<string, mixed>}>
     */
    private function rows(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT id, from_snapshot, to_snapshot, recorded_at, changes_json FROM scan_ledger WHERE project_id = ? ORDER BY id');
        $statement->execute([$projectId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $changes = json_decode((string) $row['changes_json'], true, 512, JSON_THROW_ON_ERROR);
            $rows[] = ['id' => (int) $row['id'], 'from' => (string) $row['from_snapshot'], 'to' => (string) $row['to_snapshot'], 'at' => (int) $row['recorded_at'],
                'cut' => ($changes['truncated'] ?? false) === true, 'changes' => $changes];
        }

        return $rows;
    }

    /**
     * An entry's changes as the table stores them.
     *
     * @param array<string, mixed> $changes
     */
    private static function json(array $changes): string
    {
        return json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The entries, in the order they were recorded, that lead from snapshot
     * `$at` to `$active`, each starting where the one before it ended; null
     * when none do. Two entries can start at the same snapshot (two writers
     * that read the graph before either scanned); the first chain found in
     * recording order wins, so the answer never depends on which of them a
     * reader meets first. Past `$active` a chain goes on only when a later
     * entry leads back to it.
     *
     * @param list<array{id: int, from: string, to: string, at: int, cut: bool, changes: array<string, mixed>}> $rows
     * @param array<string, true> $dead the starts already known to lead nowhere, so no start is walked twice
     * @return list<int>|null indexes into `$rows`
     */
    private static function chain(array $rows, string $at, string $active, int $after, array &$dead = []): ?array
    {
        $key = $after . "\0" . $at;
        if (isset($dead[$key])) {
            return null;
        }
        $count = count($rows);
        for ($i = $after + 1; $i < $count; ++$i) {
            if ($rows[$i]['from'] !== $at) {
                continue;
            }
            $rest = self::chain($rows, $rows[$i]['to'], $active, $i, $dead);
            if ($rest !== null) {
                return [$i, ...$rest];
            }
        }
        if ($at === $active) {
            return [];
        }
        $dead[$key] = true;

        return null;
    }
}
