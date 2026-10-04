/**
 * What the mod tells the model on its own account beyond the notes after a
 * Read, an edit or a turn: the answer of the `knossos_context` tool it
 * registers, and the note after a `git commit`.
 *
 * Pure: the figures in, one compact text out, every list cut to a few and
 * the whole bounded, since the model reads all of it.
 */
import type { FileContext, TouchStatus } from '../../types'
import { stamp } from './changes'

/** How many of each list an answer names before it counts the rest. */
const NAMED = 5
/** The longest an answer or a note runs: past it, it is cut and says so. */
export const TEXT_MAX = 2_000

/** What the tool is called by the model (it lists as `mcp__<plugin>__knossos_context`). */
export const CONTEXT_TOOL = 'knossos_context'

/** What the model reads about the tool when it decides whether to call it. */
export const CONTEXT_DESCRIPTION =
  'One file\'s architectural context from the Knossos graph, in one short answer: its boundary and the rules that bind it, how many files depend on it (and the closest few), the tests that reach it, its latest commits, and whether this session changed it. Call it before editing a file you have not read about, instead of grepping for its callers.'

/** The tool's input: one file, relative to the project or absolute. */
export const CONTEXT_SCHEMA = {
  type: 'object',
  properties: { path: { type: 'string', description: 'The file, relative to the project root or absolute.' } },
  required: ['path'],
  additionalProperties: false,
} as const

/** A list named up to {@link NAMED}, with how many more there are. */
const named = (items: string[], total = items.length): string => `${items.slice(0, NAMED).join(', ')}${total > Math.min(NAMED, items.length) ? `, and ${total - Math.min(NAMED, items.length)} more` : ''}`

/** `text` cut to {@link TEXT_MAX}, saying so when it was. */
const bounded = (text: string): string => (text.length <= TEXT_MAX ? text : `${text.slice(0, TEXT_MAX - 13)}… (cut short)`)

/**
 * The tool's answer for one file: what `file-context` read, the rules the
 * mod knows bind it (`rules`; `unsure` when a cap leaves that open), and
 * how this session changed it (null: not at all). `asked` is the path as the
 * model gave it, for the answers that have no file.
 */
export function contextAnswer(asked: string, context: FileContext | null, rules: string[], unsure: boolean, session: TouchStatus | null): string {
  if (context === null) return `knossos_context: knossos did not answer for ${asked}; try again, or use the knossos MCP tools.`
  const f = context.file
  if (context.status === 'unscanned') return `knossos_context: the project holding ${asked} has not been scanned.`
  if (context.status !== 'ok' || f === null) return `knossos_context: ${asked} is not in the graph (not a source file knossos scans, or not scanned yet).`
  const facts = [f.boundary === null ? 'no boundary' : `boundary ${f.boundary}`, f.language.toUpperCase(), ...(f.lines === null ? [] : [`${f.lines} lines`]), `${f.components} components`]
  const lines = [`${f.path}: ${facts.join(', ')}.`]
  const deps = f.dependents
  lines.push(deps.count === 0 ? 'Dependents: no other file depends on it.' : `Dependents: ${deps.count} files${deps.boundaries.length > 0 ? ` in ${named(deps.boundaries)}` : ''}; closest: ${named(deps.top, deps.count)}.`)
  const ruleText = rules.length > 0 ? `Rules: ${rules.join('; ')}.` : 'Rules: no declared rule binds it.'
  lines.push(unsure ? `${ruleText} Other rules may bind it; run check_architecture to be sure.` : ruleText)
  const tests = f.tests.items
  lines.push(tests.length === 0 ? (f.tests.more ? 'Tests: the search was cut short before it found one; run test_impact.' : 'Tests: no test reaches it.') : `Tests that reach it: ${named(tests.map(t => `${t.path} (${t.distance} ${t.distance === 1 ? 'hop' : 'hops'})`))}${f.tests.more ? ', and more' : ''}.`)
  if (f.commits.length > 0) lines.push(`Latest commits: ${f.commits.map(c => `${c.rev} ${stamp(c.at * 1000).slice(0, 10)} ${c.subject}`).join('; ')}.`)
  lines.push(session === null ? 'This session has not changed it.' : `This session ${session === 'added' ? 'added' : session === 'deleted' ? 'deleted' : 'changed'} it.`)
  return bounded(lines.join('\n'))
}

/**
 * Whether a shell command's output says it made a commit: git's own
 * `[branch abc1234] subject` line (`[main (root-commit) abc1234]`,
 * `[detached HEAD abc1234]`). Read from what ran rather than from the
 * command, so a `git commit` inside a string (`echo`, `grep`, a `gh` body),
 * a `--dry-run` or a commit that failed says nothing, and one made through
 * an alias or a script is still seen. A `--quiet` commit prints no such
 * line and goes unnoticed.
 */
export function madeCommit(output: string): boolean {
  return /^\[[^[\]\n]+ [0-9a-f]{7,64}\] \S/m.test(output)
}

/**
 * The violations of `violations` (`source → target`) the current policy
 * check still reports: one fixed since a turn introduced it is not the
 * session's to carry any more. All of them when the check cannot say (not
 * evaluated, or its list cut short).
 */
export function stillReported(violations: string[], policy: { status: string; truncated: boolean; total: number; items: { source: string; target: string }[] } | undefined): string[] {
  if (policy === undefined || policy.status !== 'evaluated' || policy.truncated || policy.items.length < policy.total) return violations
  const reported = new Set(policy.items.map(v => `${v.source} → ${v.target}`))
  return violations.filter(v => reported.has(v))
}

/**
 * The note after a commit: what this session's changes leave behind, as far
 * as the graph says: boundary-policy violations its turns introduced that
 * the policy check still reports, the files it changed that no test reaches,
 * and cycles that are new since the session began. A commit need not hold
 * all of them, so the note speaks of the session's changes, not of the
 * commit. Null when there is nothing to say.
 */
export function commitNote(violations: string[], untested: string[], cycles: { count: number; chains: string[] }): string | null {
  const parts: string[] = []
  if (violations.length > 0) parts.push(`${violations.length} boundary-policy ${violations.length === 1 ? 'violation' : 'violations'} this session introduced (${named(violations)})`)
  if (untested.length > 0) parts.push(`${untested.length} changed ${untested.length === 1 ? 'file' : 'files'} no test reaches (${named(untested)})`)
  if (cycles.count > 0) parts.push(`${cycles.count} dependency ${cycles.count === 1 ? 'cycle' : 'cycles'} new since the session began${cycles.chains.length > 0 ? ` (${named(cycles.chains, cycles.count)})` : ''}`)
  if (parts.length === 0) return null
  return bounded(`knossos: this session's changes carry ${parts.join('; ')}. Check them before you push.`)
}
