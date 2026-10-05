/**
 * The Churn tab: the files changed most in the last thirty days that much
 * of the project depends on (`knossos churn`), a change both likely and
 * far-reaching.
 *
 * Pure. Two cards: a scatter of every listed file, commits across and
 * dependents up (a log scale: a few files carry most of the weight), the
 * nine highest scores drawn as their rank so the eye finds them in the
 * list; and the ranked list itself, each file with its commits, its
 * dependents and a bar for its score (commits times dependents). The
 * marked row's file is the accent dot on the scatter. Every file opens as
 * its detail.
 */
import type { Churn, ChurnState } from '../../types'
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block } from './cards'
import { ACCENT, FAINT, HEADING, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { cells, dimRow, grouped, numberWidth, padStart, plural, spaces, tableHead, tableRow, tableSpec, wrapWords } from './rows'
import type { Loc, Row, Segment, Tier } from './rows'
import { locIn } from './views'
import type { Openable } from './views'

/** One ranked file as the tab draws it. */
export type ChurnFile = { path: string; commits: number; dependents: number; score: number; boundary: string | null; loc: Loc | null }

/** The Churn tab's view of the answer. */
export type ChurnInput = {
  /** What to say instead of the cards (loading, no git, nothing answered); null when there are files to draw or none changed. */
  said: string | null
  warn: boolean
  loading: boolean
  days: number
  commits: number
  truncated: boolean
  files: ChurnFile[]
}

/** The window the answer covers when it does not say. */
const DAYS = 30

/** The tab's view of the stored answer; `root` places each file on disk. */
export function churnInput(state: ChurnState | null, root: string | null): ChurnInput {
  const base = { warn: false, loading: false, days: DAYS, commits: 0, truncated: false, files: [] }
  const answer: Churn | null = state?.answer ?? null
  if (answer === null) {
    return state === null || state.phase === 'loading' ? { ...base, said: 'Reading the last 30 days of commits…', loading: true } : { ...base, said: 'knossos did not answer; the tab asks again when the checkout moves.', warn: true }
  }
  if (answer.status === 'no-git') return { ...base, said: 'No git repository, or git did not answer: there is no history to rank.', warn: true }
  if (answer.status === 'unreadable') return { ...base, said: 'Could not read the git history: git printed no log, not even of the last 50 commits.', warn: true }
  if (answer.status !== 'ok') return { ...base, said: 'knossos did not read the history.', warn: true }
  return {
    ...base,
    said: null,
    loading: state?.phase === 'loading',
    days: answer.days ?? DAYS,
    commits: answer.commits ?? 0,
    truncated: answer.truncated === true,
    files: answer.files.map(f => ({ path: f.path, commits: f.commits, dependents: f.dependents, score: f.score, boundary: f.boundary, loc: locIn(root, f.path) })),
  }
}

/** The files the tab walks, highest score first: each opens as its detail. */
export const churnList = (input: ChurnInput): Openable[] => input.files.map(f => ({ name: f.path, canonical: f.path, loc: f.loc, file: true }))

/** How many of the highest scores the scatter draws as their rank. */
const RANKED = 9
/** The fewest files the list shows, however short the pane. */
const LIST_MIN = 5
/** The scatter's plot height, in rows, per tier. */
const PLOT_ROWS: Record<Tier, number> = { narrow: 6, medium: 9, wide: 11 }
/** The narrowest the scatter is drawn: below it the list alone says it. */
const PLOT_MIN = 30

/** A file's rank mark: its place among the nine highest, or nothing. */
const rankOf = (i: number): string => (i < RANKED ? String(i + 1) : ' ')

/**
 * The scatter: one mark per file, commits across (linear: they are a few
 * dozen at most) and dependents up (log: they span three orders, from the
 * fewest listed to the most), the axes faint, their ends labelled. A cell two files share shows the higher
 * ranked. The marked file is the accent dot, whatever its rank.
 */
export function scatterRows(files: ChurnFile[], columns: number, height: number, marked: number): Row[] {
  const maxC = Math.max(1, ...files.map(f => f.commits))
  const maxD = Math.max(1, ...files.map(f => f.dependents))
  // The axis runs from the fewest dependents listed to the most: the rows below the fewest would stand empty.
  const minD = Math.min(maxD, ...files.map(f => f.dependents))
  const span = Math.max(1e-9, Math.log1p(maxD) - Math.log1p(Math.max(0, minD)))
  const yLabel = Math.max(cells(grouped(maxD)), 1)
  const width = Math.max(4, columns - yLabel - 2)
  const plot = Array.from({ length: height }, () => Array.from<Segment | null>({ length: width }).fill(null))
  const rankAt = Array.from({ length: height }, () => Array.from<number>({ length: width }).fill(Number.POSITIVE_INFINITY))
  const place = (f: ChurnFile): [number, number] => {
    const x = Math.round(((f.commits - 0) / maxC) * (width - 1))
    const y = height - 1 - Math.round(((Math.log1p(Math.max(0, f.dependents)) - Math.log1p(Math.max(0, minD))) / span) * (height - 1))
    // A figure no scale can place (none of a knossos's own) stays on the plot's edge rather than off it.
    return [Number.isFinite(x) ? Math.min(width - 1, Math.max(0, x)) : 0, Number.isFinite(y) ? Math.min(height - 1, Math.max(0, y)) : height - 1]
  }
  files.forEach((f, i) => {
    const [x, y] = place(f)
    if (i === marked || i >= rankAt[y]![x]!) return
    rankAt[y]![x] = i
    plot[y]![x] = i < RANKED ? { text: rankOf(i), color: HEADING, bold: true } : { text: '•', color: FAINT }
  })
  const chosen = files[marked]
  if (chosen !== undefined) {
    const [x, y] = place(chosen)
    plot[y]![x] = { text: '◉', color: ACCENT, bold: true }
  }
  const rows: Row[] = plot.map((line, y) => {
    const label = y === 0 ? grouped(maxD) : y === height - 1 ? grouped(minD) : ''
    const segments: Segment[] = [{ text: `${padStart(label, yLabel)} `, dim: true }, { text: '│', color: FAINT }]
    let run = ''
    for (const cell of line) {
      if (cell === null) {
        run += ' '
        continue
      }
      if (run !== '') segments.push({ text: run })
      run = ''
      segments.push(cell)
    }
    if (run.trimEnd() !== '') segments.push({ text: run.trimEnd() })
    return { key: `churn-plot-${y}`, segments }
  })
  // Under the axis: 0 at its start, the most commits at its end, what runs across in the middle.
  const under = Array.from({ length: width }, () => ' ')
  const caption = 'commits →'
  const put = (at: number, text: string) => [...text].forEach((ch, i) => (under[at + i] = ch))
  if (width >= cells(caption) + cells(String(maxC)) + 4) put(Math.floor((width - cells(caption)) / 2), caption)
  put(0, '0')
  put(width - cells(String(maxC)), String(maxC))
  rows.push({ key: 'churn-axis', segments: [{ text: `${spaces(yLabel + 1)}╰${'─'.repeat(width)}`, color: FAINT }] })
  rows.push({ key: 'churn-ends', segments: [{ text: `${spaces(yLabel + 2)}${under.join('').trimEnd()}`, dim: true }] })
  return rows
}

/**
 * The Churn tab's cards: the scatter over the ranked list, or what to say
 * when there is nothing to draw. The list is the card that grows.
 */
export function churnArrangement(input: ChurnInput, selected: number, tier: Tier, hues: Hues = NO_HUES): Arrangement {
  const window = `last ${input.days} days`
  if (input.said !== null) {
    const said: Block = {
      key: 'churn',
      make: columns => ({
        key: 'churn',
        title: 'Churn hotspots',
        note: noteOf(window),
        body: wrapWords(input.said ?? '', Math.max(1, columns - 3)).map((line, i): Row => ({ key: `churn-said-${i}`, segments: [{ text: input.warn && i === 0 ? '▲  ' : '   ', color: STATUS_COLOURS.warn }, { text: line, ...(input.warn ? { color: STATUS_COLOURS.warn } : { dim: true }) }] })),
      }),
    }
    return { left: [said] }
  }
  const read = `${plural(input.commits, 'commit', 'commits')}${input.truncated ? ' (the newest)' : ''} · ${window}`
  const plot: Block = {
    key: 'churn-plot',
    make: columns =>
      input.files.length === 0 || columns < PLOT_MIN
        ? null
        : { key: 'churn-plot', title: 'Changed often, depended on widely', subtitle: 'dependents ↑', note: noteOf(read), body: scatterRows(input.files, columns, PLOT_ROWS[tier], selected) },
  }
  const list: Block = {
    key: 'churn-list',
    grow: { length: input.files.length, min: LIST_MIN },
    make: (columns, limit) => {
      if (input.files.length === 0) return { key: 'churn-list', title: 'Churn hotspots', note: noteOf(read), body: [dimRow('churn-none', `   No file the graph holds changed in the ${window}.`, columns)] }
      const view = windowOf(input.files.length, limit, selected)
      const spec = tableSpec(columns, input.files.map(f => f.path), tier === 'narrow' ? [] : input.files.map(f => f.boundary ?? ''), [numberWidth('commits', input.files.map(f => f.commits), tier), numberWidth('deps', input.files.map(f => f.dependents), tier)], 56, { tier })
      const max = Math.max(1, ...input.files.map(f => f.score))
      const body: Row[] = [tableHead('churn-cols', spec, { name: 'file', boundary: 'boundary', numbers: ['commits', 'deps'] })]
      input.files.slice(view.start, view.end).forEach((f, n) => {
        const i = view.start + n
        body.push(tableRow(`churn-${i}`, { name: f.path, boundary: tier === 'narrow' ? null : f.boundary, values: [f.commits, f.dependents], barValue: f.score, max, path: true, selected: i === selected, press: `row:${i}`, mark: { text: rankOf(i), dim: true } }, spec, hues))
      })
      body.push(...moreRows('churn-window', view, input.files.length, columns, 0))
      return { key: 'churn-list', title: 'Churn hotspots', subtitle: 'commits × dependents', note: noteOf(input.files.length === 0 ? read : `${grouped(input.files.length)} files`), body }
    },
  }
  return { left: [plot, list] }
}
