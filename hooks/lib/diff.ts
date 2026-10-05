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
import type { DiffFold, DiffState, Inspected, SessionDiff, SessionRev } from '../../types'
import { button, dimRow, fit, pathText, plural, wrapWords } from './rows'
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
/**
 * The most text the card's hunks draw together, serialized as the engine
 * weighs it: the engine refuses a tree past 100,000 characters, and twelve
 * hunks of forty full lines would be most of it.
 * Past it the rest of the hunks are counted, as those past {@link HUNKS_SHOWN} are.
 */
export const DIFF_TEXT_MAX = 24_000

/** One hunk: where it starts on each side, and its lines, each with its ` `, `+` or `-` marker. */
export type Hunk = { oldStart: number; newStart: number; heading: string; lines: string[] }

/** The detail's view of the change, from state: loading, what to say instead, or the hunks. */
export type DiffView =
  | { phase: 'loading' }
  | { phase: 'message'; text: string }
  | { phase: 'diff'; path: string; added: number; removed: number; hunks: Hunk[]; renamed: string | null; truncated: boolean; open?: number[]; from?: number }

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

/**
 * The hunk header for `old` lines of the old side and `now` of the new,
 * starting where `hunk` does: its counts are the lines it holds, so a folded
 * hunk still parses. `sides` says which sides `hunk` itself has lines on.
 */
function header(hunk: Hunk, old: number, now: number, sides: { old: boolean; now: boolean }): string {
  // A side the fold left empty starts at the line before, as git writes an empty side; one empty already stays put.
  const oldStart = old === 0 && sides.old ? Math.max(0, hunk.oldStart - 1) : hunk.oldStart
  const newStart = now === 0 && sides.now ? Math.max(0, hunk.newStart - 1) : hunk.newStart
  return `@@ -${oldStart},${old} +${newStart},${now} @@${hunk.heading === '' ? '' : ` ${hunk.heading}`}`
}

/**
 * The text one hunk's element draws: its header and as many of the lines
 * shown as fit the element's limit (one at least). One pass over the lines,
 * counting each side and the length as it goes, so a hunk of any size costs
 * what its lines cost once.
 */
export function hunkSource(hunk: Hunk, shown: string[]): string {
  const sides = { old: hunk.lines.some(l => !l.startsWith('+')), now: hunk.lines.some(l => !l.startsWith('-')) }
  let old = 0
  let now = 0
  let length = 0
  let kept = 0
  for (const line of shown) {
    const nextOld = old + (line.startsWith('+') ? 0 : 1)
    const nextNow = now + (line.startsWith('-') ? 0 : 1)
    // The header, then each line after a newline.
    const total = header(hunk, nextOld, nextNow, sides).length + length + 1 + line.length
    if (kept > 0 && total > SOURCE_MAX) break
    old = nextOld
    now = nextNow
    length += 1 + line.length
    kept++
  }
  const lines = shown.slice(0, kept)
  return [header(hunk, old, now, sides), ...lines].join('\n')
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
export function diffView(shown: Inspected, state: DiffState | null, rev: SessionRev | null, fold: DiffFold | null = null): DiffView | null {
  if (shown.file !== true || shown.changed !== true) return null
  if (rev === null) return { phase: 'message', text: "No diff: the commit this session began at was not recorded (no git answered when it started)." }
  if (rev.status === 'no-git') return { phase: 'message', text: 'No diff: the project is not in a git repository, or had no commit when the session began.' }
  if (state === null || state.name !== shown.name || state.rev !== rev.rev || state.phase === 'loading') return { phase: 'loading' }
  const view = viewOf(shown.name, state.diff)
  // Opened further only for the diff it was opened on: another file, commit or snapshot starts closed.
  if (view.phase !== 'diff' || fold === null || fold.name !== state.name || fold.rev !== state.rev || fold.snapshot !== state.snapshot) return view
  return { ...view, open: fold.open, from: fold.from }
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
 * it was one, then a page of hunks as diff elements (see {@link diffPage}):
 * a long one folded under a press that shows it whole, the hunks before and
 * after the page each a press that shows them, and a note when the diff was
 * cut. `asText` draws a few short hunks as text for a panel, ending in a
 * press that opens the full detail. The text rows fit `columns`.
 */
export function diffSection(view: DiffView, columns: number, asText = false): Section {
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
  if (view.renamed !== null) rows.push(dimRow('diff-renamed', `   ${pathText(view.renamed, Math.max(1, columns - 3))}`, columns))
  if (asText) {
    const shown = view.hunks.slice(0, TEXT_HUNKS)
    for (const [i, hunk] of shown.entries()) {
      const { shown: lines, more } = folded(hunk, TEXT_LINES)
      rows.push(...hunkRows(`diff-hunk-${i}`, hunk, lines, columns))
      if (more > 0) rows.push(dimRow(`diff-more-${i}`, `   ${plural(more, 'more line', 'more lines')}`, columns))
    }
    const hidden = view.hunks.length - shown.length
    if (hidden > 0) rows.push(dimRow('diff-hunks-more', `   ${plural(hidden, 'more change', 'more changes')} further down the file`, columns))
  } else {
    const page = diffPage(view)
    if (page.start > 0) rows.push(pressRow('diff-hunks-before', `diff-from:${page.before}`, plural(page.start, 'earlier change', 'earlier changes'), columns))
    for (const drawn of page.drawn) {
      const i = drawn.index
      rows.push({ key: `diff-hunk-${i}`, segments: [], code: { source: drawn.source, path: view.path } })
      // Folded: a press opens it. Cut by the element's own limit: only the file shows the rest.
      if (drawn.cut > 0) rows.push(dimRow(`diff-more-${i}`, `   ${plural(drawn.cut + drawn.more, 'more line', 'more lines')}: too long to draw here, e opens the file`, columns))
      else if (drawn.more > 0) rows.push(pressRow(`diff-more-${i}`, `diff-more:${i}`, plural(drawn.more, 'more line', 'more lines'), columns))
    }
    const hidden = view.hunks.length - page.end
    if (hidden > 0) rows.push(pressRow('diff-hunks-more', `diff-from:${page.end}`, `${plural(hidden, 'more change', 'more changes')} further down the file`, columns))
  }
  if (view.truncated) rows.push(dimRow('diff-cut', '   The diff is cut here: open the file to see the rest.', columns))
  // Beside a tab the diff is a few lines of text: its full detail draws it across the pane.
  if (asText) rows.push(pressRow('diff-full', 'diff-open', 'open the full diff', columns))
  return { key: 'diff', title, note: counts, body: rows }
}

/**
 * A dim row of one press, indented as the diff's other rows are. Its press
 * id holds a colon or names an action, and no row of the diff is keyed so:
 * a Button is never keyed as a row's Box is.
 */
function pressRow(key: string, id: string, label: string, columns: number): Row {
  const room = Math.max(1, columns - 3)
  return { key, segments: [{ text: '   ' }, button(id, fit(label, room), undefined, { dim: true })] }
}

/** One hunk as a page draws it: its element's text, the lines folded away, and those the element's limit cut. */
export type DrawnHunk = { index: number; source: string; more: number; cut: number }

/** The hunk at `index` as drawn: whole when opened, else folded; at most what the element takes either way. */
function drawnHunk(view: Extract<DiffView, { phase: 'diff' }>, index: number): DrawnHunk {
  const hunk = view.hunks[index]!
  const whole = (view.open ?? []).includes(index)
  const { shown, more } = whole ? { shown: hunk.lines, more: 0 } : folded(hunk)
  const source = hunkSource(hunk, shown)
  return { index, source, more, cut: shown.length - (source.split('\n').length - 1) }
}

/** A hunk's weight as the engine weighs the tree, serialized: a quote or a backslash counts twice, a control byte six times. */
const weightOf = (drawn: DrawnHunk): number => JSON.stringify(drawn.source).length

/**
 * The hunks the detail draws from the first shown (`from`): at most
 * {@link HUNKS_SHOWN}, and within {@link DIFF_TEXT_MAX} together; `end` is
 * the first not drawn. `before` is where the page before this one starts:
 * as far back as one page holds.
 */
export function diffPage(view: Extract<DiffView, { phase: 'diff' }>): { start: number; end: number; before: number; drawn: DrawnHunk[] } {
  const start = Math.min(Math.max(0, view.from ?? 0), Math.max(0, view.hunks.length - 1))
  const drawn: DrawnHunk[] = []
  let spent = 0
  for (let i = start; i < view.hunks.length && drawn.length < HUNKS_SHOWN; i++) {
    const hunk = drawnHunk(view, i)
    if (spent + weightOf(hunk) > DIFF_TEXT_MAX) break
    spent += weightOf(hunk)
    drawn.push(hunk)
  }
  let before = start
  for (let back = 0; before > 0 && start - before < HUNKS_SHOWN; ) {
    const weight = weightOf(drawnHunk(view, before - 1))
    if (back + weight > DIFF_TEXT_MAX) break
    back += weight
    before--
  }
  return { start, end: start + drawn.length, before, drawn }
}

/** Hunks and lines per hunk a text diff shows: it stands in a panel beside a list, where room is short. */
const TEXT_HUNKS = 4
const TEXT_LINES = 12

/**
 * One hunk as text rows, for a panel the engine's diff element cannot stand
 * in (it draws across the whole pane): its header dim, then each line with
 * its marker, an added one in the added colour and a removed one in the
 * removed colour, the rest dim, each cut to `columns`.
 */
function hunkRows(key: string, hunk: Hunk, shown: string[], columns: number): Row[] {
  const head = `@@ −${hunk.oldStart} +${hunk.newStart} @@${hunk.heading === '' ? '' : ` ${hunk.heading}`}`
  const line = (text: string, i: number): Row => {
    const mark = text.charAt(0)
    const style: Omit<Segment, 'text'> = mark === '+' ? { color: 'diffAddedWord' } : mark === '-' ? { color: 'diffRemovedWord' } : { dim: true }
    return { key: `${key}-${i}`, segments: [{ text: fit(`${mark === '-' ? '−' : mark} ${text.slice(1).replace(/\t/g, '  ')}`, columns), ...style }] }
  }
  return [{ key: `${key}-head`, segments: [{ text: fit(head, columns), dim: true }] }, ...shown.map(line)]
}

/** The change as a card the detail places: across the pane, below its lists; `asText` draws it as text rows, for a panel. */
export const diffBlock = (view: DiffView, asText = false): Block => ({ key: 'diff', make: columns => diffSection(view, columns, asText) })
