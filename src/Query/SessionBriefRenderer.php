<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Renders a session brief as terse text for injection at session start.
 *
 * Not a thin wrapper over the agent brief, deliberately. That one is read once
 * by a person and must stand alone; this one is billed to an agent on every
 * session start, resume and compact, so it is action-shaped and drops the node
 * counts and language mix the agent brief leads with. Those read impressively
 * and change no decision.
 *
 * Sections that are derived from the graph (entry points, hubs) appear only
 * when the graph is fresh. Boundary rules come from `knossos.json` and notes
 * from the annotations table, neither of which a stale scan invalidates, so
 * both survive every verdict.
 */
final readonly class SessionBriefRenderer
{
    /**
     * Bounds on the optional sections per state, not on the whole output.
     *
     * The verdict line and the skill pointer sit below this budget as an
     * irreducible floor: the verdict embeds the project path, which is
     * unbounded, so "never exceed the budget" and "never drop the verdict or
     * pointer" cannot both hold for every path. The floor wins, because the
     * path must be verbatim or `scan_project path=...` is not a command
     * anyone can run, and the pointer is what arms the skill.
     */
    public const BUDGETS = [
        'fresh' => 1200,
        'stale' => 500,
        'unverified' => 500,
        'missing' => 500,
        'unscanned' => 200,
    ];

    private const POINTER = 'Ask before grepping for structure: the `knossos` skill.';

    /** The brief as injected text: optional sections kept within budget, verdict and pointer always present. */
    public function render(SessionBrief $brief): string
    {
        $budget = self::BUDGETS[$brief->state] ?? self::BUDGETS['unscanned'];
        $verdict = $this->verdict($brief);
        if ($brief->state === 'unscanned') {
            return $this->fit([$verdict], $budget);
        }

        $lines = [$verdict, sprintf('Knossos %s (%s)', (string) $brief->projectId, (string) $brief->projectName)];
        $sections = [
            $this->section('Rules', $brief->rules),
            $this->section('Notes', $brief->notes),
        ];
        if ($brief->state === 'fresh') {
            $sections[] = $this->section('Entry', $brief->entryPoints);
            $sections[] = $this->section('Hubs', $brief->hubs);
        }
        foreach ($sections as $section) {
            if ($section !== null) {
                $lines[] = $section;
            }
        }
        return $this->fit($lines, $budget);
    }

    /**
     * One line, always. There is nothing to skim past in a single line, which is
     * the whole reason this is not a paragraph.
     *
     * Four of the five states have two forms, chosen by whether the path lies
     * inside a root the CLI can see. Each of those four otherwise ends in an
     * instruction the server would reject on an unpermitted root, which is the
     * dead end this feature exists to stop walking an agent into. `fresh` is
     * the exception: it asks for nothing, so there is nothing to redirect, and
     * a root warning on a graph that is currently correct would be noise on the
     * one verdict that needs none.
     */
    private function verdict(SessionBrief $brief): string
    {
        return match ($brief->state) {
            'fresh' => sprintf('FRESH (scanned %s ago).', $this->age($brief->ageSeconds)),
            'stale' => $brief->pathAllowed
                ? sprintf(
                    'STALE (%d files, %s). Run scan_project path=%s first.',
                    $brief->changedFiles,
                    $this->age($brief->ageSeconds),
                    $brief->path,
                )
                : sprintf(
                    'STALE (%d files, %s), and %s is not an allowed root. Add it: knossos allow-root %s --execute',
                    $brief->changedFiles,
                    $this->age($brief->ageSeconds),
                    $brief->path,
                    $brief->path,
                ),
            'unverified' => $brief->pathAllowed
                ? sprintf(
                    'UNVERIFIED (%d files, over probe limit; scanned %s ago). Rescan if exactness matters.',
                    $brief->trackedFiles,
                    $this->age($brief->ageSeconds),
                )
                : sprintf(
                    'UNVERIFIED (%d files, over probe limit; scanned %s ago), and %s is not an allowed root. '
                        . 'Add it: knossos allow-root %s --execute',
                    $brief->trackedFiles,
                    $this->age($brief->ageSeconds),
                    $brief->path,
                    $brief->path,
                ),
            'missing' => $brief->pathAllowed
                ? sprintf('NO GRAPH. Run scan_project path=%s first.', $brief->path)
                : sprintf(
                    'NO GRAPH, and %s is not an allowed root. Add it: knossos allow-root %s --execute',
                    $brief->path,
                    $brief->path,
                ),
            default => $brief->pathAllowed
                ? sprintf('NOT SCANNED. Run scan_project path=%s to map this repository.', $brief->path)
                : sprintf(
                    'NOT SCANNED, and %s is not an allowed root. Add it: knossos allow-root %s --execute',
                    $brief->path,
                    $brief->path,
                ),
        };
    }

    /** Coarse, single-token age. Minute precision on a seventeen-day-old graph is noise. */
    private function age(?int $seconds): string
    {
        return match (true) {
            $seconds === null => 'unknown',
            $seconds >= 86_400 => intdiv($seconds, 86_400) . 'd',
            $seconds >= 3_600 => intdiv($seconds, 3_600) . 'h',
            default => max(1, intdiv($seconds, 60)) . 'm',
        };
    }

    /**
     * One labelled block, or null when it has nothing to say.
     *
     * @param list<string> $items
     */
    private function section(string $label, array $items): ?string
    {
        if ($items === []) {
            return null;
        }
        if (count($items) === 1) {
            return $label . ': ' . $items[0];
        }
        return $label . ":\n  " . implode("\n  ", $items);
    }

    /**
     * Assemble within budget, dropping whole sections rather than truncating.
     *
     * A list cut mid-entry reads as a complete list that happens to be wrong,
     * which is worse than a shorter one. The verdict and the pointer are the
     * floor beneath the budget, not subject to it: dropping the pointer to
     * honour the budget would silence the very thing that arms the skill, and
     * truncating the verdict would hand back a `scan_project path=...` that
     * nobody can run. Both are appended unconditionally, so an unusually long
     * path can push the final output past its nominal budget.
     *
     * @param list<string> $lines the verdict first, then optional sections
     */
    private function fit(array $lines, int $budget): string
    {
        $verdict = array_shift($lines) ?? '';
        $tail = "\n" . self::POINTER;
        $out = $verdict;
        foreach ($lines as $line) {
            $candidate = $out . "\n" . $line;
            if (strlen($candidate) + strlen($tail) <= $budget) {
                $out = $candidate;
            }
        }
        return $out . $tail;
    }
}
