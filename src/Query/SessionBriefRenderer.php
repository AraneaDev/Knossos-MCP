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
     * Every verdict is a state clause, which says what is known about the
     * graph, plus one continuation, which says what to do next. The
     * continuations are ordered by how far they stop an agent: a path that is
     * not on disk beats a path outside the allowed roots, which beats the
     * ordinary instruction, because the earlier blocker makes the later advice
     * impossible to act on rather than merely incomplete.
     *
     * Four of the five states therefore have three forms. `fresh` is the
     * exception: it asks for nothing, so there is nothing to redirect, and a
     * root warning on a graph that is currently correct would be noise on the
     * one verdict that needs none. It cannot reach the missing-path form
     * either, since {@see StalenessProbe} cannot call a graph fresh without
     * fingerprinting files under a root that is there to be read.
     */
    private function verdict(SessionBrief $brief): string
    {
        $state = $this->stateClause($brief);
        if (!$brief->pathExists) {
            // Neither of the two commands the other forms hand out applies:
            // `scan_project` has nothing to walk, and `allow-root` refuses a
            // path that is not an existing directory. Naming both is what
            // stops the reader trying the second after the first fails.
            return sprintf(
                '%s, and %s does not exist. Neither scan_project nor allow-root will accept it.',
                $state,
                $brief->path,
            );
        }
        if (!$brief->pathAllowed && $brief->state !== 'fresh') {
            return sprintf(
                '%s, and %s is not an allowed root. Add it: knossos allow-root %s --execute',
                $state,
                $brief->path,
                $brief->path,
            );
        }
        return $state . match ($brief->state) {
            'fresh' => '.',
            'stale', 'missing' => sprintf('. Run scan_project path=%s first.', $brief->path),
            // Not stale's wording: probing was skipped, so the graph may well
            // be current and the instruction is conditional rather than owed.
            'unverified' => '. Rescan if exactness matters.',
            default => sprintf('. Run scan_project path=%s to map this repository.', $brief->path),
        };
    }

    /**
     * What is known about the graph, with no trailing punctuation.
     *
     * Split out so the three continuations above are written once each instead
     * of once per state: the "not an allowed root" sentence was already
     * identical across four states, and a third form would have made twelve
     * near-copies of two sentences out of what is really a two-part line.
     */
    private function stateClause(SessionBrief $brief): string
    {
        return match ($brief->state) {
            'fresh' => sprintf('FRESH (scanned %s ago)', $this->age($brief->ageSeconds)),
            'stale' => sprintf(
                'STALE (%d files, %s)',
                $brief->changedFiles,
                $this->age($brief->ageSeconds),
            ),
            'unverified' => sprintf(
                'UNVERIFIED (%d files, over probe limit; scanned %s ago)',
                $brief->trackedFiles,
                $this->age($brief->ageSeconds),
            ),
            'missing' => 'NO GRAPH',
            default => 'NOT SCANNED',
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
     * A section that does not fit is skipped, not read as the end of the list:
     * the loop keeps testing the sections after it, so a long `Rules` block can
     * be dropped while a shorter `Hubs` block that follows it survives. That is
     * deliberate. The sections are independent statements about the project
     * rather than one continuing argument, so a later one is neither wrong nor
     * confusing for an earlier one being absent, and stopping at the first
     * overflow would leave the rest of the budget spent on nothing. What to
     * expect as a result: the order is stable, but what survives is not a
     * prefix of it. A brief says what fitted and never that everything above it
     * fitted too, so nothing may be inferred from a section's absence, which is
     * also why every section carries its own label rather than relying on
     * position.
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
