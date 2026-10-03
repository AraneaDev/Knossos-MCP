/**
 * A changed file's change since the session began, as its detail shows it
 * below the files that depend on it: the diff against the commit the session
 * started at, drawn with the engine's own diff element (`Code` with
 * `format: 'diff'`), so it looks as Claude Code's own diffs do.
 *
 * Pure, like the rest of the layout. The hunks come from `session-diff`;
 * here they are read, made safe for the element (it takes tab and newline
 * as its only control characters, and at most 10,000 characters), long ones
 * folded with how many lines are left out, and the whole bounded. A row
 * that carries `code` is drawn as one such element; every other row is text,
 * cut to the columns it has.
 */
import type { DiffState, Inspected, SessionDiff, SessionRev } from '../../types'
import { dimRow, fitStart, plural, wrapWords } from './rows'
import type { Row, Segment } from './rows'
import type { Block, Section } from './cards'

/** Lines of one hunk shown; past them the rest is folded into "N more lines". */
export const HUNK_LINES = 40
/** Hunks shown; past them the rest is counted. */
export const HUNKS_SHOWN = 12
/** Characters of one line kept: the element cuts a line at the edge anyway, and the hunk must stay under its limit. */
export const LINE_MAX = 200
/** The element's own limit on its text. */
const SOURCE_MAX = 10_000

/** One hunk: where it starts on each side, and its lines, each with its ` `, `+` or `-` marker. */
export type Hunk = { oldStart: number; newStart: number; heading: string; lines: string[] }

/** The detail's view of the change, from state: loading, what to say instead, or the hunks. */
export type DiffView =
  | { phase: 'loading' }
  | { phase: 'message'; text: string }
  | { phase: 'diff'; path: string; added: number; removed: number; hunks: Hunk[]; renamed: string | null; truncated: boolean }

/** Every control character but the tab, which the element refuses: a carriage return goes, the rest show as a replacement mark. */
// eslint-disable-next-line no-control-regex -- finding control characters is what it is for
const clean = (line: string): string => line.replace(/\r$/, '').replace(/[\u0000-\u0008\u000b-\u001f\u007f]/g, '�')

/** `line` cut to {@link LINE_MAX} characters, an ellipsis last when cut. */
const capped = (line: string): string => ([...line].length <= LINE_MAX ? line : `${[...line].slice(0, LINE_MAX - 1).join('')}…`)

/**
 * The hunks of a unified diff with no headers. A line before the first
 * hunk, or of no hunk's shape, is passed by; `\ No newline at end of file`
 * too, as the element would.
 */
export function parseHunks(diff: string): Hunk[] {
  const hunks: Hunk[] = []
  for (const raw of diff.split('\n')) {
    const line = clean(raw)
    const head = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@ ?(.*)$/.exec(line)
    if (head !== null) {
      hunks.push({ oldStart: Number(head[1]), newStart: Number(head[2]), heading: head[3] ?? '', lines: [] })
      continue
    }
    const last = hunks.at(-1)
    if (last !== undefined && /^[ +-]/.test(line)) last.lines.push(capped(line))
  }
  return hunks
}

/** The hunk header for `lines` starting where `hunk` does: its counts are the lines it holds, so a folded hunk still parses. */
function header(hunk: Hunk, lines: string[]): string {
  const old = lines.filter(l => !l.startsWith('+')).length
  const now = lines.filter(l => !l.startsWith('-')).length
  // A side the fold left empty starts at the line before, as git writes an empty side; one empty already stays put.
  const oldStart = old === 0 && hunk.lines.some(l => !l.startsWith('+')) ? Math.max(0, hunk.oldStart - 1) : hunk.oldStart
  const newStart = now === 0 && hunk.lines.some(l => !l.startsWith('-')) ? Math.max(0, hunk.newStart - 1) : hunk.newStart
  return `@@ -${oldStart},${old} +${newStart},${now} @@${hunk.heading === '' ? '' : ` ${hunk.heading}`}`
}

/** The text one hunk's element draws: its header and the lines shown, within the element's limit. */
export function hunkSource(hunk: Hunk, shown: string[]): string {
  let lines = shown
  while (lines.length > 1 && [header(hunk, lines), ...lines].join('\n').length > SOURCE_MAX) lines = lines.slice(0, -1)
  return [header(hunk, lines), ...lines].join('\n')
}

/** The lines of `hunk` shown, and how many are folded away. */
export function folded(hunk: Hunk, max = HUNK_LINES): { shown: string[]; more: number } {
  return hunk.lines.length <= max ? { shown: hunk.lines, more: 0 } : { shown: hunk.lines.slice(0, max), more: hunk.lines.length - max }
}

/**
 * What the detail of `shown` says about its change since the session began.
 * Null when it was not opened from the session's changes. Without the
 * commit the session began at (not read, or no git), or without an answer,
 * it says so in place of a diff.
 */
export function diffView(shown: Inspected, state: DiffState | null, rev: SessionRev | null): DiffView | null {
  if (shown.file !== true || shown.changed !== true) return null
  if (rev === null) return { phase: 'message', text: "No diff: the commit this session began at was not recorded (no git answered when it started)." }
  if (rev.status === 'no-git') return { phase: 'message', text: 'No diff: the project is not in a git repository, or had no commit when the session began.' }
  if (state === null || state.name !== shown.name || state.rev !== rev.rev || state.phase === 'loading') return { phase: 'loading' }
  return viewOf(shown.name, state.diff)
}

/** The view of one `session-diff` answer. */
function viewOf(path: string, answer: SessionDiff | null): DiffView {
  if (answer === null || answer.status === 'error' || answer.status === 'no-binary') return { phase: 'message', text: 'No diff: knossos said nothing.' }
  if (answer.status === 'no-git') return { phase: 'message', text: 'No diff: the project is not in a git repository.' }
  if (answer.status === 'unknown-rev') return { phase: 'message', text: 'No diff: the commit this session began at is no longer in the repository.' }
  if (answer.unreadable === true) return { phase: 'message', text: 'No diff: git could not show this change within its limits.' }
  if (answer.binary === true) return { phase: 'message', text: 'A binary file: no lines to show.' }
  if (answer.kind === 'absent') return { phase: 'message', text: 'Git has no record of this file, then or now.' }
  const hunks = parseHunks(answer.diff ?? '')
  const renamed = answer.kind !== 'renamed' ? null : answer.to === path ? `renamed from ${answer.from ?? '?'}` : `renamed to ${answer.to ?? '?'}`
  if (hunks.length === 0 && renamed === null) return { phase: 'message', text: answer.kind === 'unchanged' ? 'Back as it was when the session began.' : 'No lines changed.' }
  const all = hunks.flatMap(h => h.lines)
  return {
    phase: 'diff',
    path,
    added: all.filter(l => l.startsWith('+')).length,
    removed: all.filter(l => l.startsWith('-')).length,
    hunks,
    renamed,
    truncated: answer.truncated === true,
  }
}

/**
 * The change's card: the lines added and removed in its note, a rename when
 * it was one, then each hunk as a diff element (long ones folded with how
 * many lines are left out), how many hunks are not shown, and a note when
 * the diff was cut. The text rows fit `columns`.
 */
export function diffSection(view: DiffView, columns: number): Section {
  const title = 'Changed since the session began'
  if (view.phase !== 'diff') {
    const text = view.phase === 'loading' ? 'Reading the change…' : view.text
    return { key: 'diff', title, body: wrapWords(text, Math.max(1, columns - 3)).map((part, i) => dimRow(`diff-line-${i}`, `   ${part}`, columns)) }
  }
  // Only the sides that moved: an added file is all `+`, a deleted one all `−`.
  const counts: Segment[] = [
    ...(view.added > 0 ? [{ text: `+${view.added}`, color: 'diffAddedWord' }] : []),
    ...(view.added > 0 && view.removed > 0 ? [{ text: ' ' }] : []),
    ...(view.removed > 0 ? [{ text: `−${view.removed}`, color: 'diffRemovedWord' }] : []),
  ]
  const rows: Row[] = []
  if (view.renamed !== null) rows.push(dimRow('diff-renamed', `   ${fitStart(view.renamed, Math.max(1, columns - 3))}`, columns))
  view.hunks.slice(0, HUNKS_SHOWN).forEach((hunk, i) => {
    const { shown, more } = folded(hunk)
    rows.push({ key: `diff-hunk-${i}`, segments: [], code: { source: hunkSource(hunk, shown), path: view.path } })
    if (more > 0) rows.push(dimRow(`diff-more-${i}`, `   ${plural(more, 'more line', 'more lines')}`, columns))
  })
  const hidden = view.hunks.length - HUNKS_SHOWN
  if (hidden > 0) rows.push(dimRow('diff-hunks-more', `   ${plural(hidden, 'more change', 'more changes')} further down the file`, columns))
  if (view.truncated) rows.push(dimRow('diff-cut', '   The diff is cut here: open the file to see the rest.', columns))
  return { key: 'diff', title, note: counts, body: rows }
}

/** The change as a card the detail places: across the pane, below its lists. */
export const diffBlock = (view: DiffView): Block => ({ key: 'diff', make: columns => diffSection(view, columns) })
