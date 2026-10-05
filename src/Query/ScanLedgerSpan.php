<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Consecutive {@see ScanLedger} entries merged into one, so a long session's
 * oldest scans stay accountable in bounded space.
 *
 * A span starts where the first scan it holds started and ends where the
 * last ended. It keeps, per file any of its scans changed, the hash before
 * the first of them (null: one added it), which of its scans was the first to
 * change it, and the newest {@see self::SCANS_PER_FILE} that did, as step
 * numbers counted from the span's first scan. The snapshot each step
 * produced is kept as a short key ({@see self::key()}, at most
 * {@see self::MAX_POINTS} of them, the newest), so a session that began
 * between two of the span's scans is still found in it, and each file can
 * still name the scans that changed it. A span keeps no policy baselines.
 *
 * A span for more than {@see ScanLedger::MAX_FILES} files is cut, as an entry
 * is: it keeps no files, and no chain passes through it.
 *
 * Every method is pure: the ledger reads and writes the rows.
 *
 * @phpstan-type Span array{from: string, to: string, began: int, at: int, steps: int, cut: int, points: list<string>, before: array<string, string|null>, first: array<string, int>, scans: array<string, list<int>>}
 */
final readonly class ScanLedgerSpan
{
    /** The snapshots a span keeps keys for, the newest: a session that began among older ones finds no start in it. */
    public const MAX_POINTS = 5000;

    /** The newest scans named per file in a span, as many as a session's changes name per file. */
    public const SCANS_PER_FILE = SessionChangesService::MAX_SCANS;

    /** How much of a snapshot id a span keeps: the `scan_` prefix and 48 bits of its hash. */
    private const KEY = 17;

    /** A snapshot id as a span keeps it: ids that long differ within their first 48 bits of hash. */
    public static function key(string $snapshot): string
    {
        return strlen($snapshot) > self::KEY ? substr($snapshot, 0, self::KEY) : $snapshot;
    }

    /**
     * One ledger row as a span: a single scan is a span of one step.
     *
     * @param array{from: string, to: string, at: int, changes: array<string, mixed>} $row
     * @return Span
     */
    public static function of(array $row): array
    {
        $changes = $row['changes'];
        $before = [];
        foreach ($changes['before'] ?? [] as $path => $hash) {
            $before[(string) $path] = $hash === null ? null : (string) $hash;
        }
        $span = $changes['span'] ?? null;
        if (!is_array($span)) {
            return ['from' => $row['from'], 'to' => $row['to'], 'began' => $row['at'], 'at' => $row['at'], 'steps' => 1, 'cut' => 0, 'points' => [self::key($row['to'])],
                'before' => $before, 'first' => array_fill_keys(array_keys($before), 0), 'scans' => array_fill_keys(array_keys($before), [0])];
        }
        $first = [];
        $scans = [];
        foreach ($span['files'] ?? [] as $path => $steps) {
            $steps = array_map('intval', (array) $steps);
            $first[(string) $path] = (int) array_shift($steps);
            $scans[(string) $path] = $steps;
        }
        return ['from' => $row['from'], 'to' => $row['to'], 'began' => (int) ($span['began'] ?? $row['at']), 'at' => $row['at'], 'steps' => (int) ($span['steps'] ?? 1), 'cut' => (int) ($span['cut'] ?? 0),
            'points' => array_values(array_map('strval', $span['points'] ?? [])), 'before' => $before, 'first' => $first, 'scans' => $scans];
    }

    /**
     * Whether `$next` only repeats scans `$span` already holds: it starts and
     * ends at two of the span's snapshots, in that order. A writer that
     * recorded a scan its child process had already recorded leaves such a pair.
     *
     * @param Span $span
     * @param Span $next
     */
    public static function repeats(array $span, array $next): bool
    {
        $from = $next['from'] === $span['from'] ? -1 : array_search(self::key($next['from']), $span['points'], true);
        $to = array_search(self::key($next['to']), $span['points'], true);

        return $from !== false && $to !== false && $to > $from;
    }

    /**
     * `$span` followed by `$next`, which starts where it ended; null when it
     * does not. Each file keeps the hash before its first change in either;
     * past {@see self::MAX_POINTS} the oldest snapshot keys are let go.
     *
     * @param Span $span
     * @param Span $next
     * @return Span|null
     */
    public static function join(array $span, array $next): ?array
    {
        if ($next['from'] !== $span['to']) {
            return null;
        }
        $offset = $span['steps'];
        // A span that already let keys go leaves a hole no key can stand for: the older keys go too.
        $points = $next['cut'] > 0 ? $next['points'] : [...$span['points'], ...$next['points']];
        $cut = $next['cut'] > 0 ? $offset + $next['cut'] : $span['cut'];
        $over = count($points) - self::MAX_POINTS;
        if ($over > 0) {
            $points = array_slice($points, $over);
            $cut += $over;
        }
        $joined = ['from' => $span['from'], 'to' => $next['to'], 'began' => $span['began'], 'at' => $next['at'], 'steps' => $offset + $next['steps'], 'cut' => $cut, 'points' => $points,
            'before' => $span['before'], 'first' => $span['first'], 'scans' => $span['scans']];
        foreach ($next['before'] as $path => $hash) {
            if (!array_key_exists($path, $joined['before'])) {
                $joined['before'][$path] = $hash;
                $joined['first'][$path] = $next['first'][$path] + $offset;
            }
            $later = array_map(static fn(int $step): int => $step + $offset, $next['scans'][$path] ?? []);
            $joined['scans'][$path] = array_slice([...$joined['scans'][$path] ?? [], ...$later], -self::SCANS_PER_FILE);
        }

        return $joined;
    }

    /**
     * The span as a ledger row stores it; cut, with no files, past
     * {@see ScanLedger::MAX_FILES}.
     *
     * @param Span $span
     * @return array<string, mixed>
     */
    public static function encode(array $span): array
    {
        $head = ['began' => $span['began'], 'steps' => $span['steps']];
        if (count($span['before']) > ScanLedger::MAX_FILES) {
            return ['before' => (object) [], 'baselines' => null, 'truncated' => true, 'span' => $head];
        }
        $files = [];
        foreach ($span['before'] as $path => $hash) {
            $files[$path] = [$span['first'][$path], ...$span['scans'][$path] ?? []];
        }

        return ['before' => (object) $span['before'], 'baselines' => null, 'span' => $head + ['cut' => $span['cut'], 'points' => $span['points'], 'files' => (object) $files]];
    }

    /**
     * The step at which snapshot `$snapshot` stands inside the span: the
     * number of the scan that produced it, when a later scan of the span
     * follows it; null when it is not one the span still keeps a key for.
     *
     * @param Span $span
     */
    public static function stepOf(array $span, string $snapshot): ?int
    {
        $index = array_search(self::key($snapshot), $span['points'], true);
        if ($index === false) {
            return null;
        }
        $step = $span['cut'] + $index;

        return $step < $span['steps'] - 1 ? $step : null;
    }

    /**
     * What the span's scans after step `$after` changed (-1: all of them):
     * each file a later scan changed, with its hash before the span's first
     * change to it, and the scans after `$after` that changed it, named by
     * their snapshot (the span's last by its full id, the others by their
     * key). `steps` is how many scans that is.
     *
     * @param Span $span
     * @return array{before: array<string, string|null>, scans: array<string, list<string>>, steps: int}
     */
    public static function after(array $span, int $after): array
    {
        $before = [];
        $scans = [];
        $last = $span['steps'] - 1;
        foreach ($span['before'] as $path => $hash) {
            $steps = $span['scans'][$path] ?? [];
            if (($steps === [] ? $span['first'][$path] : $steps[count($steps) - 1]) <= $after) {
                continue;
            }
            $before[$path] = $hash;
            $named = [];
            foreach ($steps as $step) {
                if ($step > $after && $step >= $span['cut']) {
                    $named[] = $step === $last ? $span['to'] : $span['points'][$step - $span['cut']];
                }
            }
            $scans[$path] = $named;
        }

        return ['before' => $before, 'scans' => $scans, 'steps' => $last - $after];
    }
}
