import type { Dashboard, FanIn, TurnBrief } from './envelopes'

/** One line the model reads after editing a heavily depended-on file. */
export function editNote(entry: FanIn): string {
  const across = entry.boundaries.length > 0
    ? ` across ${entry.boundaries.length} boundar${entry.boundaries.length === 1 ? 'y' : 'ies'} (${entry.boundaries.join(', ')})`
    : ''
  return `knossos: ${entry.path} has ${entry.dependent_files} dependent files${across}; run test_impact before finishing.`
}

/** The user-role note for violations a turn introduced, or null when there are none. */
export function violationNote(brief: TurnBrief): string | null {
  const { total, violations } = brief.policy
  if (total === 0) {
    // The check stops at 100 violations project-wide before it looks at files, so a truncated
    // check may have dropped this turn's; say so rather than report a clean turn.
    return brief.policy.truncated
      ? 'knossos: the boundary-policy check was truncated; run check_architecture to see violations in the files this turn edited.'
      : null
  }
  // A check cut short at its cap or time limit makes the count a bound; the full check is one call away.
  const cut = brief.policy.truncated ? ' (check was truncated; run check_architecture)' : ''
  const head = `knossos: this turn introduced ${total} boundary-policy violation${total === 1 ? '' : 's'}${cut}. Fix ${total === 1 ? 'it' : 'them'} before finishing:`
  const lines = violations.map(v => `- ${v.policy_id}: ${v.source} → ${v.target}`)
  const more = total > violations.length ? [`- …and ${total - violations.length} more (run review_diff)`] : []
  return [head, ...lines, ...more].join('\n')
}

/** The fan-in map keyed by project-relative path. */
export function fanInIndex(dashboard: Dashboard | null): Map<string, FanIn> {
  return new Map((dashboard?.fan_in ?? []).map(f => [f.path, f]))
}
