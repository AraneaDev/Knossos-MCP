import type { PolicyRule } from '../../types'
import { runnerOf, testCommand, testsRan } from './changes'
import type { Dashboard, FanIn, TurnBrief, Violation } from './envelopes'

/**
 * One line the model reads after editing a heavily depended-on file it was
 * not told about on Read. The dependents' boundaries are counted, not named:
 * most are inferred labels (packages, namespaces) that cost tokens and say
 * nothing the count does not.
 */
export function editNote(entry: FanIn): string {
  const across = entry.boundaries.length > 1 ? ` across ${entry.boundaries.length} boundaries` : ''
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

/**
 * The declared policies as the dashboard sends them: the rules, each policed
 * boundary with the path prefixes that place its files, and the files of
 * those no prefix places.
 */
export type PolicyScope = Pick<NonNullable<Dashboard['policy']>, 'rules' | 'boundaries' | 'files' | 'files_truncated'>

/**
 * The policed boundaries `path` sits in: those whose path prefix holds it,
 * and those the file list names it in. `unsure` when the list stopped at its
 * cap before it reached this file and some policed boundary is known only
 * through that list, so a rule may bind the file unseen. A knossos that sends
 * no boundaries lists every bound file, so its cap leaves any file unsure.
 */
export function boundOf(path: string, policy: Partial<PolicyScope> | undefined): { bound: string[]; unsure: boolean } {
  const files = policy?.files ?? {}
  const policed = policy?.boundaries ?? {}
  const byPrefix = Object.entries(policed)
    .filter(([, b]) => b.path_prefixes.some(prefix => path.startsWith(prefix)))
    .map(([name]) => name)
  const listed = Object.hasOwn(files, path) ? (files[path] ?? []) : []
  const throughList = policy?.boundaries === undefined || Object.values(policed).some(b => b.listed)
  return { bound: [...new Set([...byPrefix, ...listed])], unsure: policy?.files_truncated === true && !Object.hasOwn(files, path) && throughList }
}

/** One rule in a line: what a boundary may not depend on, or may depend on alone; its edge kinds when it names some. */
export function ruleText(rule: PolicyRule): string {
  const name = (b: string) => (b === '@unassigned' ? 'unassigned code' : b)
  const parts = [
    ...(rule.deny.length > 0 ? [`${rule.from} may not depend on ${rule.deny.map(name).join(', ')}`] : []),
    ...(rule.allow.length > 0 ? [`${rule.from} may depend only on itself, ${rule.allow.map(name).join(', ')}`] : []),
  ]
  return `${parts.join('; ')}${rule.edge_kinds.length > 0 ? ` (${rule.edge_kinds.join(', ')})` : ''}`
}

/**
 * The note the model reads after it Reads `path`, before it edits: how many
 * files depend on it (when at or above `threshold`), the boundary it sits in,
 * and the rules that bind that boundary, unless they were stated already
 * (`ruled`). Null when there is nothing new to say. `ruled` in the answer
 * lists the boundaries whose rules it states.
 *
 * Only a declared boundary (`declared`) or one a rule binds is named: an
 * inferred label (a package, the repository-wide one) tells the model nothing.
 * When a cap leaves it unknown whether a rule binds the file, the note says
 * so and points at check_architecture: silence would read as "no rules".
 */
export function readNote(
  path: string,
  fanIn: FanIn | undefined,
  threshold: number,
  policy: Partial<PolicyScope> | undefined,
  ruled: ReadonlySet<string>,
  declared: ReadonlySet<string>,
): { text: string; ruled: string[] } | null {
  const hub = fanIn !== undefined && fanIn.dependent_files >= threshold
  const { bound, unsure } = boundOf(path, policy)
  const fresh = bound.filter(b => !ruled.has(b))
  if (!hub && fresh.length === 0 && !unsure) return null
  const own = fanIn?.boundary ?? null
  const boundary = bound[0] ?? (own !== null && declared.has(own) ? own : null)
  const rules = (policy?.rules ?? []).filter(r => fresh.includes(r.from)).map(ruleText)
  const may = `may bind this file; run check_architecture.`
  if (!hub && boundary === null) return { text: `knossos: ${path}: rules ${may}`, ruled: fresh }
  const head = hub
    ? `knossos: ${path}${boundary === null ? '' : ` (${boundary})`} has ${fanIn.dependent_files} dependent files.`
    : `knossos: ${path} is in ${boundary}.`
  const stated = rules.length === 0 ? head : `${head} Policy: ${rules.join('; ')}.`
  return { text: unsure ? `${stated} ${rules.length === 0 ? 'Rules' : 'Other rules'} ${may}` : stated, ruled: fresh }
}

/** How many tests a tests note names before `and N more`. */
const TESTS_NAMED = 5
/** Runners whose command names each test (its file, or its class in a filter): the command is the list. */
const SELF_NAMING = new Set(['phpunit', 'vitest', 'pytest'])

/**
 * The turn-end note on the tests that reach the turn's changes: the runnable
 * ones (a runner runs them; helpers and fixtures are left out) that no
 * command of the turn (`ran`) already ran and no earlier note named, nearest
 * first, and the command that runs them all. Null when none is left.
 *
 * Tests the command already names are not listed again; those it runs by
 * package (`go test`, `cargo test`) are, at most five.
 */
export function testsNote(tests: TurnBrief['tests'], ran: string[], named: ReadonlySet<string>): { text: string; tests: string[] } | null {
  const left = [...tests]
    .filter(t => runnerOf(t.path) !== null && !named.has(t.path) && !testsRan(t.path, ran))
    .sort((a, b) => a.distance - b.distance || a.path.localeCompare(b.path))
    .map(t => t.path)
  const command = testCommand(left)
  if (left.length === 0 || command === null) return null
  const listed = left.filter(t => !SELF_NAMING.has(runnerOf(t) ?? ''))
  const more = listed.length > TESTS_NAMED ? ` and ${listed.length - TESTS_NAMED} more` : ''
  const names = listed.length === 0 ? '' : `: ${listed.slice(0, TESTS_NAMED).join(', ')}${more}`
  const count = left.length === 1 ? '1 test reaches' : `${left.length} tests reach`
  return { text: `knossos: ${count} this turn's changes${names}. Run: ${command}`, tests: left }
}

/** A violation's identity across turns: its policy and the two ends. */
export const violationKey = (v: Violation): string => `${v.policy_id}: ${v.source} → ${v.target}`

/** The brief with the violations already reported (`seen`) taken out of its list and its count. */
export function freshViolations(brief: TurnBrief, seen: ReadonlySet<string>): TurnBrief {
  const fresh = brief.policy.violations.filter(v => !seen.has(violationKey(v)))
  const dropped = brief.policy.violations.length - fresh.length
  return { ...brief, policy: { ...brief.policy, total: Math.max(0, brief.policy.total - dropped), violations: fresh } }
}
