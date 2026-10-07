/**
 * What the model is told: the note on a Read or an edit, the note after a
 * turn and after a commit, and how a note is delivered.
 */
import { commitNote, cyclesSinceStart, REFLOG_READ, stillReported } from '../lib/agent'
import type { TurnBrief } from '../lib/envelopes'
import { editNote, fanInIndex, freshViolations, readNote, testsNote, violationKey, violationNote } from '../lib/notes'
import { declaredOf } from '../lib/palette'
import { relativise } from '../lib/paths'
import { keepEdit, placed, shownChanges } from './port'
import type { Port } from './port'
import { mod, ownChange, takeNoteSlot } from './state'

/** How long git may take to name HEAD or print its reflog around a shell command. */
const GIT_PROBE_TIMEOUT_MS = 2_000

/** The repository a commit note speaks for: the graph's project root, else the session's. */
export async function projectRoot(io: Port): Promise<string> {
  const d = await io.state.dashboard.read()
  return d?.status === 'ok' && d.project_root !== null ? d.project_root : await io.session.root()
}

/**
 * HEAD in `repo`: the commit, '' on a branch with no commit yet, null when
 * `repo` is no repository or git did not answer. Run around a shell command
 * in its hook, never in a render.
 */
export async function headAt(io: Port, repo: string): Promise<string | null> {
  try {
    const { exitCode, stdout } = await io.process.run(['git', '--no-optional-locks', '-C', repo, 'rev-parse', '-q', '--verify', 'HEAD'], { timeoutMs: GIT_PROBE_TIMEOUT_MS })
    if (exitCode === 1) return ''
    const head = stdout.trim()
    return exitCode === 0 && /^[0-9a-f]{40,64}$/.test(head) ? head : null
  } catch {
    return null
  }
}

/** HEAD's newest reflog entries in `repo`, `<sha>\x1f<subject>` a line; '' when there is none or git did not answer. */
export async function reflogAt(io: Port, repo: string): Promise<string> {
  try {
    const { exitCode, stdout } = await io.process.run(['git', '--no-optional-locks', '-C', repo, 'reflog', '-n', String(REFLOG_READ), '--format=%H%x1f%gs', 'HEAD'], { timeoutMs: GIT_PROBE_TIMEOUT_MS })
    return exitCode === 0 ? stdout : ''
  } catch {
    return ''
  }
}

/**
 * The note after a commit in `loop`, or null when there is nothing new to
 * say: of this session's own changes (its files, not ones changed outside
 * it since it began), the violations its turns introduced that the policy
 * check still reports, the files no test reaches, and the cycles new since
 * the session began. A cycle is named new only when the graph listed every
 * cycle it held as the session began; otherwise only the count that grew
 * is said.
 */
export async function commitNoteFor(io: Port, loop: string): Promise<string | null> {
  const d = await io.state.dashboard.read()
  if (!mod.notesOn || d?.status !== 'ok') return null
  const session = await shownChanges(io, d.project_root)
  const untested = Object.entries(session.files)
    .filter(([path, f]) => f.status !== 'deleted' && f.tests === 0 && ownChange(session, path))
    .map(([path]) => path)
    .sort()
  const start = await io.state.startCycles.read()
  const { count, fresh } = cyclesSinceStart(start, d.cycles)
  const chains = fresh.map(c => `${c.members.slice(0, 4).join(' → ')}${c.members.length > 4 ? ' → …' : ''}`)
  const note = commitNote(mod.enforce ? stillReported(session.violations, session.violation_snapshots, d) : [], untested, { count, chains })
  if (note === null) return null
  const key = `${loop}\u0000${note}`
  if (mod.commitNoted.has(key) || !takeNoteSlot(loop)) return null
  mod.commitNoted.add(key)
  return note
}

/**
 * The one note the model reads after a scanned turn: the violations it
 * introduced that were not reported before (when policies are enforced), and
 * the tests that reach its changes that it did not run and no note named.
 * Null when there is nothing new, or notes are off.
 */
export function turnEndNote(parsed: TurnBrief, cd: string | null): string | null {
  if (!mod.notesOn) return null
  const parts: string[] = []
  if (mod.enforce) {
    const fresh = freshViolations(parsed, mod.violationsSeen)
    const quietCut = fresh.policy.total === 0 && fresh.policy.truncated
    const violations = quietCut && mod.truncationSaid ? null : violationNote(fresh)
    if (violations !== null) parts.push(violations)
    if (quietCut) mod.truncationSaid = true
    for (const v of parsed.policy.violations) mod.violationsSeen.add(violationKey(v))
  }
  const tests = testsNote(parsed.tests, mod.turnRan, mod.testsNamed, cd)
  if (tests !== null) {
    parts.push(tests.text)
    for (const t of tests.tests) mod.testsNamed.add(t)
  }
  return parts.length === 0 ? null : parts.join('\n\n')
}

/**
 * Appends the turn-end note as a user-role row the model reads. A refusal
 * (a plugin above, or a run no plugin may shape) leaves a debug line with the
 * note, so a lost note is never silent.
 */
export async function deliverNote(io: Port, note: string): Promise<void> {
  let reason: string | null
  try {
    const appended = await io.session.append({ message: { type: 'user', content: [{ type: 'text', text: note }] } })
    reason = appended.deny ?? null
  } catch (err) {
    reason = err instanceof Error ? err.message : String(err)
  }
  if (reason !== null) io.ui.log(`knossos: the note did not reach the model (${reason}): ${note}`, { to: 'debug' })
}

/**
 * Records an edit that landed inside the project, with `base` (the snapshot
 * from before it) as the turn's start when the turn has none yet. Resolves
 * to the fan-in note for the model with the file it is about, or null when
 * the file is quiet or outside.
 */
export async function noteEdit(io: Port, reported: string, base: string | null): Promise<{ text: string; path: string } | null> {
  const path = await placed(io, reported)
  const current = await io.state.dashboard.read()
  const root = current?.project_root ?? null
  if (root === null) {
    // No dashboard yet: the project root is unknown, so keep the absolute
    // path (the brief accepts those) when it lies under the session's root.
    if (relativise(await placed(io, await io.session.root()), path) === null) return null
    mod.dirty = true
    mod.edited.add(path)
    mod.turnBase ??= base
    await keepEdit(io, path)
    return null
  }
  const relative = relativise(root, path)
  if (relative === null) return null
  mod.dirty = true
  mod.edited.add(relative)
  mod.turnBase ??= base
  await keepEdit(io, relative)
  const entry = fanInIndex(current).get(relative)
  return entry === undefined || entry.dependent_files < mod.threshold ? null : { text: editNote(entry), path: relative }
}

/**
 * The tool result with the note `add` gives it, or `ran` unchanged when that
 * throws: a note is never worth a failed tool call. The first failure of a
 * session leaves one debug line; later ones are as quiet as the first.
 */
export async function noteSafely<T>(io: Port, ran: T, add: () => Promise<T>): Promise<T> {
  try {
    return await add()
  } catch (err) {
    if (!mod.noteFailureLogged) {
      mod.noteFailureLogged = true
      io.ui.log(`knossos: a note after a tool call failed and was left out (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return ran
  }
}

/**
 * The note for a Read of `reported` in `loop`: the file's dependents, its
 * boundary and the rules that bind it, before the model edits it. Null when
 * there is nothing new to say, the file lies outside the project, notes are
 * off, or this turn's notes are spent (then it is said on a later Read).
 */
export async function noteRead(io: Port, reported: string, loop: string): Promise<string | null> {
  const d = await io.state.dashboard.read()
  if (!mod.notesOn || d?.status !== 'ok' || d.project_root === null) return null
  const relative = relativise(d.project_root, await placed(io, reported))
  if (relative === null || mod.noted.has(`${loop}\u0000${relative}`)) return null
  const prefix = `${loop}\u0000`
  const ruled = new Set([...mod.ruled].filter(k => k.startsWith(prefix)).map(k => k.slice(prefix.length)))
  const declared = declaredOf(d)
  const note = readNote(relative, fanInIndex(d).get(relative), mod.threshold, d.policy, ruled, declared)
  if (note === null || !takeNoteSlot(loop)) return null
  mod.noted.add(`${prefix}${relative}`)
  for (const b of note.ruled) mod.ruled.add(`${prefix}${b}`)
  return note.text
}
