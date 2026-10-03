/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. The
 * shared primitives (segments, cutting, bars, the self-fitting table) are in
 * `rows.ts`; the Issues and Cycles tabs and the component detail in `views.ts`.
 */
import type { AllowState, Dashboard, HubSort, KnossosView, PaneTab, Ranked, RefreshState, RescanState, SessionChanges, TurnBrief } from '../../types'
import { formatAge } from './band'
import { boundariesInput, boundaryRows } from './boundaries'
import type { BoundariesInput } from './boundaries'
import { changesInput, changesList, changesRows, lookAtOf, lookAtRows, NO_CHANGES } from './changes'
import type { ChangesInput, LookAt } from './changes'
import { countLabel } from './envelopes'
import { ACCENT, boundaryLabel, FAINT, huesOf, STATUS_COLOURS } from './palette'
import type { Hues, Tone } from './palette'
import {
  baseName,
  blank,
  button,
  cells,
  clip,
  dimRow,
  displayName,
  fit,
  grouped,
  joinFitting,
  numberWidth,
  padEnd,
  padStart,
  plural,
  rowWidth,
  sectionRow,
  sectionWidth,
  spaces,
  specWidth,
  spread,
  tableHead,
  tableRow,
  tableSpec,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, TableSpec } from './rows'
import { sparkline } from './sparkline'
import { cycleRows, cyclesInput, detailList, detailRows, issueCount, issueRows, issuesInput, issuesList, locIn, superscript } from './views'
import type { CyclesInput, DetailInput, IssuesInput, Openable } from './views'

// Everything the specs and the render hook draw with, from one module.
export { bar, button, cells, displayName, fileHref, fit, linkMarkdown, locOf, locText, plainText, rowWidth, tableSpec, wrapWords } from './rows'
export type { Field, Loc, Press, Row, Segment, TableSpec } from './rows'
export type { DetailInput, Openable } from './views'
export type { BoundariesInput } from './boundaries'
export { accumulate, cdFor, NO_CHANGES } from './changes'
export type { ChangesInput, LookAt } from './changes'
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
  impact: { name: string; boundary: string | null; dependents: number; loc: Loc | null }[]
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
  /** Null when the knossos that answered sends no boundary matrix. */
  boundaries: BoundariesInput | null
  /** The component on show instead of a tab, or null. */
  detail: DetailInput | null
  /** The allow-root offer and its progress, or null when no root was refused. */
  allow: AllowInput | null
  /** Each boundary's colour, the project's largest first. */
  hues: Hues
  /** Everything this session changed, for the Changes tab. */
  changes: ChangesInput
  /** The Overview's "Look at now", or null before anything changed. */
  lookAt: LookAt | null
}

/** A refused root the pane offers to allow: the root, the roots file it would join, and where the action stands. */
export type AllowInput = { root: string; rootsFile: string | null; phase: AllowState['phase']; reason: string | null }

/** The tabs in hotkey order; Changes came last, so the older tabs keep their digits. */
export const TABS: { id: PaneTab; full: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', hotkey: '4' },
  { id: 'issues', full: 'Issues', hotkey: '5' },
  { id: 'changes', full: 'Changes', hotkey: '6' },
]

export const SORTS: HubSort[] = ['in', 'out', 'cross']

/** How many components the overview lists. */
export const OVERVIEW_TOP = 5
/** The narrowest the overview's sections spread, so health and its trend never crowd together. */
const HEALTH_MIN = 48
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
export function listFor(input: Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail' | 'changes'>): Openable[] {
  if (input.detail !== null) return detailList(input.detail)
  if (input.tab === 'overview') return input.items.slice(0, OVERVIEW_TOP)
  if (input.tab === 'hubs') return hubList(input.items, input.filter, input.sort)
  if (input.tab === 'changes') return changesList(input.changes)
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

/**
 * The last turn's impact from a fresh brief, or null when there is none to
 * show. Each file is labelled with its own boundary (none when it sits in
 * none), never with its dependents'. `root` places the files on disk, so
 * their names open them.
 */
export function lastTurnOf(brief: TurnBrief | null, root: string | null = null): LastTurn | null {
  if (brief === null || brief.status !== 'ok') return null
  const impact = Object.values(brief.impact)
    .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
    .map(f => ({ name: baseName(f.path), boundary: f.boundary ?? null, dependents: f.dependent_files, loc: locIn(root, f.path) }))
  const files = brief.changed_files.length + brief.added_files.length
  if (files === 0 && impact.length === 0) return null
  return { files, dependents: impact.reduce((n, f) => n + f.dependents, 0), tests: brief.tests.length, impact }
}

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'PY', rust: 'RS', go: 'GO', ruby: 'RB', java: 'JAVA' }

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
  allow: AllowState | null = null,
  session: SessionChanges = NO_CHANGES,
  sessionRoot: string | null = null,
): PaneInput {
  const items = mergeRanked(d)
  const issues = issuesInput(d)
  const hues = huesOf(d)
  const changes = changesInput(session, d.project_root, hues, sessionRoot)
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
    lastTurn: lastTurnOf(brief, d.project_root),
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
    boundaries: boundariesInput(d),
    detail,
    allow: allowInput(brief, rescan, allow),
    hues,
    changes,
    lookAt: lookAtOf(changes),
  }
}

/**
 * The root a scan was refused for: the turn brief's, else the pane's last
 * rescan's; null when neither was refused.
 */
export function refusedRoot(brief: TurnBrief | null, rescan: RescanState): { root: string; rootsFile: string | null } | null {
  if (brief?.status === 'not-allowed') return { root: brief.refused_root ?? brief.path, rootsFile: brief.roots_file }
  return rescan.refusedRoot ? { root: rescan.refusedRoot, rootsFile: null } : null
}

/** The allow-root offer: the action under way for its root, else an offer for a refused root, else null. */
export function allowInput(brief: TurnBrief | null, rescan: RescanState, allow: AllowState | null): AllowInput | null {
  const refused = refusedRoot(brief, rescan)
  if (allow !== null && allow.phase !== 'idle' && allow.root !== null) {
    return { root: allow.root, rootsFile: refused?.root === allow.root ? refused.rootsFile : null, phase: allow.phase, reason: allow.reason }
  }
  return refused === null ? null : { ...refused, phase: 'idle', reason: null }
}

/**
 * The component a copy or an "Ask Claude" is about: the one on show in the
 * detail, else the marked row of the tab's list; null when there is none.
 */
export function subjectOf(input: Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail' | 'selected' | 'changes'>): Openable | null {
  if (input.detail !== null) {
    const c = input.detail.component
    return c === null ? null : { name: c.name, canonical: c.canonical, loc: c.loc }
  }
  const list = listFor(input)
  const marked = list[Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))]
  return marked === undefined ? null : { name: marked.name, canonical: marked.canonical, loc: marked.loc ?? null, ...(marked.file ? { file: true } : {}) }
}

/**
 * The place `e` opens in the editor: the Overview's riskiest touched file,
 * else the file of the component on show or of the marked row; null when
 * there is none.
 */
export function editTarget(input: Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail' | 'selected' | 'changes' | 'lookAt'>): Loc | null {
  if (input.detail === null && input.tab === 'overview') return input.lookAt?.file?.loc ?? null
  return subjectOf(input)?.loc ?? null
}

/** The test command `c` (on Changes) and `t` (on Overview) copy, or null when no test reaches the changes. */
export const testCommandOf = (input: Pick<PaneInput, 'changes'>): string | null => input.changes.command

/** The prompt "Ask Claude" submits for a component. */
export const askPrompt = (canonical: string): string =>
  `Using the Knossos graph, what depends on ${canonical} and what would break if I changed it?`

/**
 * The allow-root rows: the offer with its button, the question with its two
 * answers, the action running, or how it ended. The offer never runs
 * anything itself: `a` only asks, and only `y` on the question allows.
 */
export function allowRows(allow: AllowInput, columns: number): Row[] {
  const lines = (key: string, text: string, style: Omit<Segment, 'text'>): Row[] =>
    wrapWords(text, Math.max(1, columns - 3)).map((line, i) => ({ key: `${key}-${i}`, segments: [{ text: i === 0 ? '▲  ' : '   ', ...style }, { text: line, ...style }] }))
  const answers = (groups: Segment[][]) => wrapGroups('allow-keys', groups, columns, 2, 3)
  if (allow.phase === 'confirming') {
    const where = allow.rootsFile === null ? 'the roots file knossos reads' : allow.rootsFile
    return [
      ...lines('allow-ask', `Allow knossos to scan ${allow.root}? This adds it to ${where}.`, { color: STATUS_COLOURS.warn }),
      ...answers([[button('allow-yes', 'allow', 'y', { dim: false })], [button('allow-no', 'cancel', 'n', { dim: true })]]),
    ]
  }
  if (allow.phase === 'running') return [dimRow('allow-running', `   allowing ${allow.root}…`, columns)]
  if (allow.phase === 'done') {
    return wrapWords(`✓ ${allow.root} allowed · the next turn scans it`, columns).map((line, i) => ({
      key: `allow-done-${i}`,
      segments: [{ text: line, color: STATUS_COLOURS.ok }],
    }))
  }
  const said = allow.phase === 'failed' ? `allow-root failed${allow.reason ? `: ${allow.reason}` : ''} · ${allow.root}` : `${allow.root} is not an allowed root`
  return [
    ...lines('allow-offer', said, { color: allow.phase === 'failed' ? STATUS_COLOURS.alert : STATUS_COLOURS.warn }),
    ...answers([[button('allow', 'allow root', 'a', { dim: false })]]),
  ]
}

function headerRows(input: PaneInput, columns: number): Row[] {
  const dot: Segment = { text: '● ', color: STATUS_COLOURS[input.status.tone] }
  const said: Segment = { text: input.status.text, dim: true }
  const rescan = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  // The rescan action and the dot come first; the project name gives way, then the status text.
  const rightMax = Math.max(0, columns - 1 - Math.min(cells(input.project), 4))
  let right: Segment[] = [dot, said, ...rescan]
  if (right.reduce((n, s) => n + cells(s.text), 0) > rightMax) {
    const room = rightMax - rescan.reduce((n, s) => n + cells(s.text), 0)
    right = room >= 3 ? [dot, { ...said, text: fit(said.text, room - cells(dot.text)) }, ...rescan] : clip(rescan.slice(1), columns)
  }
  const rightWidth = right.reduce((n, s) => n + cells(s.text), 0)
  const title: Segment = { text: fit(input.project, Math.max(0, columns - rightWidth - 1)), bold: true, color: 'text' }
  return [
    spread('title', [title], right, columns),
    { key: 'summary', segments: [{ text: joinFitting(input.summary, columns), dim: true }] },
  ]
}

/**
 * The tab strip and the rule under it. Every tab by its full name when all
 * fit; else the active tab by its full name and the others by their digit
 * (each with its badge, the Issues and Changes counts), then with the gaps
 * closed, then digits alone. Never an abbreviation. On the terminal the rule
 * marks the active tab; elsewhere tabs are native buttons whose widths the
 * pane cannot know.
 *
 * A Button with a hotkey always draws `N: label`, so a tab drawn as its digit
 * alone is a Button without one, and its hotkey rides on a hidden twin
 * (`tabkey:<id>`, no text) placed just before it: the digit keys still switch
 * every tab. The pane's focus hook moves a ring landing on a twin onto its
 * visible tab.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean, badges: Partial<Record<PaneTab, string>> = {}): Row[] {
  type Tab = (typeof TABS)[number]
  const full = (t: Tab) => (badges[t.id] ? `${t.full} ${badges[t.id]}` : t.full)
  const digit = (t: Tab) => `${t.hotkey}${badges[t.id] ?? ''}`
  // Each variant: whether a tab shows its name, and the gap between tabs.
  const variants: [(t: Tab) => boolean, number][] = [
    [() => true, 2],
    [t => t.id === active, 2],
    [t => t.id === active, 1],
    [() => false, 1],
  ]
  const width = (named: (t: Tab) => boolean, gap: number) =>
    TABS.reduce((n, t) => n + cells(named(t) ? `${t.hotkey}: ${full(t)}` : digit(t)), 0) + gap * (TABS.length - 1)
  const [named, gap] = variants.find(([l, g]) => width(l, g) <= columns) ?? variants[variants.length - 1]!
  const segments: Segment[] = []
  let rule = ''
  TABS.forEach((t, i) => {
    if (i > 0) {
      segments.push({ text: spaces(gap) })
      rule += '─'.repeat(gap)
    }
    const on = t.id === active
    const style = on ? {} : { dim: true }
    if (named(t)) {
      segments.push(button(`tab:${t.id}`, full(t), t.hotkey, style))
    } else {
      segments.push({ text: '', hidden: true, press: { id: `tabkey:${t.id}`, label: t.full, hotkey: t.hotkey } }, button(`tab:${t.id}`, digit(t), undefined, style))
    }
    rule += (on ? '━' : '─').repeat(cells(segments[segments.length - 1]!.text))
  })
  const strip = { key: 'tabs', segments: clip(segments, columns) }
  if (!terminal) return [strip]
  const shown = [...(rule + '─'.repeat(Math.max(0, columns - cells(rule))))].slice(0, columns).join('')
  const start = shown.indexOf('━')
  const end = shown.lastIndexOf('━') + 1
  const ruleRow: Row =
    start < 0
      ? { key: 'tab-rule', segments: [{ text: shown, color: FAINT }] }
      : {
          key: 'tab-rule',
          segments: [
            { text: shown.slice(0, start), color: FAINT },
            { text: shown.slice(start, end), color: ACCENT },
            { text: shown.slice(end), color: FAINT },
          ].filter(s => s.text !== ''),
        }
  return [strip, ruleRow]
}

/** A component's numbers as a table shows them: in, out and cross, or in alone. */
const degreesOf = (item: Item, withDegrees: boolean): number[] => (withDegrees ? [item.in, item.out, item.cross] : [item.in])
const degreeTitles = (withDegrees: boolean): string[] => (withDegrees ? ['in', 'out', 'cross'] : ['in'])

/** How a table of components fits `columns`. */
function componentSpec(items: Item[], columns: number, withDegrees: boolean): TableSpec {
  const widths = degreeTitles(withDegrees).map((t, i) => numberWidth(t, items.map(item => degreesOf(item, withDegrees)[i] ?? 0)))
  return tableSpec(columns, items.map(i => i.name), items.map(i => boundaryLabel(i.boundary)), widths)
}

/** The listed components as table rows laid out by `spec`, with the selection marker on `selected`; the bar draws `sort`. */
function componentRows(prefix: string, items: Item[], selected: number, spec: TableSpec, withDegrees: boolean, hues: Hues, sort: HubSort = 'in'): Row[] {
  const titles = degreeTitles(withDegrees)
  const columnsOf = (item: Item) => degreesOf(item, withDegrees)
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
      }, spec, hues),
    ),
  ]
}

/** How many of the last turn's files the overview lists. */
const TURN_SHOWN = 4

/** How the last turn's table fits `columns`. */
function lastTurnSpec(turn: LastTurn, columns: number): TableSpec {
  const shown = turn.impact.slice(0, TURN_SHOWN)
  return tableSpec(columns, shown.map(f => f.name), shown.map(f => boundaryLabel(f.boundary)), [numberWidth('', shown.map(f => f.dependents))])
}

/** The last turn: what it touched and how much depends on it, each name a link to its file. */
function lastTurnRows(turn: LastTurn, spec: TableSpec, columns: number, hues: Hues): Row[] {
  const note = `${plural(turn.files, 'file', 'files')} → ${turn.dependents} deps · ${plural(turn.tests, 'test', 'tests')}`
  const shown = turn.impact.slice(0, TURN_SHOWN)
  const max = Math.max(0, ...shown.map(f => f.dependents))
  return [
    sectionRow('turn-head', 'Last turn', note, sectionWidth(specWidth(spec), 'Last turn', note, columns)),
    ...shown.map((f, i) => tableRow(`turn-${i}`, { name: f.name, boundary: f.boundary, values: [f.dependents], max, link: f.loc }, spec, hues)),
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
    const left: Segment[] = [{ text: `   ${padEnd(label, 11)}`, dim: true }, { text: padStart(value, valueWidth), bold: true, color: 'text' }, ...extra]
    return spread(key, left, trend === '' ? [] : [{ text: trend, dim: true }], columns)
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
  const changes = input.detail === null && input.tab === 'changes'
  if (input.detail !== null) keys.push(button('back', 'back', 'b'))
  if (hasList) keys.push(button('down', '↓', 'j'), button('up', '↑', 'k'), button('open', 'open', 'o'))
  // On Overview the "Look at now" row carries its own `e`.
  if (editTarget(input) !== null && !(input.detail === null && input.tab === 'overview')) keys.push(button('edit', 'edit', 'e'))
  if (changes) {
    if (input.changes.command !== null) keys.push(button('copy', 'copy test command', 'c'))
    if (subjectOf(input) !== null) keys.push(button('ask', 'ask Claude', 'q'))
  } else if (subjectOf(input) !== null) {
    keys.push(button('copy', 'copy', 'c'), button('ask', 'ask Claude', 'q'))
  }
  if (input.detail === null && input.tab === 'hubs') {
    keys.push(button('filter', 'filter', 'f'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '') keys.push(button('clear', 'clear', 'x'))
  }
  keys.push(button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const rows = wrapGroups('keys', keys.map(k => [{ ...k, dim: true }]), columns)
  if (input.showKeys) {
    const help =
      '1–6 or a click switch tabs. j/k, Tab or a click move the marker; o or Enter opens it, b goes back. ' +
      'A file:line opens in your editor on a click; e opens the marked one (on Overview, the riskiest file this session touched). ' +
      'On Hubs, f filters (type, then Enter; x clears) and s sorts by in, out or cross. ' +
      'c copies the marked component\'s full name (on Changes, the command for the tests that reach the changes; t copies it from Overview); ' +
      'q asks Claude what depends on it (your press sends the prompt). ' +
      'r rescans when the snapshot is stale; a offers to allow a refused root and asks first. Every key has a button.'
    wrapWords(help, columns).forEach((line, i) => rows.push({ key: `help-${i}`, segments: [{ text: line, dim: true }] }))
  }
  return rows
}

/** The hubs tab: its section header, the filter field or line, and the filtered, sorted table. */
function hubRows(input: PaneInput, list: Item[], selected: number, columns: number): Row[] {
  const hotspots = input.items.some(i => i.hotspotOnly) ? '◆ hotspot only' : ''
  const note = [hotspots, input.partial ? 'partial' : ''].filter(s => s !== '').join(' · ')
  const spec = componentSpec(list, columns, true)
  const title = 'Hubs and hotspots'
  const subtitle = `sorted by ${input.sort}`
  const head = sectionWidth(specWidth(spec), `${title} · ${subtitle}`, note, columns)
  const rows: Row[] = [blank('gap-hubs'), sectionRow('hubs-head', title, note, head, subtitle)]
  if (input.filtering) {
    rows.push({ key: 'filter-row', segments: [{ text: '   filter: ', dim: true }, { text: input.filter, field: { id: 'filter', value: input.filter, placeholder: 'part of a name' } }] })
  } else if (input.filter !== '') {
    rows.push(dimRow('filter-row', `   filter "${input.filter}" · ${list.length} of ${input.items.length}`, columns))
  }
  if (list.length === 0) {
    const empty = input.filter === '' ? '   none' : `   no hub matches "${input.filter}"`
    return [...rows, dimRow('hubs-none', empty, columns)]
  }
  return [...rows, ...componentRows('hub', list, selected, spec, true, input.hues, input.sort)]
}

/**
 * The pane with no dashboard to draw: what to do about it, and the allow-root
 * offer when the project's root was refused (often the reason there is none).
 */
export function emptyRows(allow: AllowInput | null, columns: number): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  const said = wrapWords('No Knossos data for this project. Scan it with knossos scan.', width).map((line, i) => dimRow(`empty-${i}`, line, width))
  return allow === null ? said : [...said, blank('gap-allow'), ...allowRows(allow, width)]
}

/**
 * The Overview: what to look at now (once this session changed something),
 * the last turn, health and the most depended on. Its sections share one
 * width, the widest of its tables, so their notes stand in one column.
 */
function overviewRows(input: PaneInput, selected: number, columns: number): Row[] {
  const top = input.items.slice(0, OVERVIEW_TOP)
  const topSpec = componentSpec(top, columns, false)
  const turnSpec = input.lastTurn === null ? null : lastTurnSpec(input.lastTurn, columns)
  const width = Math.min(columns, Math.max(HEALTH_MIN, top.length > 0 ? specWidth(topSpec) : 0, turnSpec === null ? 0 : specWidth(turnSpec)))
  const rows: Row[] = []
  if (input.lookAt !== null) rows.push(blank('gap-look'), ...lookAtRows(input.lookAt, columns, input.hues))
  if (input.lastTurn !== null && turnSpec !== null) rows.push(blank('gap-turn'), ...lastTurnRows(input.lastTurn, turnSpec, columns, input.hues))
  rows.push(blank('gap-health'), ...healthRows(input.health, width))
  const note = input.partial ? 'partial · in' : 'in'
  rows.push(blank('gap-top'), sectionRow('top-head', 'Most depended on', note, sectionWidth(specWidth(topSpec), 'Most depended on', note, columns)))
  rows.push(...(top.length === 0 ? [dimRow('top-none', '   none', columns)] : componentRows('top', top, selected, topSpec, false, input.hues)))
  return rows
}

/** Every row of the pane for `input`, none wider than `columns` (nor {@link CONTENT_MAX}). */
export function paneRows(input: PaneInput, columns: number): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const rows: Row[] = [...headerRows(input, width)]
  if (input.allow !== null) rows.push(...allowRows(input.allow, width))
  if (input.detail !== null) {
    rows.push(...detailRows(input.detail, width, input.hues))
  } else {
    const count = issueCount(input.issues)
    const touched = input.changes.files.length
    const badges: Partial<Record<PaneTab, string>> = {
      ...(count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}),
      ...(touched > 0 ? { changes: superscript(touched, input.changes.truncated) } : {}),
    }
    rows.push(...tabRows(input.tab, width, input.terminal, badges))
    if (input.tab === 'overview') {
      rows.push(...overviewRows(input, selected, width))
    } else if (input.tab === 'changes') {
      rows.push(...changesRows(input.changes, selected, width, input.hues))
    } else if (input.tab === 'hubs') {
      rows.push(...hubRows(input, hubList(input.items, input.filter, input.sort), selected, width))
    } else if (input.tab === 'issues') {
      rows.push(...issueRows(input.issues, selected, width, input.hues))
    } else if (input.tab === 'cycles') {
      rows.push(...cycleRows(input.cycles, width, input.hues))
    } else {
      rows.push(...boundaryRows(input.boundaries, width, input.hues))
    }
  }
  rows.push(blank('gap-keys'), ...footerRows(input, width, list.length > 0 && input.detail === null))
  return rows.map(row => (rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
}
