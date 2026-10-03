/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. The
 * shared primitives (segments, cutting, bars, the self-fitting table) are in
 * `rows.ts`; the Issues and Cycles tabs and the component detail in `views.ts`.
 */
import type { Dashboard, HubSort, KnossosView, PaneTab, Ranked, RefreshState, RescanState, TurnBrief } from '../../types'
import { formatAge } from './band'
import { countLabel } from './envelopes'
import { ACCENT, boundaryLabel, STATUS_COLOURS } from './palette'
import type { Tone } from './palette'
import {
  baseName,
  blank,
  button,
  cells,
  clip,
  dimRow,
  displayName,
  fit,
  joinFitting,
  numberWidth,
  padEnd,
  padStart,
  plural,
  rowWidth,
  sectionRow,
  spaces,
  spread,
  tableHead,
  tableRow,
  tableSpec,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Row, Segment } from './rows'
import { sparkline } from './sparkline'
import { cycleRows, cyclesInput, detailList, detailRows, issueCount, issueRows, issuesInput, issuesList, superscript } from './views'
import type { CyclesInput, DetailInput, IssuesInput, Openable } from './views'

// Everything the specs and the render hook draw with, from one module.
export { bar, button, cells, displayName, fit, plainText, rowWidth, tableSpec, wrapWords } from './rows'
export type { Field, Press, Row, Segment, TableSpec } from './rows'
export type { DetailInput, Openable } from './views'
export { detailInput, SIDE_BY_SIDE } from './views'

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
  /** Policy violations: the project's when the dashboard reports them, else the last turn's; null when neither was checked. */
  policy: string | null
  /** Diagnostic errors and warnings, or null when the dashboard reports none. */
  diagnostics: number | null
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
  /** The hubs tab's filter text, whether its field is open, and its sort. */
  filter: string
  filtering: boolean
  sort: HubSort
  issues: IssuesInput
  cycles: CyclesInput
  /** The component on show instead of a tab, or null. */
  detail: DetailInput | null
}

export const TABS: { id: PaneTab; full: string; short: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', short: 'Over', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', short: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', short: 'Bound', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', short: 'Cyc', hotkey: '4' },
  { id: 'issues', full: 'Issues', short: 'Iss', hotkey: '5' },
]

export const SORTS: HubSort[] = ['in', 'out', 'cross']

/** How many components the overview lists. */
export const OVERVIEW_TOP = 5
/** A trend is drawn only with this many snapshots, and only when it moves. */
const TREND_MIN_POINTS = 5
const TREND_MAX_POINTS = 24
/**
 * The widest the pane lays itself out: past this, rows would only stretch the
 * gap between a name and its numbers. An inline pane spans the terminal.
 */
export const CONTENT_MAX = 100

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

/**
 * The hubs tab's list: the components whose name (shown or canonical) holds
 * the filter, any case, most first by the sort, ties by in-degree then name.
 */
export function hubList(items: Item[], filter: string, sort: HubSort): Item[] {
  const needle = filter.trim().toLowerCase()
  const kept = needle === '' ? items : items.filter(i => i.name.toLowerCase().includes(needle) || i.canonical.toLowerCase().includes(needle))
  return [...kept].sort((a, b) => b[sort] - a[sort] || b.in - a.in || a.name.localeCompare(b.name))
}

/** The rows the selection walks: the detail's counterparts, else the tab's list. */
export function listFor(input: Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail'>): Openable[] {
  if (input.detail !== null) return detailList(input.detail)
  if (input.tab === 'overview') return input.items.slice(0, OVERVIEW_TOP)
  if (input.tab === 'hubs') return hubList(input.items, input.filter, input.sort)
  return input.tab === 'issues' ? issuesList(input.issues) : []
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

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'PY', rust: 'RS', go: 'GO', ruby: 'RB', java: 'JAVA' }
/** A count with thousands separated: 7,878. */
const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/**
 * The header's summary line: components, boundaries, drift and languages
 * from a dashboard that reports them; hubs, cycles and dead code otherwise.
 * Boundaries count the declared ones when there are any.
 */
export function summaryParts(d: Dashboard, hubs: number): string[] {
  const drift = `${d.freshness.drift_files} drifted`
  if (d.summary === undefined) {
    return [
      plural(hubs, 'hub', 'hubs'),
      `${countLabel(d.cycles.count, d.cycles.truncated)} ${d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles'}`,
      `${countLabel(d.dead_code_candidates, d.dead_code_truncated)} dead code`,
      drift,
    ]
  }
  const all = d.boundaries?.items ?? []
  const declared = all.filter(b => b.source === 'explicit')
  const boundaries = declared.length > 0 ? declared.length : all.length
  // Cut short: a floor when every listed one is counted (none declared) or the declared ones fill the list.
  const more = d.boundaries?.truncated === true && (declared.length === 0 || declared.length === all.length)
  const languages = d.summary.languages.map(l => LANGUAGES[l.language] ?? l.language.toUpperCase()).join(' ')
  // Languages last: the line drops parts from its end, and drift says more than the languages.
  return [
    `${grouped(d.summary.components)} components`,
    `${countLabel(boundaries, more)} ${boundaries === 1 && !more ? 'boundary' : 'boundaries'}`,
    drift,
    ...(languages === '' ? [] : [languages]),
  ]
}

/** Everything the pane draws, from state. `d` is an `ok` dashboard; `detail` the component on show, if any. */
export function paneInput(
  d: Dashboard,
  brief: TurnBrief | null,
  refresh: RefreshState,
  rescan: RescanState,
  view: KnossosView,
  now: number,
  terminal: boolean,
  detail: DetailInput | null = null,
): PaneInput {
  const items = mergeRanked(d)
  const issues = issuesInput(d)
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? String(brief.policy.total) : null
  const policy = issues.policy?.evaluated ? issues.policy.total : turn
  const diagnostics = issues.diagnostics === null ? null : issues.diagnostics.errors + issues.diagnostics.warnings
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now),
    canRescan: rescan.phase !== 'scanning' && needsRescan(d),
    summary: summaryParts(d, items.length),
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
      policy,
      diagnostics,
    },
    filter: view.filter ?? '',
    filtering: view.filtering ?? false,
    sort: view.sort ?? 'in',
    issues,
    cycles: cyclesInput(d),
    detail,
  }
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
 * then only the digits stay; a tab's badge (the Issues count) stays with its
 * label. On the terminal the rule marks the active tab; elsewhere tabs are
 * native buttons whose widths the pane cannot know.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean, badges: Partial<Record<PaneTab, string>> = {}): Row[] {
  const badged = (t: (typeof TABS)[number], label: string, sep: string) => (badges[t.id] ? `${label}${sep}${badges[t.id]}` : label)
  const variants: [(t: (typeof TABS)[number]) => string, number][] = [
    [t => badged(t, t.full, ' '), 2],
    [t => badged(t, t.short, ' '), 2],
    [t => badged(t, t.short, ''), 1],
    [t => badged(t, t.full.charAt(0), ''), 1],
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

/** The listed components as table rows, with the selection marker on `selected`; the bar draws `sort`. */
function componentRows(prefix: string, items: Item[], selected: number, columns: number, withDegrees: boolean, sort: HubSort = 'in'): Row[] {
  const titles = withDegrees ? ['in', 'out', 'cross'] : ['in']
  const columnsOf = (item: Item) => (withDegrees ? [item.in, item.out, item.cross] : [item.in])
  const widths = titles.map((t, i) => numberWidth(t, items.map(item => columnsOf(item)[i] ?? 0)))
  const spec = tableSpec(columns, items.map(i => i.name), items.map(i => boundaryLabel(i.boundary)), widths)
  const max = Math.max(0, ...items.map(i => i[sort]))
  return [
    ...(withDegrees ? [tableHead(`${prefix}-head`, spec, { name: 'name', boundary: 'boundary', numbers: titles })] : []),
    ...items.map((item, i) =>
      tableRow(`${prefix}-${i}`, {
        name: item.name,
        boundary: item.boundary,
        values: columnsOf(item),
        barValue: item[sort],
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

/** A count drawn green when zero and in `tone` otherwise, after a dim label. */
function verdict(label: string, value: string, tone: Tone): Segment[] {
  const zero = value === '0'
  return [{ text: `   ${label} `, dim: true }, zero ? { text: '✓ 0', color: STATUS_COLOURS.ok } : { text: `▲ ${value}`, color: STATUS_COLOURS[tone] }]
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
  const extra: Segment[] = [
    ...(h.policy === null ? [] : verdict('policy', h.policy, 'alert')),
    ...(h.diagnostics === null ? [] : verdict('diagnostics', String(h.diagnostics), 'warn')),
  ]
  return [
    sectionRow('health-head', 'Health', note, columns),
    metric('health-cycles', 'cycles', h.cycles, [], cyclesTrend),
    ...(h.maxDegree === null ? [] : [metric('health-degree', 'max degree', String(h.maxDegree), [], degreeTrend)]),
    metric('health-dead', 'dead code', h.deadCode, extra, ''),
  ]
}

/** The key buttons, wrapped onto as many rows as they need: a key that does not fit is never dropped, since it would stop working. */
function footerRows(input: PaneInput, columns: number, hasList: boolean): Row[] {
  const keys: Segment[] = []
  if (input.detail !== null) keys.push(button('back', 'back', 'b'))
  if (hasList) keys.push(button('down', '↓', 'j'), button('up', '↑', 'k'), button('open', 'open', 'o'))
  if (input.detail === null && input.tab === 'hubs') {
    keys.push(button('filter', 'filter', 'f'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '') keys.push(button('clear', 'clear', 'x'))
  }
  keys.push(button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const rows = wrapGroups('keys', keys.map(k => [{ ...k, dim: true }]), columns)
  if (input.showKeys) {
    const help =
      '1–5 or a click switch tabs. j/k, Tab or a click move the marker; o or Enter opens it, b goes back. ' +
      'On Hubs, f filters (type, then Enter; x clears) and s sorts by in, out or cross. ' +
      'r rescans when the snapshot is stale. Every key has a button.'
    wrapWords(help, columns).forEach((line, i) => rows.push({ key: `help-${i}`, segments: [{ text: line, dim: true }] }))
  }
  return rows
}

const PLACEHOLDER: Partial<Record<PaneTab, string>> = {
  boundaries: 'Boundary heat map: coming next.',
}

/** The hubs tab: its section header, the filter field or line, and the filtered, sorted table. */
function hubRows(input: PaneInput, list: Item[], selected: number, columns: number): Row[] {
  const hotspots = input.items.some(i => i.hotspotOnly) ? '◆ hotspot only' : ''
  const note = [hotspots, `by ${input.sort}`, input.partial ? 'partial' : ''].filter(s => s !== '').join(' · ')
  const rows: Row[] = [blank('gap-hubs'), sectionRow('hubs-head', 'Hubs and hotspots', note, columns)]
  if (input.filtering) {
    rows.push({ key: 'filter-row', segments: [{ text: '   filter: ', dim: true }, { text: input.filter, field: { id: 'filter', value: input.filter, placeholder: 'part of a name' } }] })
  } else if (input.filter !== '') {
    rows.push(dimRow('filter-row', `   filter "${input.filter}" · ${list.length} of ${input.items.length}`, columns))
  }
  if (list.length === 0) {
    const empty = input.filter === '' ? '   none' : `   no hub matches "${input.filter}"`
    return [...rows, dimRow('hubs-none', empty, columns)]
  }
  return [...rows, ...componentRows('hub', list, selected, columns, true, input.sort)]
}

/** Every row of the pane for `input`, none wider than `columns` (nor {@link CONTENT_MAX}). */
export function paneRows(input: PaneInput, columns: number): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const rows: Row[] = [...headerRows(input, width)]
  if (input.detail !== null) {
    rows.push(...detailRows(input.detail, width))
  } else {
    const count = issueCount(input.issues)
    rows.push(...tabRows(input.tab, width, input.terminal, count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}))
    if (input.tab === 'overview') {
      if (input.lastTurn !== null) rows.push(blank('gap-turn'), ...lastTurnRows(input.lastTurn, width))
      rows.push(blank('gap-health'), ...healthRows(input.health, width))
      rows.push(blank('gap-top'), sectionRow('top-head', 'Most depended on', input.partial ? 'partial · in' : 'in', width))
      rows.push(...(list.length === 0 ? [dimRow('top-none', '   none', width)] : componentRows('top', input.items.slice(0, OVERVIEW_TOP), selected, width, false)))
    } else if (input.tab === 'hubs') {
      rows.push(...hubRows(input, hubList(input.items, input.filter, input.sort), selected, width))
    } else if (input.tab === 'issues') {
      rows.push(...issueRows(input.issues, selected, width))
    } else if (input.tab === 'cycles') {
      rows.push(...cycleRows(input.cycles, width))
    } else {
      rows.push(blank('gap-soon'), ...wrapWords(PLACEHOLDER[input.tab] ?? '', width).map((line, i) => dimRow(`soon-${i}`, line, width)))
    }
  }
  rows.push(blank('gap-keys'), ...footerRows(input, width, list.length > 0 && input.detail === null))
  return rows.map(row => (rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
}
