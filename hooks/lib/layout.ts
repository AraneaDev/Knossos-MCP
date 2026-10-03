/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here.
 *
 * Widths are counted in code points. Every glyph the pane draws (blocks,
 * bullets, arrows, the ellipsis) is one cell wide, and names come from source
 * identifiers, so a code point is a cell.
 *
 * As the width shrinks a table gives way in a fixed order: its bars shorten
 * to a minimum, then names are cut with an ellipsis, then the boundary column
 * goes, then the bars go. The numbers always stay.
 */
import type { Dashboard, KnossosView, PaneTab, Ranked, RefreshState, RescanState, TurnBrief } from '../../types'
import { formatAge } from './band'
import { countLabel } from './envelopes'
import { ACCENT, boundaryColour, boundaryLabel, STATUS_COLOURS } from './palette'
import type { Tone } from './palette'
import { sparkline } from './sparkline'

/** A pressable segment: drawn as a plain Button, `hotkey: label` when it has a hotkey. */
export type Press = { id: string; label: string; hotkey?: string }
export type Segment = { text: string; color?: string; dim?: boolean; bold?: boolean; press?: Press }
export type Row = { key: string; segments: Segment[] }

/** One component in a list the selection walks: hubs and hotspots merged. */
export type Item = {
  name: string
  canonical: string
  kind: string
  boundary: string | null
  in: number
  out: number
  cross: number
  /** Ranked as a hotspot but not as a hub: marked `◆`. */
  hotspotOnly: boolean
}

export type PaneStatus = { tone: Tone; text: string }

export type LastTurn = {
  files: number
  dependents: number
  tests: number
  impact: { name: string; boundary: string | null; dependents: number }[]
}

export type Health = {
  cycles: string
  maxDegree: number | null
  cyclesTrend: number[]
  degreeTrend: number[]
  deadCode: string
  /** Violations the last turn introduced, or null when no turn was checked. */
  policy: number | null
}

/** Everything the pane draws, read from state once per render. */
export type PaneInput = {
  project: string
  status: PaneStatus
  canRescan: boolean
  summary: string[]
  tab: PaneTab
  selected: number
  showKeys: boolean
  /** Whether plain Buttons are drawn as text (`1: label`): the terminal. Elsewhere they are native. */
  terminal: boolean
  items: Item[]
  partial: boolean
  lastTurn: LastTurn | null
  health: Health
}

export const TABS: { id: PaneTab; full: string; short: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', short: 'Over', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', short: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', short: 'Bound', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', short: 'Cyc', hotkey: '4' },
  { id: 'issues', full: 'Issues', short: 'Iss', hotkey: '5' },
]

/** How many components the overview lists. */
export const OVERVIEW_TOP = 5
/** A trend is drawn only with this many snapshots, and only when it moves. */
const TREND_MIN_POINTS = 5
const TREND_MAX_POINTS = 24
const BAR_MIN = 4
/** The longest bar drawn: a wider column leaves the rest of it empty. */
const BAR_MAX = 40
const NAME_MIN = 8
const NAME_MAX = 32
const BOUNDARY_MAX = 12
/**
 * The widest the pane lays itself out: past this, rows would only stretch the
 * gap between a name and its numbers. An inline pane spans the terminal.
 */
export const CONTENT_MAX = 100
/** The selection marker, the hotspot mark and a space. */
const MARK = 3

export const cells = (text: string): number => [...text].length
export const rowWidth = (row: Row): number => row.segments.reduce((n, s) => n + cells(s.text), 0)
/** The row as the terminal shows it, colours aside. */
export const plainText = (row: Row): string => row.segments.map(s => s.text).join('')

/** `text` cut to `width` cells, the last one an ellipsis when anything was cut. */
export function fit(text: string, width: number): string {
  if (width <= 0) return ''
  const chars = [...text]
  return chars.length <= width ? text : `${chars.slice(0, width - 1).join('')}…`
}

const spaces = (n: number): string => ' '.repeat(Math.max(0, n))
const padEnd = (text: string, width: number): string => text + spaces(width - cells(text))
const padStart = (text: string, width: number): string => spaces(width - cells(text)) + text

const EIGHTHS = ['', '▏', '▎', '▍', '▌', '▋', '▊', '▉']

/**
 * A horizontal bar for `value` out of `max` in at most `width` cells, in
 * eighth blocks. Anything above zero shows at least a sliver.
 */
export function bar(value: number, max: number, width: number): string {
  if (width <= 0 || max <= 0 || value <= 0) return ''
  const eighths = Math.max(1, Math.round((Math.min(value, max) / max) * width * 8))
  return '█'.repeat(Math.floor(eighths / 8)) + EIGHTHS[eighths % 8]
}

/** A pressable segment; its text is what the terminal draws for it. */
export function button(id: string, label: string, hotkey?: string, style: Omit<Segment, 'text' | 'press'> = {}): Segment {
  const text = hotkey === undefined ? label : `${hotkey}: ${label}`
  return { ...style, text, press: hotkey === undefined ? { id, label } : { id, label, hotkey } }
}

/**
 * A component's name as the pane prints it: a method as `Class::method`
 * from its canonical name, anything else by its own short name. Display
 * only; lookups go by the canonical name.
 */
export function displayName(item: { name: string; canonical_name: string; kind: string }): string {
  if (item.kind !== 'method') return item.name
  const cut = item.canonical_name.lastIndexOf('::')
  if (cut < 0) return item.name
  const owner = item.canonical_name.slice(0, cut)
  // An anonymous class is named after where it is declared: keep the marker, not the path.
  const at = owner.indexOf('@')
  const scoped = at > 0 ? owner.slice(0, at) : owner
  const cls = scoped.slice(Math.max(scoped.lastIndexOf('\\'), scoped.lastIndexOf('/'), scoped.lastIndexOf('#')) + 1)
  return `${cls}::${item.canonical_name.slice(cut + 2)}`
}

/**
 * Hubs and hotspots as one list, one row per component: the two rankings
 * mostly name the same components, so listing both repeats them. Most
 * depended on first; a hotspot that is not a hub keeps its place after the
 * hubs with the same in-degree.
 */
export function mergeRanked(d: Pick<Dashboard, 'hubs' | 'hotspots'>): Item[] {
  const seen = new Map<string, Item>()
  const add = (r: Ranked, hotspotOnly: boolean) => {
    if (seen.has(r.canonical_name)) return
    seen.set(r.canonical_name, {
      name: displayName(r),
      canonical: r.canonical_name,
      kind: r.kind,
      boundary: r.boundary ?? null,
      in: r.in_degree ?? 0,
      out: r.out_degree ?? 0,
      cross: r.cross_boundary_degree ?? 0,
      hotspotOnly,
    })
  }
  d.hubs.forEach(hub => add(hub, false))
  d.hotspots.forEach(spot => add(spot, true))
  return [...seen.values()].sort((a, b) => b.in - a.in || Number(a.hotspotOnly) - Number(b.hotspotOnly))
}

/** The rows the selection walks on a tab: the overview's top five, every hub, else none. */
export function listFor(input: Pick<PaneInput, 'tab' | 'items'>): Item[] {
  if (input.tab === 'overview') return input.items.slice(0, OVERVIEW_TOP)
  return input.tab === 'hubs' ? input.items : []
}

/** The header's status: the snapshot's state and age, or what the rescan or refresh is doing. */
export function paneStatus(d: Dashboard, refresh: RefreshState, rescan: RescanState, now: number): PaneStatus {
  if (rescan.phase === 'scanning') return { tone: 'warn', text: 'scanning…' }
  const sinceFetch = refresh.fetchedAt === null ? 0 : now - refresh.fetchedAt
  const age = d.freshness.age_seconds === null ? '' : ` · ${formatAge(d.freshness.age_seconds * 1000 + sinceFetch)}`
  if (rescan.phase === 'failed') return { tone: 'alert', text: `rescan failed${rescan.reason ? `: ${rescan.reason}` : ''}` }
  if (refresh.failed) return { tone: 'alert', text: `refresh failed${age}` }
  return { tone: d.freshness.state === 'fresh' ? 'ok' : 'warn', text: `${d.freshness.state}${age}` }
}

/** Whether a rescan would change anything: the snapshot is stale or files drifted since it. */
export function needsRescan(d: Dashboard): boolean {
  return d.freshness.state !== 'fresh' || d.freshness.drift_files > 0
}

const baseName = (path: string): string => path.slice(path.lastIndexOf('/') + 1)
const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

/** The last turn's impact from a fresh brief, or null when there is none to show. */
export function lastTurnOf(brief: TurnBrief | null): LastTurn | null {
  if (brief === null || brief.status !== 'ok') return null
  const impact = Object.values(brief.impact)
    .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
    .map(f => ({ name: baseName(f.path), boundary: f.boundaries[0] ?? null, dependents: f.dependent_files }))
  const files = brief.changed_files.length + brief.added_files.length
  if (files === 0 && impact.length === 0) return null
  return { files, dependents: impact.reduce((n, f) => n + f.dependents, 0), tests: brief.tests.length, impact }
}

/** Everything the pane draws, from state. `d` is an `ok` dashboard. */
export function paneInput(
  d: Dashboard,
  brief: TurnBrief | null,
  refresh: RefreshState,
  rescan: RescanState,
  view: KnossosView,
  now: number,
  terminal: boolean,
): PaneInput {
  const items = mergeRanked(d)
  const summary = [
    plural(items.length, 'hub', 'hubs'),
    `${countLabel(d.cycles.count, d.cycles.truncated)} ${d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles'}`,
    `${countLabel(d.dead_code_candidates, d.dead_code_truncated)} dead code`,
    `${d.freshness.drift_files} drifted`,
  ]
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? brief.policy.total : null
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now),
    canRescan: rescan.phase !== 'scanning' && needsRescan(d),
    summary,
    tab: view.tab,
    selected: view.selected,
    showKeys: view.showKeys,
    terminal,
    items,
    partial: d.hubs_truncated,
    lastTurn: lastTurnOf(brief),
    health: {
      cycles: countLabel(d.cycles.count, d.cycles.truncated),
      maxDegree: d.trend.at(-1)?.max_degree ?? null,
      cyclesTrend: d.trend.map(t => t.cycles),
      degreeTrend: d.trend.map(t => t.max_degree),
      deadCode: countLabel(d.dead_code_candidates, d.dead_code_truncated),
      policy: turn,
    },
  }
}

/** Segments laid out as `left`, a gap, then `right` against the right edge; `right` goes first when both do not fit. */
function spread(key: string, left: Segment[], right: Segment[], columns: number): Row {
  const used = (segs: Segment[]) => segs.reduce((n, s) => n + cells(s.text), 0)
  const gap = columns - used(left) - used(right)
  if (right.length > 0 && gap >= 1) return { key, segments: [...left, { text: spaces(gap) }, ...right] }
  return { key, segments: clip(left, columns) }
}

/**
 * Segments cut to `columns`: the last text segment that crosses the edge is
 * truncated with an ellipsis, and nothing after it is kept. A pressable
 * segment is never cut: it is dropped whole, since its label is its address.
 */
function clip(segments: Segment[], columns: number): Segment[] {
  const out: Segment[] = []
  let used = 0
  for (const s of segments) {
    const w = cells(s.text)
    if (used + w <= columns) {
      out.push(s)
      used += w
      continue
    }
    if (s.press === undefined && columns - used > 0) out.push({ ...s, text: fit(s.text, columns - used) })
    break
  }
  return out
}

/** Whole parts joined by ` · `, as many as fit, dropping from the end. */
function joinFitting(parts: string[], columns: number): string {
  for (let n = parts.length; n > 0; n--) {
    const text = parts.slice(0, n).join(' · ')
    if (cells(text) <= columns) return text
  }
  return fit(parts[0] ?? '', columns)
}

/** Words wrapped onto as many rows as they need, none wider than `columns`. */
export function wrapWords(text: string, columns: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/).filter(w => w !== '')) {
    const next = line === '' ? word : `${line} ${word}`
    if (cells(next) <= columns) {
      line = next
      continue
    }
    if (line !== '') lines.push(line)
    line = fit(word, columns)
  }
  if (line !== '') lines.push(line)
  return lines
}

function headerRows(input: PaneInput, columns: number): Row[] {
  const dot: Segment = { text: `● ${input.status.text}`, color: STATUS_COLOURS[input.status.tone] }
  const rescan = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  // The rescan action and the dot come first; the project name gives way, then the status text.
  const rightMax = Math.max(0, columns - 1 - Math.min(cells(input.project), 4))
  let right: Segment[] = [dot, ...rescan]
  if (right.reduce((n, s) => n + cells(s.text), 0) > rightMax) {
    const room = rightMax - rescan.reduce((n, s) => n + cells(s.text), 0)
    right = room >= 3 ? [{ ...dot, text: fit(dot.text, room) }, ...rescan] : clip(rescan.slice(1), columns)
  }
  const rightWidth = right.reduce((n, s) => n + cells(s.text), 0)
  const title: Segment = { text: fit(input.project, Math.max(0, columns - rightWidth - 1)), bold: true }
  return [
    spread('title', [title], right, columns),
    { key: 'summary', segments: [{ text: joinFitting(input.summary, columns), dim: true }] },
  ]
}

/**
 * The tab strip and the rule under it. Labels shorten, then the gaps close,
 * then only the digits stay. On the terminal the rule marks the active tab;
 * elsewhere tabs are native buttons whose widths the pane cannot know.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean): Row[] {
  const variants: [(t: (typeof TABS)[number]) => string, number][] = [
    [t => t.full, 2],
    [t => t.short, 2],
    [t => t.short, 1],
    [t => t.full.charAt(0), 1],
  ]
  const width = (label: (t: (typeof TABS)[number]) => string, gap: number) =>
    TABS.reduce((n, t) => n + cells(`${t.hotkey}: ${label(t)}`), 0) + gap * (TABS.length - 1)
  const [label, gap] = variants.find(([l, g]) => width(l, g) <= columns) ?? variants[variants.length - 1]!
  const segments: Segment[] = []
  let rule = ''
  TABS.forEach((t, i) => {
    if (i > 0) {
      segments.push({ text: spaces(gap) })
      rule += '─'.repeat(gap)
    }
    const on = t.id === active
    const seg = button(`tab:${t.id}`, label(t), t.hotkey, on ? {} : { dim: true })
    segments.push(seg)
    rule += (on ? '━' : '─').repeat(cells(seg.text))
  })
  const strip = { key: 'tabs', segments: clip(segments, columns) }
  if (!terminal) return [strip]
  const shown = [...(rule + '─'.repeat(Math.max(0, columns - cells(rule))))].slice(0, columns).join('')
  const start = shown.indexOf('━')
  const end = shown.lastIndexOf('━') + 1
  const ruleRow: Row =
    start < 0
      ? { key: 'tab-rule', segments: [{ text: shown, dim: true }] }
      : {
          key: 'tab-rule',
          segments: [
            { text: shown.slice(0, start), dim: true },
            { text: shown.slice(start, end), color: ACCENT },
            { text: shown.slice(end), dim: true },
          ].filter(s => s.text !== ''),
        }
  return [strip, ruleRow]
}

/** A section header: its title in bold capitals, a note against the right edge when it fits. */
function sectionRow(key: string, title: string, note: string, columns: number): Row {
  const head: Segment = { text: fit(title.toUpperCase(), columns), bold: true }
  return spread(key, [head], note === '' ? [] : [{ text: note, dim: true }], columns)
}

const blank = (key: string): Row => ({ key, segments: [{ text: ' ' }] })

/** How a table of names, boundaries, a bar and numbers fits `columns`. */
export type TableSpec = { name: number; boundary: number; bar: number; numbers: number[] }

/**
 * Fits a table to `columns` (see the module docblock for the order things
 * give way in). `numbers` holds each number column's widest cell, title
 * included; the first number column is the one the bar draws.
 */
export function tableSpec(columns: number, names: string[], boundaries: string[], numbers: number[]): TableSpec {
  const fixed = MARK + numbers.reduce((n, w) => n + 1 + w, 0)
  const nameNeed = Math.min(NAME_MAX, Math.max(1, ...names.map(cells)))
  const boundaryNeed = Math.min(BOUNDARY_MAX, Math.max(0, ...boundaries.map(cells)))
  const attempt = (withBoundary: boolean, withBar: boolean): TableSpec | null => {
    const boundary = withBoundary && boundaryNeed > 0 ? boundaryNeed : 0
    const avail = columns - fixed - (boundary > 0 ? boundary + 1 : 0)
    if (!withBar) return avail >= Math.min(NAME_MIN, nameNeed) ? { name: avail, boundary, bar: 0, numbers } : null
    const barWidth = Math.max(BAR_MIN, avail - 1 - nameNeed)
    const name = avail - 1 - barWidth
    return name >= Math.min(NAME_MIN, nameNeed) ? { name, boundary, bar: barWidth, numbers } : null
  }
  return (
    attempt(true, true) ??
    attempt(false, true) ??
    attempt(false, false) ?? { name: Math.max(1, columns - fixed), boundary: 0, bar: 0, numbers }
  )
}

/** A column-title row for a table, dim. */
function tableHead(key: string, spec: TableSpec, titles: { name: string; boundary: string; numbers: string[] }): Row {
  let text = spaces(MARK) + padEnd(fit(titles.name, spec.name), spec.name)
  // A title that would be cut says nothing: the column's colours say what it is.
  if (spec.boundary > 0) text += ` ${padEnd(cells(titles.boundary) <= spec.boundary ? titles.boundary : '', spec.boundary)}`
  if (spec.bar > 0) text += ` ${spaces(spec.bar)}`
  spec.numbers.forEach((w, i) => (text += ` ${padStart(titles.numbers[i] ?? '', w)}`))
  return { key, segments: [{ text, dim: true }] }
}

type TableLine = {
  name: string
  boundary: string | null
  values: number[]
  max: number
  selected?: boolean
  hotspotOnly?: boolean
  press?: string
}

/** One table row: marker, name (pressable when it has an id), boundary, bar and numbers. */
function tableRow(key: string, line: TableLine, spec: TableSpec): Row {
  const colour = boundaryColour(line.boundary)
  const name = fit(line.name, spec.name)
  const segments: Segment[] = [
    { text: line.selected ? '›' : ' ', color: ACCENT, bold: true },
    { text: line.hotspotOnly ? '◆' : ' ', color: STATUS_COLOURS.warn },
    { text: ' ' },
    line.press === undefined ? { text: name } : button(line.press, name),
    { text: spaces(spec.name - cells(name)) },
  ]
  if (spec.boundary > 0) {
    const label = fit(boundaryLabel(line.boundary), spec.boundary)
    segments.push({ text: ' ' }, { text: padEnd(label, spec.boundary), ...(colour ? { color: colour } : { dim: true }) })
  }
  if (spec.bar > 0) {
    const glyphs = bar(line.values[0] ?? 0, line.max, Math.min(spec.bar, BAR_MAX))
    segments.push({ text: ' ' }, { text: glyphs, ...(colour ? { color: colour } : { dim: true }) }, { text: spaces(spec.bar - cells(glyphs)) })
  }
  spec.numbers.forEach((w, i) => segments.push({ text: ` ${padStart(String(line.values[i] ?? 0), w)}`, ...(i > 0 ? { dim: true } : {}) }))
  return { key, segments: segments.filter(s => s.text !== '') }
}

const numberWidth = (title: string, values: number[]) => Math.max(cells(title), ...values.map(v => cells(String(v))))

/** The listed components as table rows, with the selection marker on `selected`. */
function componentRows(prefix: string, items: Item[], selected: number, columns: number, withDegrees: boolean): Row[] {
  const titles = withDegrees ? ['in', 'out', 'cross'] : ['in']
  const columnsOf = (item: Item) => (withDegrees ? [item.in, item.out, item.cross] : [item.in])
  const widths = titles.map((t, i) => numberWidth(t, items.map(item => columnsOf(item)[i] ?? 0)))
  const spec = tableSpec(columns, items.map(i => i.name), items.map(i => boundaryLabel(i.boundary)), widths)
  const max = Math.max(0, ...items.map(i => i.in))
  return [
    ...(withDegrees ? [tableHead(`${prefix}-head`, spec, { name: 'name', boundary: 'boundary', numbers: titles })] : []),
    ...items.map((item, i) =>
      tableRow(`${prefix}-${i}`, {
        name: item.name,
        boundary: item.boundary,
        values: columnsOf(item),
        max,
        selected: i === selected,
        hotspotOnly: item.hotspotOnly,
        press: `row:${i}`,
      }, spec),
    ),
  ]
}

function lastTurnRows(turn: LastTurn, columns: number): Row[] {
  const note = `${plural(turn.files, 'file', 'files')} → ${turn.dependents} deps · ${plural(turn.tests, 'test', 'tests')}`
  const shown = turn.impact.slice(0, 4)
  const widths = [numberWidth('', shown.map(f => f.dependents))]
  const spec = tableSpec(columns, shown.map(f => f.name), shown.map(f => boundaryLabel(f.boundary)), widths)
  const max = Math.max(0, ...shown.map(f => f.dependents))
  return [
    sectionRow('turn-head', 'Last turn', note, columns),
    ...shown.map((f, i) => tableRow(`turn-${i}`, { name: f.name, boundary: f.boundary, values: [f.dependents], max }, spec)),
  ]
}

/** Glyphs for a trend that is long enough and moves; otherwise nothing, since a flat line says nothing. */
function trendGlyphs(values: number[]): string {
  if (values.length < TREND_MIN_POINTS || Math.min(...values) === Math.max(...values)) return ''
  return sparkline(values.slice(-TREND_MAX_POINTS))
}

function healthRows(h: Health, columns: number): Row[] {
  const cyclesTrend = trendGlyphs(h.cyclesTrend)
  const degreeTrend = trendGlyphs(h.degreeTrend)
  const note = cyclesTrend !== '' || degreeTrend !== '' ? `trend (${Math.max(h.cyclesTrend.length, h.degreeTrend.length)} scans)` : ''
  const values = [h.cycles, h.maxDegree === null ? '' : String(h.maxDegree), h.deadCode]
  const valueWidth = Math.max(...values.map(cells))
  const metric = (key: string, label: string, value: string, extra: Segment[], trend: string): Row => {
    const left: Segment[] = [{ text: `   ${padEnd(label, 11)}` }, { text: padStart(value, valueWidth), bold: true }, ...extra]
    return spread(key, left, trend === '' ? [] : [{ text: trend, color: ACCENT }], columns)
  }
  const policy: Segment[] =
    h.policy === null
      ? []
      : [{ text: '   policy ', dim: true }, h.policy === 0 ? { text: '✓ 0', color: STATUS_COLOURS.ok } : { text: `▲ ${h.policy}`, color: STATUS_COLOURS.alert }]
  return [
    sectionRow('health-head', 'Health', note, columns),
    metric('health-cycles', 'cycles', h.cycles, [], cyclesTrend),
    ...(h.maxDegree === null ? [] : [metric('health-degree', 'max degree', String(h.maxDegree), [], degreeTrend)]),
    metric('health-dead', 'dead code', h.deadCode, policy, ''),
  ]
}

function footerRows(input: PaneInput, columns: number, hasList: boolean): Row[] {
  const keys: Segment[] = hasList ? [button('down', '↓', 'j'), button('up', '↑', 'k'), button('open', 'open', 'o')] : []
  keys.push(button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const segments: Segment[] = []
  let used = 0
  for (const key of keys) {
    const gap = segments.length === 0 ? 0 : 2
    if (used + gap + cells(key.text) > columns) break
    if (gap > 0) segments.push({ text: spaces(gap) })
    segments.push({ ...key, dim: true })
    used += gap + cells(key.text)
  }
  const rows: Row[] = [{ key: 'keys', segments }]
  if (input.showKeys) {
    const help =
      '1–5 or a click switch tabs. j/k, Tab or a click move the marker; o or Enter opens it, b goes back. ' +
      'r rescans when the snapshot is stale. Every key has a button.'
    wrapWords(help, columns).forEach((line, i) => rows.push({ key: `help-${i}`, segments: [{ text: line, dim: true }] }))
  }
  return rows
}

const PLACEHOLDER: Partial<Record<PaneTab, string>> = {
  boundaries: 'Boundary heat map: coming next.',
  cycles: 'Cycles, largest first: coming next.',
  issues: 'Policy, diagnostics and dead code: coming next.',
}

/** Every row of the pane for `input`, none wider than `columns` (nor {@link CONTENT_MAX}). */
export function paneRows(input: PaneInput, columns: number): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  const rows: Row[] = [...headerRows(input, width), ...tabRows(input.tab, width, input.terminal)]
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const partial = input.partial ? 'partial' : ''
  if (input.tab === 'overview') {
    if (input.lastTurn !== null) rows.push(blank('gap-turn'), ...lastTurnRows(input.lastTurn, width))
    rows.push(blank('gap-health'), ...healthRows(input.health, width))
    rows.push(blank('gap-top'), sectionRow('top-head', 'Most depended on', partial === '' ? 'in' : `${partial} · in`, width))
    rows.push(...(list.length === 0 ? [{ key: 'top-none', segments: [{ text: '   none', dim: true }] }] : componentRows('top', list, selected, width, false)))
  } else if (input.tab === 'hubs') {
    const hotspots = input.items.some(i => i.hotspotOnly) ? '◆ hotspot only' : ''
    rows.push(blank('gap-hubs'), sectionRow('hubs-head', 'Hubs and hotspots', [hotspots, partial].filter(s => s !== '').join(' · '), width))
    rows.push(...(list.length === 0 ? [{ key: 'hubs-none', segments: [{ text: '   none', dim: true }] }] : componentRows('hub', list, selected, width, true)))
  } else {
    rows.push(blank('gap-soon'), ...wrapWords(PLACEHOLDER[input.tab] ?? '', width).map((line, i) => ({ key: `soon-${i}`, segments: [{ text: line, dim: true }] })))
  }
  rows.push(blank('gap-keys'), ...footerRows(input, width, list.length > 0))
  return rows.map(row => (rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
}
