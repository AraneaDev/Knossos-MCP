import { describe, expect, it } from 'vitest'
import type { ComponentDetail, Dashboard, DetailState, KnossosView, PaneTab, TurnBrief } from '../../types'
import {
  allowInput,
  allowRows,
  askPrompt,
  emptyRows,
  noGraphOf,
  subjectOf,
  bar,
  detailInput,
  displayName,
  fit,
  hubList,
  listFor,
  mergeRanked,
  needsRescan,
  paneInput,
  paneStatus,
  rowWidth,
  tabRows,
  summaryParts,
  tableSpec,
  wrapWords,
} from './layout'
import { paneRows } from './__tests__/pane'
import type { PaneInput, Row } from './layout'
import { findRow, plainText, rawText } from './__tests__/plain-text'
import { compact, fitStart, shortName, wrapGroups } from './rows'
import { gitLabel, titleRow } from './layout'
import { chainGroups, cyclesInput } from './cycles'
import { issueCount, issuesInput, issuesList, superscript } from './views'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const TABS: PaneTab[] = ['overview', 'hubs', 'boundaries', 'cycles', 'issues', 'changes']

const hub = (name: string, canonical: string, kind: string, boundary: string | null, inDegree: number, out = 1, cross = 0) => ({
  name,
  canonical_name: canonical,
  kind,
  boundary,
  in_degree: inDegree,
  out_degree: out,
  cross_boundary_degree: cross,
})

const dash = (over: Partial<Dashboard> = {}): Dashboard => ({
  status: 'ok',
  path: '/work/Knossos-MCP',
  project_root: '/work/Knossos-MCP',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'stale', age_seconds: 40_000, drift_files: 41 },
  hubs: [
    hub('StableId', 'Knossos\\Store\\StableId', 'class', 'core', 525, 0, 0),
    hub('ArchitectureQueryService', 'Knossos\\Query\\ArchitectureQueryService', 'class', 'core', 262, 8, 1),
    hub('symbol', 'Knossos\\Store\\StableId::symbol', 'method', 'core', 199),
    hub('register', 'hooks/register.tsx#register', 'function', 'module:hooks (+typescript:hooks/tsconfig.json)', 61, 30, 4),
    hub('Caller', 'App\\Edge\\Caller', 'class', null, 3),
  ],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [
    { ...hub('StableId', 'Knossos\\Store\\StableId', 'class', 'core', 525, 0, 0), score: 525 },
    { ...hub('scan', 'Knossos\\Scan\\ProjectScanService::scan', 'method', 'core', 119, 58, 0), score: 177 },
  ],
  dead_code_candidates: 55,
  dead_code_truncated: false,
  cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [] },
  trend: [2, 2, 2, 2, 2, 2].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 161 })),
  fan_in: [],
  fan_in_truncated: false,
  ...over,
})

const brief = (over: Partial<TurnBrief> = {}): TurnBrief => ({
  status: 'ok',
  project_root: '/work/Knossos-MCP',
  project_id: 'p1',
  snapshot_id: 's1',
  scanned_at: 0,
  scan_ms: 5,
  reason: null,
  roots_file: null,
  refused_root: null,
  path: '/work/Knossos-MCP',
  changed_files: ['src/Query/TurnBriefService.php', 'hooks/register.tsx'],
  added_files: [],
  deleted_files: [],
  impact: {
    'hooks/register.tsx': { path: 'hooks/register.tsx', dependent_files: 6, boundaries: ['module:hooks'], boundary: 'module:hooks' },
    'src/Query/TurnBriefService.php': { path: 'src/Query/TurnBriefService.php', dependent_files: 21, boundaries: ['tests', 'core'], boundary: 'core' },
  },
  tests: [{ path: 'tests/a.php', distance: 1 }],
  policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
  ...over,
})

const view = (over: Partial<KnossosView> = {}): KnossosView => ({
  inspect: null,
  isBandHidden: false,
  tab: 'overview',
  selected: 0,
  showKeys: false,
  filter: '',
  filtering: false,
  sort: 'in',
  ...over,
})

const IDLE = { phase: 'idle', reason: null } as const
const FETCHED = { fetchedAt: 0, failed: false }

const input = (over: Partial<PaneInput> = {}, d = dash(), b: TurnBrief | null = brief()): PaneInput => ({
  ...paneInput(d, b, FETCHED, IDLE, view(), 0, true),
  ...over,
})

const textOf = (rows: Row[]) => rows.map(plainText).join('\n')
const row = findRow

describe('fit and bar', () => {
  it('cuts with an ellipsis only when it has to', () => {
    expect(fit('StableId', 8)).toBe('StableId')
    expect(fit('StableId', 5)).toBe('Stab…')
    expect(fit('StableId', 0)).toBe('')
  })
  it('draws a thin bar in half cells scaled to the maximum', () => {
    expect(bar(10, 10, 4)).toBe('━━━━')
    expect(bar(5, 10, 4)).toBe('━━')
    expect(bar(1, 4, 2)).toBe('╸')
    expect(bar(3, 4, 2)).toBe('━╸')
  })
  it('gives any non-zero value a half cell and zero nothing', () => {
    expect(bar(1, 100_000, 10)).toBe('╸')
    expect(bar(0, 10, 10)).toBe('')
    expect(bar(5, 0, 10)).toBe('')
    expect(bar(5, 10, 0)).toBe('')
  })
})

describe('displayName', () => {
  it('names a method by its class', () => {
    expect(displayName({ name: 'symbol', canonical_name: 'Knossos\\Store\\StableId::symbol', kind: 'method' })).toBe('StableId::symbol')
  })
  it('keeps an anonymous class marker rather than its path', () => {
    expect(displayName({ name: 'status', canonical_name: '{anonymous}@tests/A.php:21::status', kind: 'method' })).toBe('{anonymous}::status')
  })
  it('leaves classes and functions as they are', () => {
    expect(displayName({ name: 'StableId', canonical_name: 'Knossos\\Store\\StableId', kind: 'class' })).toBe('StableId')
    expect(displayName({ name: 'run', canonical_name: 'workers::rust::run', kind: 'function' })).toBe('run')
  })
})

describe('mergeRanked', () => {
  it('lists each component once, hubs first, a hotspot that is not a hub marked', () => {
    const items = mergeRanked(dash())
    expect(items.map(i => i.name)).toEqual(['StableId', 'ArchitectureQueryService', 'StableId::symbol', 'ProjectScanService::scan', 'register', 'Caller'])
    expect(items.filter(i => i.hotspotOnly).map(i => i.canonical)).toEqual(['Knossos\\Scan\\ProjectScanService::scan'])
  })
  it('reads hotspots from an older knossos without degrees or boundary', () => {
    const items = mergeRanked({ hubs: [], hotspots: [{ name: 'K', canonical_name: 'App\\K', kind: 'class', score: 7 }] })
    expect(items).toEqual([{ name: 'K', canonical: 'App\\K', kind: 'class', boundary: null, in: 0, out: 0, cross: 0, files: null, hotspotOnly: true, loc: null, top: [] }])
  })
})

describe('tableSpec', () => {
  const names = ['ArchitectureQueryService', 'StableId']
  const boundaries = ['core', 'typescript-wo']
  const numbers = [3, 3, 5]

  it('gives way in order: bars shrink, names truncate, the boundary goes, then the bars', () => {
    const at = (columns: number) => tableSpec(columns, names, boundaries, numbers)
    const full = at(120)
    // Names take what the longest needs, bars stop at their cap, and the rest stays at the right edge.
    expect(full.bar).toBe(16)
    expect(full.name).toBe(24)
    // A boundary is a chip: its swatch and a space before the label.
    expect(full.boundary).toBe(14)
    const shrinking = at(70)
    expect(shrinking.name).toBe(24)
    expect(shrinking.bar).toBeLessThan(full.bar)
    const truncating = at(55)
    expect(truncating.bar).toBe(4)
    expect(truncating.boundary).toBe(14)
    expect(truncating.name).toBeLessThan(24)
    const noBoundary = at(38)
    expect(noBoundary.boundary).toBe(0)
    expect(noBoundary.bar).toBe(4)
    const noBar = at(28)
    expect(noBar.boundary).toBe(0)
    expect(noBar.bar).toBe(0)
    expect(noBar.numbers).toEqual(numbers)
  })
  it('never lays out wider than the columns', () => {
    for (let columns = 20; columns <= 140; columns++) {
      const spec = tableSpec(columns, names, boundaries, numbers)
      const width = 3 + spec.name + (spec.boundary > 0 ? spec.boundary + 1 : 0) + (spec.bar > 0 ? spec.bar + 1 : 0) + 14
      expect(width).toBeLessThanOrEqual(columns)
    }
  })
})

describe('paneRows', () => {
  for (const columns of WIDTHS) {
    for (const tab of TABS) {
      it(`fits ${columns} columns on the ${tab} tab, with and without the key help`, () => {
        for (const showKeys of [false, true]) {
          for (const terminal of [true, false]) {
            const rows = paneRows(input({ tab, showKeys, terminal }), columns)
            for (const r of rows) expect(rowWidth(r), `${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
          }
        }
      })
    }
  }

  it('fits even a long project name, a long failure and a narrow pane', () => {
    const rescan = { phase: 'failed', reason: 'the project root is not an allowed root for scanning' } as const
    const long = paneInput(dash({ project_root: `/work/${'very-long-project-name-'.repeat(4)}` }), brief(), FETCHED, rescan, view(), 0, true)
    for (const columns of [12, 20, 40, 60]) {
      for (const r of paneRows(long, columns)) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })

  it('spans the whole width: no cap, whatever the pane has', () => {
    for (const columns of [120, 200]) expect(Math.max(...paneRows(input(), columns).map(rowWidth))).toBe(columns)
  })

  it('degrades the hubs table as the pane narrows', () => {
    const at = (columns: number) => textOf(paneRows(input({ tab: 'hubs' }), columns))
    // Wide: full names, boundary labels and bars.
    expect(at(90)).toMatch(/ArchitectureQueryService +■ core/)
    expect(at(90)).toContain('━')
    // Narrow: names cut before anything else goes; the numbers stay.
    expect(at(40)).toContain('…')
    expect(at(40)).toContain('525')
  })

  it('draws the header with the project, the status and the rescan action', () => {
    const rows = paneRows(input(), 60)
    // Narrow: the name and the status as a pill in its colour, the rescan beside it; no summary line under it.
    expect(plainText(row(rows, 'title')!)).toMatch(/^ Knossos-MCP +● stale 11h {3}r: rescan$/)
    expect(row(rows, 'title')!.segments.find(s => s.text === ' ● stale 11h ')).toMatchObject({ bg: 'warning', color: 'inverseText', bold: true })
    expect(row(rows, 'summary')).toBeUndefined()
    // Narrow: no row above the name, one between it and the tabs, then a blank row and a rule before the first card.
    expect(rows.slice(0, 5).map(r => r.key)).toEqual(['title', 'head-gap', 'tabs', 'head-end', 'head-rule'])
    const press = row(rows, 'title')!.segments.find(s => s.press)?.press
    expect(press).toEqual({ id: 'rescan', label: 'rescan', hotkey: 'r' })
  })

  it('offers no rescan for a fresh snapshot or while one runs', () => {
    const fresh = dash({ freshness: { state: 'fresh', age_seconds: 3, drift_files: 0 } })
    expect(textOf(paneRows(input({}, fresh), 60))).not.toContain('rescan')
    const scanning = paneInput(dash(), null, FETCHED, { phase: 'scanning', reason: null }, view(), 0, true)
    const title = plainText(row(paneRows(scanning, 60), 'title')!)
    expect(title).toContain('● scanning…')
    expect(title).not.toContain('rescan')
  })

  it('marks the selected row and keeps the marker inside the list', () => {
    const rows = paneRows(input({ tab: 'hubs', selected: 1 }), 60)
    expect(plainText(row(rows, 'hub-1')!)).toMatch(/^› {2}ArchitectureQueryService/)
    expect(plainText(row(rows, 'hub-0')!)).toMatch(/^ {3}StableId/)
    const past = paneRows(input({ tab: 'hubs', selected: 99 }), 60)
    const last = input().items.length - 1
    expect(plainText(row(past, `hub-${last}`)!).startsWith('›')).toBe(true)
  })

  it('makes each listed name pressable by its row', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 60)
    expect(row(rows, 'hub-3')!.segments.find(s => s.press)?.press).toEqual({ id: 'row:3', label: 'ProjectScanService::scan' })
    expect(plainText(row(rows, 'hub-3')!)).toMatch(/^ ◆ ProjectScanService::scan/)
  })

  it('colours a boundary and its bar alike, its track faint, and draws no boundary neutral', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 90)
    const core = row(rows, 'hub-0')!.segments.filter(s => s.text === '■' || s.text.includes('━'))
    expect(core).toHaveLength(2)
    expect(core[0]!.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(core[0]!.color).toBe(core[1]!.color)
    // The name on the chip stays in a text tone: the colour is on the swatch and the bar.
    expect(row(rows, 'hub-0')!.segments.find(s => s.text.trim() === 'core')).toMatchObject({ dim: true })
    const track = row(rows, 'hub-1')!.segments.find(s => /^·+$/.test(s.text))
    expect(track?.color).toBe('subtle')
    const none = row(rows, 'hub-5')!.segments.find(s => /[━╸]/.test(s.text))
    expect(none?.dim).toBe(true)
    // Numbers are plain text, the sorted column bright and the others dim; only boundaries, statuses and the selection carry colour.
    const numbers = row(rows, 'hub-0')!.segments.filter(s => /^ +[\d,]+$/.test(s.text))
    expect(numbers.map(s => (s.dim === true ? 'dim' : s.color))).toEqual(['text', 'dim', 'dim'])
    const byCross = row(paneRows(input({ tab: 'hubs', sort: 'cross' }), 90), 'hub-0')!.segments.filter(s => /^ +[\d,]+$/.test(s.text))
    expect(byCross.map(s => (s.dim === true ? 'dim' : s.color))).toEqual(['dim', 'dim', 'text'])
    expect(row(rows, 'hub-0')!.segments.find(s => s.text === '›')).toMatchObject({ color: 'suggestion' })
  })

  it('measures on the Overview and lists nothing: the components and files are on Hubs', () => {
    const text = textOf(paneRows(input(), 60))
    // Narrow, the stat tiles collapse to a line of every figure, deltas beside them when the dashboard sends them.
    const line = (rows: Row[]) => rows.filter(r => r.key.startsWith('tiles-line')).map(plainText).join(' / ')
    expect(line(paneRows(input(), 60))).toBe('2 cycles   161 max degree   55 dead code   0 policy / 41 drifted')
    const moved = dash({ deltas: { against: 's4', components: 0, cycles: 1, max_degree: -3, dead_code: 0, diagnostics: 0 } })
    expect(line(paneRows(input({}, moved), 60))).toBe('2 cycles ▲1   161 max degree ▼3   55 dead code   0 policy / 41 drifted')
    // Dead code counts the listed candidates; the trend carries the gate's wider count, so the tile takes neither its delta nor its trend.
    const gate = dash({ deltas: { against: 's4', components: 0, cycles: 0, max_degree: 0, dead_code: 25, diagnostics: 0 } })
    expect(line(paneRows(input({}, gate), 60))).toContain('55 dead code   0 policy')
    for (const gone of ['Most depended on', 'Last turn', 'Files most depended on', 'Look at now']) expect(text).not.toContain(gone)
    // Nothing changed this session and no chart sent: nothing to walk, and the session card is one line.
    expect(listFor(input())).toEqual([])
    expect(plainText(row(paneRows(input(), 60), 'session-head')!)).toMatch(/^This session +nothing changed yet$/)
  })

  it('draws the health rows only from five snapshots, and the tiles a sparkline only from five that move', () => {
    // Six flat snapshots: no sparkline in the tiles, and the rows lie flat, saying nothing moved.
    const flat = paneRows(input(), 60, 80)
    expect(flat.filter(r => r.key.startsWith('tiles-line')).map(plainText).join('')).not.toMatch(/[▁-█]/)
    expect(plainText(row(flat, 'health-0')!)).toMatch(/^cycles +▁+ +2 +no change$/)
    const few = dash({ trend: [1, 2, 3, 4].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 100 + i })) })
    expect(textOf(paneRows(input({}, few), 60, 80))).not.toContain('Health over time')
    const moving = dash({ trend: [1, 2, 2, 3, 2].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 100 + i })) })
    const rows = paneRows(input({}, moving), 60, 24)
    expect(textOf(rows)).toContain('2 cycles ▁▅▅█▅')
    expect(plainText(row(rows, 'health-head')!)).toMatch(/^Health over time +5 snapshots$/)
    // Each figure its own row on the same axis, the newest value after it; an older knossos sends no dead code or diagnostics.
    expect(plainText(row(rows, 'health-0')!)).toMatch(/^cycles +[▁-█]+ +2 +1–3 *$/)
    expect(plainText(row(rows, 'health-1')!)).toMatch(/^max degree +[▁-█]+ +104 +100–104 *$/)
    expect(row(rows, 'health-2')).toBeUndefined()
    expect(plainText(row(rows, 'health-axis')!)).toMatch(/^ +oldest─+newest$/)
  })

  it('names a policy violation in the error colour', () => {
    const rows = paneRows(input({}, dash(), brief({ policy: { status: 'evaluated', total: 3, violations: [], truncated: false } })), 60)
    const seg = rows.filter(r => r.key.startsWith('tiles-line')).flatMap(r => r.segments).find(s => s.text === '3')
    expect(seg?.color).toBe('error')
  })

  it('says when the knossos that answered sends no boundary map, and walks nothing there or on cycles', () => {
    expect(textOf(paneRows(input({ tab: 'boundaries' }), 60))).toContain('sends no boundary map')
    for (const tab of ['boundaries', 'cycles'] as const) expect(listFor(input({ tab }))).toEqual([])
  })

  it('shows the key help on request', () => {
    const rows = paneRows(input({ showKeys: true }), 60)
    expect(textOf(rows)).toMatch(/1–7 +switch tabs \(or click one\)/)
    expect(textOf(rows)).toMatch(/\n q +ask Claude about the marked row/)
    // One key a line, the key in the accent; a long action wraps under its own column.
    expect(row(rows, 'help-0')!.segments[1]).toMatchObject({ text: '1–7    ', color: 'suggestion' })
    expect(rows.filter(r => r.key.startsWith('help-')).every(r => r.segments[1]!.text.length === 7)).toBe(true)
    expect(textOf(paneRows(input(), 60))).not.toContain('switch tabs')
  })
})

describe('tabRows', () => {
  it('names every tab in full when it fits, the open one on the selection colour, and no digits on the bar', () => {
    const [strip, ...rest] = tabRows('hubs', 80, true)
    expect(rest).toHaveLength(0)
    expect(plainText(strip!)).toBe(' Overview    Hubs    Boundaries    Cycles    Issues    Changes    Branch ')
    expect(strip!.segments.find(s => s.press?.id === 'tab:hubs')).toMatchObject({ text: ' Hubs ', bg: 'selectionBg' })
    expect(strip!.segments.find(s => s.press?.id === 'tab:overview')).toMatchObject({ text: ' Overview ', dim: true })
    expect(strip!.segments.find(s => s.press?.id === 'tab:overview')?.bg).toBeUndefined()
    // Counts ride on the name as superscript badges.
    expect(plainText(tabRows('hubs', 80, true, { issues: '⁴', changes: '¹²' })[0]!)).toContain(' Issues⁴    Changes¹² ')
  })
  it('names the open tab and gives the others their digit where the names do not fit, and always when narrow', () => {
    expect(plainText(tabRows('overview', 50, true)[0]!)).toBe(' Overview    2    3    4    5    6    7 ')
    expect(plainText(tabRows('overview', 34, true)[0]!)).toBe(' Overview   2   3   4   5   6   7 ')
    expect(plainText(tabRows('overview', 32, true)[0]!)).toBe(' Overview  2  3  4  5  6  7 ')
    expect(plainText(tabRows('boundaries', 120, true, { issues: '⁴', changes: '⁸' }, true)[0]!)).toBe(' 1    2    Boundaries    4    5⁴    6⁸    7 ')
    expect(plainText(tabRows('changes', 30, true, { changes: '¹²' })[0]!)).toBe(' 1  2  3  4  5  Changes¹²  7 ')
    // Never an abbreviation: a pane too narrow even for that keeps the digits alone.
    expect(plainText(tabRows('boundaries', 20, true)[0]!)).toBe(' 1  2  3  4  5  6 ')
    for (const columns of [20, 40, 60, 72]) expect(plainText(tabRows('issues', columns, true, {}, true)[0]!)).not.toMatch(/\b(Over|Bound|Cyc|Chg)\b/)
    for (const columns of [20, 30, 40, 50, 60, 80, 200]) expect(rowWidth(tabRows('issues', columns, true)[0]!)).toBeLessThanOrEqual(columns)
  })
  it('keeps every digit a hotkey on a hidden twin that draws nothing, the bar drawing none', () => {
    for (const columns of [50, 80]) {
      const segments = tabRows('overview', columns, true)[0]!.segments
      const twin = segments.find(s => s.press?.id === 'tabkey:hubs')
      expect(twin).toMatchObject({ text: '', hidden: true, press: { hotkey: '2' } })
      // The twin comes before the tab it stands for, so a forward walk lands on the visible one.
      expect(segments.indexOf(twin!)).toBeLessThan(segments.findIndex(s => s.press?.id === 'tab:hubs'))
      expect(segments.find(s => s.press?.id === 'tab:hubs')?.press?.hotkey).toBeUndefined()
    }
  })
  it('is one row on every surface', () => {
    expect(tabRows('overview', 60, false)).toHaveLength(1)
  })
  it('gives every tab its digit as hotkey', () => {
    for (const columns of [60, 80]) {
      const presses = tabRows('overview', columns, true)[0]!.segments.flatMap(s => (s.press?.hotkey ? [s.press] : []))
      expect(presses.map(p => `${p.hotkey}:${p.id.replace('tabkey:', 'tab:')}`)).toEqual(['1:tab:overview', '2:tab:hubs', '3:tab:boundaries', '4:tab:cycles', '5:tab:issues', '6:tab:changes', '7:tab:branch'])
    }
  })
})

describe('paneStatus and needsRescan', () => {
  it('reads the snapshot state and an age that keeps counting', () => {
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: false }, IDLE, 61_000)).toEqual({ tone: 'warn', text: 'stale 11h' })
    const fresh = dash({ freshness: { state: 'fresh', age_seconds: 5, drift_files: 0 } })
    expect(paneStatus(fresh, { fetchedAt: 1_000, failed: false }, IDLE, 6_000)).toEqual({ tone: 'ok', text: 'fresh 10s' })
  })
  it('puts a failed refresh or rescan in red, the reason beside the pill', () => {
    expect(paneStatus(dash(), { fetchedAt: 0, failed: true }, IDLE, 0).tone).toBe('alert')
    expect(paneStatus(dash(), FETCHED, { phase: 'failed', reason: 'not-allowed' }, 0)).toEqual({ tone: 'alert', text: 'scan failed', note: 'not-allowed' })
    expect(paneStatus(dash(), FETCHED, { phase: 'failed', reason: null }, 0)).toEqual({ tone: 'alert', text: 'scan failed' })
  })
  it('keeps saying how old the figures on show are while a scan runs', () => {
    const scanning = { phase: 'scanning', reason: null } as const
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: false }, scanning, 61_000)).toEqual({ tone: 'warn', text: 'scanning… · stale 11h' })
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: true }, scanning, 61_000).text).toBe('scanning… · refresh failed 11h')
    expect(paneStatus(dash({ freshness: { state: 'fresh', age_seconds: 5, drift_files: 0 } }), FETCHED, scanning, 0).text).toBe('scanning…')
  })
  it('wants a rescan when stale or drifted', () => {
    expect(needsRescan(dash())).toBe(true)
    expect(needsRescan(dash({ freshness: { state: 'fresh', age_seconds: 1, drift_files: 2 } }))).toBe(true)
    expect(needsRescan(dash({ freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 } }))).toBe(false)
  })
})

describe('wrapWords', () => {
  it('wraps on words and cuts a word longer than a row', () => {
    expect(wrapWords('a bb ccc dddd', 6)).toEqual(['a bb', 'ccc', 'dddd'])
    expect(wrapWords('abcdefghij', 4)).toEqual(['abc…'])
  })
})

/** A dashboard from a knossos that reports everything the Issues and Cycles tabs and the header draw. */
const full = (over: Partial<Dashboard> = {}): Dashboard =>
  dash({
    summary: {
      components: 7878,
      kinds: [{ kind: 'method', count: 5959 }],
      kinds_truncated: true,
      files: 635,
      languages: [
        { language: 'php', files: 569 },
        { language: 'javascript', files: 41 },
        { language: 'rust', files: 9 },
      ],
      languages_truncated: false,
    },
    boundaries: {
      items: [
        { name: 'tests', source: 'explicit', members: 4913 },
        { name: 'core', source: 'explicit', members: 1610 },
        { name: 'namespace:Knossos', source: 'inferred', members: 5984 },
      ],
      truncated: true,
    },
    diagnostics: {
      total: 3,
      errors: 1,
      warnings: 1,
      infos: 1,
      items: [
        { severity: 'error', code: 'TS2307', message: "Cannot find module '@scope/missing' or its corresponding type declarations.", path: 'workers/typescript/src/scanner.ts', line: 14 },
        { severity: 'warning', code: 'PY001', message: 'Unused import', path: 'workers/python/bin/worker.py', line: 3 },
      ],
    },
    complexity_hotspots: [
      { path: 'workers/typescript/src/scanner.js', language: 'javascript', lines: 4986, dependent_files: 20, score: 99720 },
      { path: 'src/Discovery/ProjectDiscoverer.php', language: 'php', lines: 2835, dependent_files: 16, score: 45360 },
    ],
    over_budget: { source: 'maintainability-budgets.json', max_function_lines: 205, total: 3, files: [{ path: 'src/Scan/ProjectScanService.php', functions: 2, longest: 260, line: 88 }] },
    policy: {
      status: 'evaluated',
      total: 7,
      truncated: false,
      truncation_reasons: [],
      items: [
        {
          policy_id: 'core-does-not-reach-into-workers',
          source: 'Knossos\\Query\\ArchitectureQueryService::fileMetrics',
          source_kind: 'method',
          source_boundary: 'core',
          target: 'KnossosPhpScanner\\FactCollector',
          target_kind: 'class',
          target_boundary: 'php-worker',
          path: 'src/Query/ArchitectureQueryService.php',
          line: 191,
        },
      ],
    },
    dead_code: [
      {
        name: 'beforeTraverse',
        canonical_name: 'KnossosPhpScanner\\FactCollector::beforeTraverse',
        kind: 'method',
        boundary: 'php-worker',
        reachability: 'unreferenced',
        confidence: 'possible',
        path: 'workers/php/src/FactCollector.php',
        line: 108,
      },
      {
        name: 'helper',
        canonical_name: 'Knossos\\Support\\helper',
        kind: 'function',
        boundary: 'core',
        reachability: 'test_only',
        confidence: 'possible',
        path: 'src/Support/helper.php',
        line: 4,
      },
    ],
    cycles: {
      count: 2,
      truncated: false,
      truncation_reasons: [],
      largest: [
        {
          size: 5,
          members: ['a', 'b', 'c', 'd', 'e'],
          nodes: ['_add_instances', 'module_declarations', 'read_bounded', '_add_reexports', 'safe_file'].map((n, i) => ({
            name: n,
            canonical_name: `python.bin.worker.Index::${n}`,
            kind: 'method',
            boundary: i < 4 ? 'python-worker' : 'core',
          })),
          nodes_truncated: false,
        },
        { size: 4, members: ['visit', 'walk', 'Scanner::scan', 'emit'], nodes: undefined, nodes_truncated: false },
      ],
    },
    ...over,
  })

const fullInput = (over: Partial<PaneInput> = {}, d = full()): PaneInput => ({ ...paneInput(d, brief(), FETCHED, IDLE, view(), 0, true), ...over })

const detailAnswer = (over: Partial<NonNullable<ComponentDetail['component']>> = {}): ComponentDetail => ({
  status: 'ok',
  path: '/work/Knossos-MCP',
  name: 'Knossos\\Query\\DashboardService',
  project_id: 'p1',
  snapshot_id: 's1',
  candidates: [],
  component: {
    name: 'Knossos\\Query\\DashboardService',
    display_name: 'DashboardService',
    kind: 'class',
    path: 'src/Query/DashboardService.php',
    line: 28,
    boundary: 'core',
    boundaries: ['core', 'namespace:Knossos'],
    used_by: {
      count: 19,
      truncated: false,
      names: ['answer'],
      items: [
        { name: 'testDrift', canonical_name: 'Knossos\\Tests\\DashboardServiceTest::testDrift', kind: 'method', boundary: 'tests', edges: 3 },
        { name: 'answer', canonical_name: 'Knossos\\Cli\\Command\\BriefCommand::answer', kind: 'method', boundary: 'core', edges: 1 },
      ],
    },
    uses: {
      count: 2,
      truncated: false,
      names: ['BoundaryLabels', 'ProjectFindings'],
      items: [
        { name: 'BoundaryLabels', canonical_name: 'Knossos\\Query\\BoundaryLabels', kind: 'class', boundary: 'core', edges: 4 },
        { name: 'ProjectFindings', canonical_name: 'Knossos\\Query\\ProjectFindings', kind: 'class', boundary: 'core', edges: 2 },
      ],
    },
    annotations: [{ kind: 'note', value: 'Read-only: it never scans.' }],
    ...over,
  },
})

const shown = { name: 'Knossos\\Query\\DashboardService', label: 'DashboardService' }
const done = (answer: ComponentDetail | null): DetailState => ({ snapshot_id: 's1', name: shown.name, detail: answer, phase: 'done' })
const detailPane = (answer: ComponentDetail | null = detailAnswer(), state: DetailState | null = done(answer)) =>
  paneInput(full(), brief(), FETCHED, IDLE, view({ inspect: shown }), 0, true, detailInput(shown, state))

const widthsFit = (rows: Row[], columns: number) => {
  for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
}

describe('the header summary', () => {
  it('counts components, declared boundaries and languages when knossos reports them', () => {
    expect(summaryParts(full(), 6)).toEqual(['7,878 components', '2 boundaries', '41 drifted', 'PHP JS RS'])
    // The figures are the tiles'; the languages are chips in the title row, from the medium tier.
    expect(row(paneRows(fullInput(), 60), 'summary')).toBeUndefined()
    const title = row(paneRows(fullInput(), 100), 'title')!
    expect(title.segments.filter(s => s.bg === 'userMessageBackground').map(s => s.text)).toEqual([' PHP ', ' JS ', ' RS '])
    expect(title.segments.find(s => s.text === ' PHP ')?.dim).toBe(true)
    expect(row(paneRows(fullInput(), 60), 'title')!.segments.some(s => s.text === ' PHP ')).toBe(false)
  })
  it('falls back to hubs, cycles and dead code from an older knossos', () => {
    expect(summaryParts(dash(), 6)).toEqual(['6 hubs', '2 cycles', '55 dead code', '41 drifted'])
  })
  it('counts every boundary, as a floor when cut, where none is declared', () => {
    const inferred = full({ boundaries: { items: [{ name: 'namespace:App', source: 'inferred', members: 3 }], truncated: true } })
    expect(summaryParts(inferred, 0)[1]).toBe('1+ boundaries')
  })
  it('counts every declared boundary, also those past the short list, as a floor only past their own cap', () => {
    const items = Array.from({ length: 12 }, (_, i) => ({ name: `d${i}`, source: 'explicit', members: 50 - i }))
    const declared = Array.from({ length: 15 }, (_, i) => `d${i}`)
    expect(summaryParts(full({ boundaries: { items, truncated: true, declared, declared_truncated: false } }), 0)[1]).toBe('15 boundaries')
    expect(summaryParts(full({ boundaries: { items, truncated: true, declared, declared_truncated: true } }), 0)[1]).toBe('15+ boundaries')
  })
})

describe('the issues tab', () => {
  for (const columns of WIDTHS) {
    it(`fits ${columns} columns`, () => {
      widthsFit(paneRows(fullInput({ tab: 'issues' }), columns), columns)
      widthsFit(paneRows(fullInput({ tab: 'issues', showKeys: true, terminal: false }), columns), columns)
    })
  }
  it('lists violations, diagnostics, dead code, the complexity hotspots and the files over budget, each with its place', () => {
    const text = textOf(paneRows(fullInput({ tab: 'issues' }), 100))
    expect(text).toMatch(/Policy violations +▲ 7/)
    expect(text).toMatch(/ArchitectureQueryService::fileMetr… → ■ FactCollector ■ core +ArchitectureQueryService\.php:191/)
    expect(text).toContain('+6 not listed')
    expect(text).toMatch(/Diagnostics +1 error · 1 warning · 1 note/)
    expect(text).toMatch(/✗ TS2307 Cannot find module/)
    expect(text).toMatch(/scanner\.ts:14$/m)
    expect(text).toMatch(/Dead code +55 · first 2/)
    expect(text).toMatch(/FactCollector::beforeTraverse +■ php-worker +FactCollector\.php:108/)
    expect(text).toMatch(/◇ helper/)
    expect(text).toMatch(/Complexity hotspots +lines × dependents/)
    expect(text).toMatch(/workers\/typescript\/src\/scanner\.js .*━+ +4,986 +20/)
    expect(text).toMatch(/Over budget +▲ 3 functions over 205 lines/)
    expect(text).toMatch(/▲ src\/Scan\/ProjectScanService\.php +2 +260/)
    expect(text).toContain('+2 not listed')
  })
  it('gives way in order: names are cut, then the boundary goes, then the place', () => {
    const dead = (columns: number) => plainText(row(paneRows(fullInput({ tab: 'issues' }), columns), 'dead-0')!)
    expect(dead(90)).toMatch(/FactCollector::beforeTraverse +■ php-worker +FactCollector\.php:108$/)
    expect(dead(50)).toMatch(/FactCollector::… ■ php-worker …ollector\.php:108$/)
    expect(dead(40)).not.toContain('php-worker')
    expect(dead(40)).toMatch(/\.php:108$/)
    expect(dead(30)).not.toContain('.php')
    expect(dead(30)).toContain('FactCollector::')
  })
  it('walks violations, dead code, the hotspots and the files over budget, each row pressable by its index', () => {
    const input = fullInput({ tab: 'issues', selected: 1 })
    expect(listFor(input).map(i => i.canonical)).toEqual([
      'Knossos\\Query\\ArchitectureQueryService::fileMetrics',
      'KnossosPhpScanner\\FactCollector::beforeTraverse',
      'Knossos\\Support\\helper',
      'workers/typescript/src/scanner.js',
      'src/Discovery/ProjectDiscoverer.php',
      'src/Scan/ProjectScanService.php',
    ])
    const rows = paneRows(input, 90)
    expect(plainText(row(rows, 'dead-0')!)).toMatch(/^›/)
    expect(row(rows, 'dead-1')!.segments.find(s => s.press)?.press?.id).toBe('row:2')
    expect(row(rows, 'pol-0')!.segments.find(s => s.press)?.press?.id).toBe('row:0')
  })
  it('counts violations, errors and warnings into the tab label', () => {
    expect(issueCount(issuesInput(full()))).toEqual({ n: 9, plus: false })
    expect(superscript(9)).toBe('⁹')
    expect(superscript(12, true)).toBe('¹²⁺')
    expect(plainText(row(paneRows(fullInput(), 90), 'tabs')!)).toContain(' Issues⁹ ')
    expect(plainText(row(paneRows(fullInput(), 40), 'tabs')!)).toContain('5⁹')
    // Nothing to act on: no badge.
    expect(plainText(row(paneRows(input(), 90), 'tabs')!)).toMatch(/ Issues {4}Changes {4}Branch $/)
  })
  it('says what it cannot know from an older knossos, and when no policy is declared', () => {
    const old = textOf(paneRows(input({ tab: 'issues' }), 60))
    expect(old).toMatch(/Policy violations +not reported/)
    expect(old).toMatch(/Diagnostics +not reported/)
    const undeclared = full({ policy: { status: 'not_evaluated', total: 0, truncated: false, truncation_reasons: [], items: [] } })
    expect(textOf(paneRows(fullInput({ tab: 'issues' }, undeclared), 60))).toMatch(/Policy violations +no policies declared/)
    expect(issuesList(issuesInput(undeclared))).toHaveLength(5)
  })
  it('reads a policy total cut short as a floor', () => {
    const cut = full({ policy: { status: 'evaluated', total: 100, truncated: true, truncation_reasons: ['time_limit'], items: [] } })
    expect(textOf(paneRows(fullInput({ tab: 'issues' }, cut), 60))).toContain('▲ 100+')
    expect(issueCount(issuesInput(cut)).plus).toBe(true)
  })
})

describe('the cycles tab', () => {
  for (const columns of WIDTHS) {
    it(`fits ${columns} columns`, () => widthsFit(paneRows(fullInput({ tab: 'cycles' }), columns), columns))
  }
  it('draws the marked cycle as boxes in a serpentine, each member in its boundary colour, the crossings marked', () => {
    const rows = paneRows(fullInput({ tab: 'cycles' }), 100)
    const text = textOf(rows)
    expect(text).toMatch(/Cycle 1 +5 members · crosses 2 boundaries/)
    expect(text).toMatch(/All cycles +2 · largest first/)
    // Each member a box, the first marked: its frame and its label in the accent, its inside tinted.
    expect(text).toContain('│ Index::_add_instances')
    const drawn = rows.filter(r => r.key.split('|').some(k => k.startsWith('diagram-0-')))
    expect(drawn.length).toBeGreaterThan(5)
    const label = (name: string) => drawn.flatMap(r => r.segments).find(s => s.text === name)
    expect(label('Index::_add_instances')).toMatchObject({ bold: true, bg: 'userMessageBackground', press: { id: 'row:0' } })
    // Spanning two boundaries: every member's frame in its own boundary's colour (a pressable label keeps the engine's), the hop into core marked.
    const frames = new Set(drawn.flatMap(r => r.segments).filter(s => /[╭╮╰╯]/.test(s.text)).map(s => s.color))
    expect([...frames].filter(c => /_FOR_SUBAGENTS_ONLY$/.test(c ?? '')).length).toBe(2)
    expect(label('Index::read_bounded')?.press?.id).toBe('row:2')
    expect(drawn.flatMap(r => r.segments).some(s => s.text.includes('╫') || s.text.includes('╪'))).toBe(true)
    expect(plainText(row(rows, 'cycle-legend')!)).toBe('■ python-worker  ■ core  ╫ crosses a boundary')
    // The list under it names each cycle, the marked one tinted.
    expect(plainText(row(rows, 'cycle-0')!)).toBe('›  cycle 1 · 5 members · ■ python-worker +1 boundary')
    expect(row(rows, 'cycle-0')!.tint).toBe('userMessageBackground')
  })
  it('falls back to the chain as text and a member a row below fifty columns', () => {
    const rows = paneRows(fullInput({ tab: 'cycles' }), 40)
    const text = textOf(rows)
    expect(text).toContain('↻ ■ Index::_add_instances →')
    expect(text).toContain('→ ↻')
    expect(rows.some(r => r.key.startsWith('diagram-'))).toBe(false)
    expect(row(rows, 'member-0')!.segments.find(s => s.press)?.press?.id).toBe('row:0')
    expect(plainText(row(rows, 'member-0')!).startsWith('›')).toBe(true)
  })
  it('draws a cycle from an older knossos plain, and the members knossos did not list as one box', () => {
    const text = textOf(paneRows(fullInput({ tab: 'cycles', selected: 6 }), 100))
    expect(text).toContain('│ visit')
    expect(text).toMatch(/Cycle 2 +4 members/)
    const long = full({ cycles: { count: 60, truncated: true, truncation_reasons: ['result_limit'], largest: [{ size: 50, members: [], nodes: [{ name: 'a', canonical_name: 'a', kind: 'function', boundary: null }], nodes_truncated: true }] } })
    const cut = textOf(paneRows(fullInput({ tab: 'cycles' }, long), 100))
    expect(cut).toMatch(/Cycles +60\+ · 1 shown · largest first/)
    expect(cut).toContain('│ +49 not listed')
  })
  it('walks members: j and k name the box they move to, o opens a member, c copies it, q asks how to break its cycle', () => {
    const input = fullInput({ tab: 'cycles', selected: 1 })
    const list = listFor(input)
    expect(list.map(c => c.name)).toEqual(['Index::_add_instances', 'Index::module_declarations', 'Index::read_bounded', 'Index::_add_reexports', 'Index::safe_file', 'cycle 1', 'visit', 'walk', 'Scanner::scan', 'emit', 'cycle 2'])
    const rows = paneRows(input, 100)
    const keys = rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press] : [])))
    expect(keys.find(k => k.hotkey === 'j')?.id).toBe('next:2')
    expect(keys.find(k => k.hotkey === 'k')?.id).toBe('prev:0')
    expect(keys.find(k => k.hotkey === 'o')?.id).toBe('open')
    // Past a cycle's last member, the next cycle's first; a press on a cycle in the list marks its first member.
    expect(paneRows(fullInput({ tab: 'cycles', selected: 4 }), 100).flatMap(r => r.segments).find(s => s.press?.hotkey === 'j')?.press?.id).toBe('next:6')
    expect(row(rows, 'cycle-1')!.segments.find(s => s.press)?.press).toEqual({ id: 'mark:6', label: 'cycle 2' })
    const subject = subjectOf(input)!
    expect(subject.canonical).toBe('python.bin.worker.Index::module_declarations')
    expect(subject.ask).toContain('how could I break this dependency cycle')
    expect(subject.ask).toMatch(/_add_instances → .* → .*_add_instances\?/)
  })
  it('folds the middle of a long cycle on a narrow pane into a box the marker can rest on, and o unfolds it', () => {
    const names = Array.from({ length: 10 }, (_, i) => `m${i}`)
    const long = full({ cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [{ size: 10, members: names, nodes: names.map(n => ({ name: n, canonical_name: `A::${n}`, kind: 'method', boundary: null })), nodes_truncated: false }] } })
    const at = (selected: number, columns: number, unfolded: number[] = []) => paneRows(fullInput({ tab: 'cycles', selected, cycles: cyclesInput(long, unfolded) }, long), columns)
    const press = (rows: Row[], key: string) => rows.flatMap(r => r.segments).find(s => s.press?.hotkey === key)?.press?.id
    // Narrow: four members, the fold, the last.
    const raw = (rows: Row[]) => rows.map(rawText).join('\n')
    const text = raw(at(0, 60))
    expect(text).toContain(' … 5 more … │')
    expect(text).not.toMatch(/[│┤├] A::m5/)
    expect(text).toMatch(/[│┤├] A::m9/)
    expect(press(at(3, 60), 'j')).toBe('next:10')
    expect(press(at(10, 60), 'j')).toBe('next:9')
    expect(press(at(10, 60), 'o')).toBe('unfold:0:4')
    expect(at(10, 60).flatMap(r => r.segments).find(s => s.text.includes('… 5 more …'))).toMatchObject({ press: { id: 'fold:0:4' }, bg: 'userMessageBackground' })
    // Unfolded, or wide enough, every member.
    expect(raw(at(4, 60, [0]))).toMatch(/[│┤├] A::m5/)
    expect(raw(at(0, 200))).toMatch(/[│┤├] A::m5/)
    expect(press(at(3, 200), 'j')).toBe('next:4')
  })
  it('says when there is none', () => {
    expect(textOf(paneRows(fullInput({ tab: 'cycles' }, full({ cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] } })), 60))).toContain('No dependency cycles.')
  })
})

describe('the detail view', () => {
  for (const columns of WIDTHS) {
    it(`fits ${columns} columns, loading, answered and not found`, () => {
      widthsFit(paneRows(detailPane(), columns), columns)
      widthsFit(paneRows(detailPane(null, { ...done(null), phase: 'loading' }), columns), columns)
      widthsFit(paneRows(detailPane({ ...detailAnswer(), status: 'not-found', component: null }), columns), columns)
    })
  }
  it('heads with name, kind, boundary and place, then draws its neighbourhood round it', () => {
    const rows = paneRows(detailPane(), 140)
    expect(plainText(row(rows, 'detail-head')!)).toMatch(/^DashboardService +class · ■ core$/)
    expect(plainText(row(rows, 'detail-place')!)).toBe('src/Query/DashboardService.php:28')
    expect(plainText(row(rows, 'detail-canonical')!)).toBe('Knossos\\Query\\DashboardService')
    // Drawn round it: what uses it on the left, each with its count on its edge, what it uses on the right.
    const raw = rows.map(rawText).join('\n')
    expect(raw).toContain('│ DashboardServiceTest::testDrift ├')
    expect(raw).toMatch(/►│ DashboardService ├/)
    expect(raw).toMatch(/─ 3 ─/)
    expect(raw).toMatch(/─ 4 ─+►│ BoundaryLabels/)
    // Nineteen use it and two are listed: the rest as one box.
    expect(raw).toContain('│ +17 more')
    expect(plainText(row(rows, 'hood-head')!)).toMatch(/^Dependencies · used by 19 · uses 2 +edges$/)
    expect(textOf(rows)).toMatch(/Annotations\n {3}note: Read-only: it never scans\./)
  })
  it('stacks what uses it above it and what it uses below it on a narrow pane, and lists them below fifty columns', () => {
    const raw = paneRows(detailPane(), 60).map(rawText).join('\n')
    expect(raw.indexOf('DashboardServiceTest::')).toBeLessThan(raw.indexOf('│ DashboardService '))
    expect(raw.indexOf('│ DashboardService ')).toBeLessThan(raw.indexOf('BoundaryLabels'))
    expect(raw).toContain('▼')
    const text = textOf(paneRows(detailPane(), 40))
    expect(text.indexOf('Used by · 19')).toBeLessThan(text.indexOf('Uses · 2'))
    expect(text).toMatch(/BoundaryLabels +■ core +[━╸·]+ +4/)
  })
  it('makes every neighbour pressable, used by first, marks the one j and k reach, and offers back beside the list keys', () => {
    const input = detailPane()
    expect(listFor(input).map(i => i.name)).toEqual(['DashboardServiceTest::testDrift', 'BriefCommand::answer', 'BoundaryLabels', 'ProjectFindings'])
    for (const columns of [60, 140]) {
      const rows = paneRows(input, columns)
      const labels = rows.flatMap(r => r.segments).filter(s => s.press?.id.startsWith('rel:'))
      expect(labels.map(s => s.press!.id), `${columns}`).toEqual(expect.arrayContaining(['rel:0', 'rel:1', 'rel:2', 'rel:3']))
      // Moving about on the first row, what to do with the marked one after it.
      const keys = rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))
      expect(keys.slice(0, 4)).toEqual(['b', 'j', 'k', 'o'])
      expect(keys).toContain('q')
      // The marker walks used by, then uses: the third neighbour is the first it uses, its box tinted.
      const third = paneRows({ ...input, selected: 2 }, columns).flatMap(r => r.segments)
      expect(third.find(s => s.press?.id === 'rel:2'), `${columns}`).toMatchObject({ bold: true, bg: 'userMessageBackground' })
      expect(third.find(s => s.press?.id === 'rel:0')?.bg, `${columns}`).toBeUndefined()
      expect(row(rows, 'tabs')).toBeUndefined()
    }
  })
  it('says it is looking, or what knossos said instead', () => {
    expect(textOf(paneRows(detailPane(null, { ...done(null), phase: 'loading' }), 60))).toContain('Inspecting DashboardService…')
    expect(textOf(paneRows(detailPane(null), 60))).toContain('No details for DashboardService: knossos said nothing.')
    expect(textOf(paneRows(detailPane({ ...detailAnswer(), status: 'not-found', component: null }), 60))).toContain('No component matched')
  })
  it('lists names without counts or bars from an older knossos', () => {
    const old = detailAnswer({ used_by: { count: 1, truncated: false, names: ['Kernel'] }, uses: { count: 0, truncated: false, names: [] } })
    expect(paneRows(detailPane(old), 60).map(rawText).join('\n')).toMatch(/│ Kernel +├─+╮/)
    const text = textOf(paneRows(detailPane(old), 40))
    expect(text).toMatch(/Used by · 1\n› {2}Kernel/)
    expect(text).not.toMatch(/█/)
  })
})

describe('tables packed to the left', () => {
  // The longest name meets its boundary with one space, whatever the width; width the table does not need stays at the right edge.
  it('keeps every boundary beside its name, on every tab, at every width', () => {
    for (const columns of [...WIDTHS, 100]) {
      const hubs = paneRows(fullInput({ tab: 'hubs' }), columns)
      const issues = paneRows(fullInput({ tab: 'issues' }), columns)
      if (columns >= 60) expect(plainText(row(hubs, 'hub-1')!), `hubs ${columns}`).toMatch(/ArchitectureQueryService ■ core +━/)
      // Wide, dead code is half the pane: its longest name is cut before its boundary goes.
      if (columns >= 90 && columns <= 130) expect(plainText(row(issues, 'dead-0')!), `dead ${columns}`).toMatch(/FactCollector::beforeTraverse ■ php-worker +FactCollector\.php:108$/)
      for (const r of [...hubs, ...issues]) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })
  it("sets the title, the sort and the note into the card's top edge, which spans the card like its rows", () => {
    const rows = paneRows(fullInput({ tab: 'hubs' }), 100)
    const head = row(rows, 'hubs-head')!
    expect(plainText(head)).toMatch(/^Hubs and hotspots · sorted by in +◆ hotspot only$/)
    expect(rowWidth(head)).toBe(100)
    expect(rowWidth(row(rows, 'hub-0')!)).toBe(100)
  })
  it('makes every place on the Issues tab a link to its file and line', () => {
    const rows = paneRows(fullInput({ tab: 'issues' }), 90)
    expect(row(rows, 'dead-0')!.segments.find(seg => seg.link)?.link).toEqual({ path: '/work/Knossos-MCP/workers/php/src/FactCollector.php', line: 108 })
    // A hotspot or a file over budget is a row: it opens as the file's detail, and `e` opens it at its longest function.
    expect(row(rows, 'hot-0')!.segments.find(seg => seg.press)?.press).toEqual({ id: 'row:3', label: 'workers/typescript/src/scanner.js' })
    const listed = listFor(fullInput({ tab: 'issues' }))
    expect(listed.slice(3).map(i => [i.canonical, i.file, i.loc?.line])).toEqual([
      ['workers/typescript/src/scanner.js', true, null],
      ['src/Discovery/ProjectDiscoverer.php', true, null],
      ['src/Scan/ProjectScanService.php', true, 88],
    ])
  })
  it('says how the budget stands when nothing is over it, or there is none', () => {
    const none = textOf(paneRows(fullInput({ tab: 'issues' }, full({ over_budget: { source: 'maintainability-budgets.json', max_function_lines: 205, total: 0, files: [] } })), 100))
    expect(none).toMatch(/Over budget +✓ 0 functions over 205 lines/)
    expect(textOf(paneRows(fullInput({ tab: 'issues' }, full({ over_budget: null })), 100))).toMatch(/Over budget +no maintainability-budgets\.json/)
  })
})

describe('the pane at every width and height', () => {
  const many = dash({ hubs: Array.from({ length: 40 }, (_, i) => hub(`Hub${i}`, `App\\Hub${i}`, 'class', i % 3 === 0 ? 'core' : 'tests', 400 - i * 5, i, i % 4)), hotspots: [] })
  const shown = (rows: Row[], prefix: string) => rows.filter(r => r.key.split('|').some(k => new RegExp(`^${prefix}-\\d+$`).test(k))).length

  for (const columns of WIDTHS) {
    it(`lays out every tab at ${columns} columns, short and tall, within the width, its edges filling it`, () => {
      for (const height of [24, 60]) {
        for (const tab of TABS) {
          const rows = paneRows(input({ tab }, many), columns, height)
          for (const r of rows) expect(rowWidth(r), `${tab} ${height} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
          // Every card edge spans its column: the whole pane, or its half of the wide grid.
          for (const r of rows.filter(r => /-head$/.test(r.key) && /^(╭|──)/.test(plainText(r) === '' ? '' : r.segments[0]!.text))) expect(rowWidth(r)).toBe(columns)
        }
      }
    })
  }

  it('lists as many hubs as the height allows, then how many more', () => {
    const short = paneRows(input({ tab: 'hubs' }, many), 100, 24)
    const tall = paneRows(input({ tab: 'hubs' }, many), 100, 40)
    expect(short.length).toBeLessThanOrEqual(24)
    expect(shown(short, 'hub')).toBeGreaterThanOrEqual(5)
    expect(shown(tall, 'hub')).toBeGreaterThan(shown(short, 'hub'))
    // Too short for even the minimum: five still show, and the pane scrolls.
    expect(shown(paneRows(input({ tab: 'hubs' }, many), 100, 8), 'hub')).toBe(5)
    expect(tall.length).toBeLessThanOrEqual(40)
    expect(textOf(tall)).toMatch(new RegExp(`${40 - shown(tall, 'hub')} more ↓`))
    // However tall the pane, a list stops at 28 rows and says how many more.
    expect(shown(paneRows(input({ tab: 'hubs' }, many), 100, 200), 'hub')).toBe(28)
  })

  it('scrolls a list to the marker: the marked hub is drawn, with how many are above it', () => {
    const rows = paneRows(input({ tab: 'hubs', selected: 30 }, many), 100, 24)
    expect(plainText(row(rows, 'hub-30')!)).toMatch(/^›/)
    expect(textOf(rows)).toMatch(/\d+ above ↑ · \d+ more ↓/)
  })

  it('switches layout at the tier thresholds: one column to 130, two from 131', () => {
    const withFiles = { ...many, fan_in: [{ path: 'src/A.php', dependent_files: 30, boundaries: [], boundary: 'core' }] }
    const grid = (columns: number) => paneRows(input({ tab: 'hubs' }, withFiles), columns, 40).some(r => r.key.includes('|'))
    expect([60, 79, 80, 130].map(grid)).toEqual([false, false, false, false])
    expect([131, 140, 200].map(grid)).toEqual([true, true, true])
    // Medium tables add out, cross and the file to the in-degree a narrow pane shows.
    expect(textOf(paneRows(input({ tab: 'hubs' }, many), 100))).toMatch(/in +out +cross/)
  })

  it('lays the wide Overview out on a fixed grid: two equal columns two cells apart, the cards of a row equally tall', () => {
    const matrix = { boundaries: ['core', 'tests'], members: [10, 5], labelled: 15, boundaries_truncated: false, cells: [[4, 0], [3, 2]], forbidden: [], flows: [{ from: 1, to: 0, edges: 3, forbidden: false }], edges: 9, truncated: false, truncation_reasons: [] }
    const in_degree = { buckets: [{ from: 0, to: 0, components: 4 }, { from: 1, to: 5, components: 9 }, { from: 6, to: null, components: 2 }], truncated: false }
    for (const columns of [140, 200]) {
      const rows = paneRows(input({ tab: 'overview' }, dash({ boundary_matrix: matrix, in_degree })), columns, 60)
      const left = Math.floor((columns - 2) * 0.5)
      const head = rows.find(r => r.key.endsWith('|concentration-head'))!
      expect(rowWidth({ key: 'x', segments: head.segments.slice(0, head.split) }), `${columns}`).toBe(left + 2)
      expect(plainText({ key: 'x', segments: head.segments.slice(head.split) })).toMatch(/^Dependency concentration +15 components$/)
      // The composition and the concentration end on the same row: the shorter is stretched inside its frame.
      const ends = rows.filter(r => r.key.split('|').includes('composition-end'))
      expect(ends).toHaveLength(1)
      expect(ends[0]!.key.split('|')).toContain('concentration-end')
      for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })
})

describe('the hubs narrowed to an in-degree range', () => {
  it('lists only the hubs in the range an Overview bucket opened, says how many the bucket counts, and drops the files', () => {
    const d = dash({
      in_degree: { buckets: [{ from: 0, to: 0, components: 9 }, { from: 101, to: null, components: 7 }], truncated: false },
      fan_in: [{ path: 'src/A.php', dependent_files: 30, boundaries: [], boundary: 'core' }],
    })
    const narrowed = paneInput(d, brief(), FETCHED, IDLE, view({ tab: 'hubs', degree: { from: 101, to: null } }), 0, true)
    expect(narrowed.degree).toEqual({ from: 101, to: null, components: 7 })
    expect(listFor(narrowed).map(o => o.name)).toEqual(['StableId', 'ArchitectureQueryService', 'StableId::symbol', 'ProjectScanService::scan'])
    for (const columns of WIDTHS) {
      const rows = paneRows(narrowed, columns)
      expect(plainText(row(rows, 'degree-row')!), `${columns}`).toMatch(/^ *in-degree 101\+ · 4 listed of 7/)
      expect(rows.some(r => r.key.split('|').includes('files-head'))).toBe(false)
      expect(rows.flatMap(r => r.segments).some(s => s.press?.id === 'clear' && s.press.hotkey === 'x')).toBe(true)
      for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
    const none = paneInput(d, brief(), FETCHED, IDLE, view({ tab: 'hubs', degree: { from: 0, to: 0 } }), 0, true)
    expect(textOf(paneRows(none, 100))).toContain('none of the listed hubs is in this range')
  })
})

describe('the hubs filter and sort', () => {
  for (const columns of WIDTHS) {
    it(`fits ${columns} columns with the field open, a filter kept and every sort`, () => {
      for (const sort of ['in', 'out', 'cross'] as const) {
        widthsFit(paneRows(fullInput({ tab: 'hubs', filtering: true, filter: 'stab', sort }), columns), columns)
        widthsFit(paneRows(fullInput({ tab: 'hubs', filter: 'a', sort, showKeys: true }), columns), columns)
      }
    })
  }
  it('filters by any part of the shown or canonical name, any case', () => {
    const items = input().items
    expect(hubList(items, 'stable', 'in').map(i => i.name)).toEqual(['StableId', 'StableId::symbol'])
    expect(hubList(items, 'KNOSSOS\\QUERY', 'in').map(i => i.name)).toEqual(['ArchitectureQueryService'])
    expect(hubList(items, '', 'in')).toHaveLength(items.length)
  })
  it('sorts by in, out or cross, most first', () => {
    const items = input().items
    expect(hubList(items, '', 'out')[0]?.name).toBe('ProjectScanService::scan')
    expect(hubList(items, '', 'cross')[0]?.name).toBe('register')
  })
  it('shows the field while filtering, then the filter and how many match', () => {
    const open = paneRows(input({ tab: 'hubs', filtering: true, filter: 'stab' }), 60)
    const field = row(open, 'filter-row')!.segments.find(s => s.field)
    expect(field?.field).toEqual({ id: 'filter', value: 'stab', placeholder: 'part of a name' })
    const kept = paneRows(input({ tab: 'hubs', filter: 'stab' }), 60)
    expect(plainText(row(kept, 'filter-row')!)).toBe('   filter "stab" · 2 of 6')
    expect(plainText(row(kept, 'hub-0')!)).toContain('StableId')
    expect(textOf(paneRows(input({ tab: 'hubs', filter: 'zzz' }), 60))).toContain('no hub matches "zzz"')
  })
  it('names the sort, draws its bar, and offers n, s and x as keys, and f to find', () => {
    const rows = paneRows(input({ tab: 'hubs', sort: 'out', filter: 'a' }), 90)
    expect(plainText(row(rows, 'hubs-head')!)).toMatch(/^Hubs and hotspots · sorted by out +◆ hotspot only$/)
    expect(plainText(row(rows, 'hub-0')!)).toMatch(/ProjectScanService::scan +■ core +[━╸]+·* +119 +58 +0$/)
    const keys = rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))
    expect(keys).toEqual(['j', 'k', 'o', 'c', 'q', 'n', 's', 'x', 'f', 'h'])
  })
  it('wraps the keys rather than dropping one that does not fit', () => {
    const rows = paneRows(input({ tab: 'hubs', filter: 'a' }), 40)
    const keyRows = rows.filter(r => r.key.startsWith('keys'))
    expect(keyRows.length).toBeGreaterThan(1)
    expect(keyRows.flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))).toEqual(['j', 'k', 'o', 'c', 'q', 'n', 's', 'x', 'f', 'h'])
    widthsFit(rows, 40)
  })
})

describe('colour', () => {
  it('draws every tab in Claude Code theme keys only, never a raw colour', () => {
    for (const tab of ['overview', 'hubs', 'boundaries', 'cycles', 'issues'] as const) {
      for (const segment of paneRows(fullInput({ tab }), 100).flatMap(r => r.segments)) {
        for (const colour of [segment.color, segment.cell?.fg, segment.cell?.bg]) {
          if (colour !== undefined) expect(colour, `${tab}: ${segment.text}`).toMatch(/^[a-zA-Z][a-zA-Z0-9_-]*$/)
        }
      }
    }
  })
})

describe('the overview stat tiles', () => {
  it('show the project policy and diagnostics when knossos reports them, coloured only where they deviate', () => {
    const rows = paneRows(fullInput(), 140)
    const figures = plainText(row(rows, 'tiles-0-value')!)
    const labels = plainText(row(rows, 'tiles-0-label')!)
    expect(labels).toMatch(/^components +│ boundaries +│ cycles +│ max degree +│ dead code +│ diagnostics +│ policy +│ drifted$/)
    expect(figures).toMatch(/^7,878 +│ 2 +│ 2 +│ 161 +│ 55 +│ 2 +│ 7 +│ 41$/)
    const colour = (text: string) => row(rows, 'tiles-0-value')!.segments.find(s => s.text === text)?.color
    expect(colour('7,878')).toBe('text')
    expect(colour('55')).toBe('text')
    expect(colour('41')).toBe('suggestion')
    expect(colour('7')).toBe('error')
    expect(row(rows, 'tiles-0-value')!.segments.filter(s => s.text === '2').map(s => s.color)).toEqual(['text', 'warning', 'warning'])
    // Every figure stands at the left edge of its tile, over its label: the rules line up.
    const at = (r: Row) => [...rawText(r)].flatMap((ch, i) => (ch === '│' ? [i] : []))
    expect(at(row(rows, 'tiles-0-value')!)).toEqual(at(row(rows, 'tiles-0-label')!))
  })
})

describe('row primitives', () => {
  it('cuts a path from the front so the file name stays', () => {
    expect(fitStart('workers/typescript/src/scanner.js', 12)).toBe('…/scanner.js')
  })
  it('names the ends of a violation by class and member', () => {
    expect(shortName('Knossos\\Query\\Foo::bar')).toBe('Foo::bar')
    expect(shortName('Knossos\\Query\\Foo')).toBe('Foo')
    expect(shortName('hooks/register.tsx#register')).toBe('register')
  })
  it('wraps groups whole and indents every row', () => {
    const rows = wrapGroups('g', [[{ text: 'aaaa' }], [{ text: 'bbbb' }], [{ text: 'cccc' }]], 12, 1, 2)
    expect(rows.map(plainText)).toEqual(['  aaaa bbbb', '  cccc'])
    expect(rows.map(r => r.key)).toEqual(['g', 'g-1'])
  })
})

describe('the allow-root offer', () => {
  const refused = brief({ status: 'not-allowed', refused_root: '/work/Knossos-MCP', roots_file: '/data/roots.json' })
  const offer = (phase: 'idle' | 'confirming' | 'running' | 'done' | 'failed', reason: string | null = null) =>
    allowInput(refused, IDLE, { phase, root: '/work/Knossos-MCP', reason })!
  const keysOf = (rows: Row[]) => rows.flatMap(r => r.segments.flatMap(s => (s.press ? [`${s.press.hotkey}:${s.press.id}`] : [])))

  it('offers a refused root from the turn brief, else from the last rescan, else nothing', () => {
    expect(allowInput(refused, IDLE, null)).toEqual({ root: '/work/Knossos-MCP', rootsFile: '/data/roots.json', phase: 'idle', reason: null })
    expect(allowInput(brief(), { phase: 'failed', reason: 'not an allowed root', refusedRoot: '/work/x' }, null)?.root).toBe('/work/x')
    expect(allowInput(brief(), IDLE, null)).toBeNull()
    expect(allowInput(brief(), IDLE, { phase: 'done', root: '/work/x', reason: null })?.phase).toBe('done')
  })
  it('only asks on a: the offer has the one key, the question its two answers', () => {
    expect(keysOf(allowRows(offer('idle'), 60))).toEqual(['a:allow'])
    const asked = allowRows(offer('confirming'), 60)
    expect(keysOf(asked)).toEqual(['y:allow-yes', 'n:allow-no'])
    expect(asked.map(plainText).join(' ').replace(/\s+/g, ' ')).toContain('Allow knossos to scan /work/Knossos-MCP? This adds it to /data/roots.json.')
    expect(keysOf(allowRows(offer('running'), 60))).toEqual([])
    expect(keysOf(allowRows(offer('done'), 60))).toEqual([])
    expect(allowRows(offer('failed', 'knossos did not allow it'), 60).map(plainText).join(' ')).toContain('allow-root failed: knossos did not allow it')
    expect(keysOf(allowRows(offer('failed'), 60))).toEqual(['a:allow'])
  })
  it('sits under the header on every tab and in the empty pane, within the width', () => {
    for (const columns of WIDTHS) {
      for (const phase of ['idle', 'confirming', 'running', 'done', 'failed'] as const) {
        const rows = paneRows(input({ allow: offer(phase, 'knossos did not allow it') }), columns)
        widthsFit(rows, columns)
        expect(rows.findIndex(r => r.key.startsWith('allow'))).toBeGreaterThan(0)
        widthsFit(emptyRows('unscanned', offer(phase), columns), columns)
      }
    }
    // Refused, the one action is the offer; there is no second one beside it.
    expect(keysOf(emptyRows('unscanned', offer('idle'), 60))).toEqual(['a:allow'])
  })
})

describe('copy and Ask Claude', () => {
  it('act on the marked row of the tab, or on the component in the detail', () => {
    expect(subjectOf(input({ tab: 'hubs', selected: 1 }))).toEqual({ name: 'ArchitectureQueryService', canonical: 'Knossos\\Query\\ArchitectureQueryService', loc: null })
    expect(subjectOf(input({ tab: 'cycles' }))).toBeNull()
    expect(subjectOf(detailPane())?.canonical).toBe(detailPane().detail!.component!.canonical)
    expect(subjectOf(detailPane(null))).toBeNull()
  })
  it('offer c and q only where there is a component to act on', () => {
    const keys = (rows: Row[]) => rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))
    expect(keys(paneRows(input({ tab: 'hubs' }), 60))).toEqual(expect.arrayContaining(['c', 'q']))
    expect(keys(paneRows(input({ tab: 'boundaries' }), 60))).not.toContain('c')
    expect(keys(paneRows(input({ tab: 'cycles' }), 60))).not.toContain('q')
  })
  it('asks what depends on the component and what a change would break', () => {
    expect(askPrompt('Knossos\\Store\\StableId')).toBe(
      'Using the Knossos graph, what depends on Knossos\\Store\\StableId and what would break if I changed it?',
    )
  })
})

describe('the live watcher in the header', () => {
  const fresh = dash({ freshness: { state: 'fresh', age_seconds: 5, drift_files: 0 } })
  it('says live instead of an age while the watcher keeps a fresh graph current', () => {
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'live' })).toEqual({ tone: 'ok', text: 'live · 11s' })
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'following' })).toEqual({ tone: 'ok', text: 'following · 11s' })
    expect(paneStatus({ ...fresh, freshness: { ...fresh.freshness, age_seconds: null } }, FETCHED, IDLE, 6_000, { phase: 'live' }).text).toBe('live')
  })
  it("says so plainly when the session it follows stopped answering", () => {
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'following', stale: true })).toEqual({ tone: 'warn', text: 'fresh 11s', note: "another session's watcher is stuck" })
  })
  it('says scanning while the watcher scans, and offers no rescan of its own then', () => {
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'scanning' })).toEqual({ tone: 'warn', text: 'scanning…' })
    expect(paneStatus(dash(), FETCHED, IDLE, 0, { phase: 'scanning' }).text).toBe('scanning… · stale 11h')
    const drifted = dash({ freshness: { state: 'stale', age_seconds: 5, drift_files: 3 } })
    expect(paneInput(drifted, null, FETCHED, IDLE, view(), 0, true, null, null, undefined, null, { phase: 'scanning' }).canRescan).toBe(false)
    expect(paneInput(drifted, null, FETCHED, IDLE, view(), 0, true, null, null, undefined, null, { phase: 'live' }).canRescan).toBe(true)
  })
  it('keeps the old states when the graph is not fresh, when starting, or off', () => {
    expect(paneStatus(dash(), FETCHED, IDLE, 0, { phase: 'live' }).text).toBe('stale 11h')
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'starting' }).text).toBe('fresh 11s')
    expect(paneStatus(fresh, { fetchedAt: 0, failed: true }, IDLE, 6_000, { phase: 'live' }).tone).toBe('alert')
  })
})

describe('a boundary column that one boundary dominates', () => {
  const allCore = dash({
    hubs: [hub('A', 'App\\A', 'class', 'core', 30), hub('B', 'App\\B', 'class', 'core', 20), hub('C', 'App\\C', 'class', 'core', 10)],
    hotspots: [],
  })
  it('is said once in the note when every row shares it, and the column goes', () => {
    for (const columns of WIDTHS) {
      const rows = paneRows(input({ tab: 'hubs' }, allCore), columns)
      expect(plainText(row(rows, 'hubs-head')!)).toContain('all in core')
      expect(plainText(row(rows, 'hub-1')!)).not.toContain('core')
    }
  })
  it('draws every chip alike, a repeat included: no ditto tone, the swatch in the colour and the name dim', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 120)
    const label = (key: string) => row(rows, key)!.segments.find(s => s.text.trim() === 'core')
    const swatch = (key: string) => row(rows, key)!.segments.find(s => s.text === '■')
    // StableId (core), ArchitectureQueryService (core): the second repeats the first.
    expect(label('hub-0')).toMatchObject({ dim: true })
    expect(label('hub-1')).toMatchObject({ dim: true })
    expect(label('hub-1')?.color).toBeUndefined()
    expect(swatch('hub-0')?.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(swatch('hub-1')?.color).toBe(swatch('hub-0')?.color)
  })
})

describe('hubs carry their file', () => {
  const placed = dash({
    hubs: [{ ...hub('StableId', 'Knossos\\Store\\StableId', 'class', 'core', 525), path: 'src/Store/StableId.php', line: 19 }],
    hotspots: [],
  })
  it('so e opens a hub row without opening its detail first', () => {
    const items = mergeRanked(placed)
    expect(items[0]?.loc).toEqual({ path: '/work/Knossos-MCP/src/Store/StableId.php', line: 19 })
    const hubs = input({ tab: 'hubs', selected: 0 }, placed)
    expect(subjectOf(hubs)?.loc).toEqual({ path: '/work/Knossos-MCP/src/Store/StableId.php', line: 19 })
    expect(paneRows(hubs, 90).some(r => r.segments.some(s => s.press?.id === 'edit'))).toBe(true)
  })
  it('and a hub the dashboard places nowhere offers no e', () => {
    const hubs = input({ tab: 'hubs', selected: 0 })
    expect(paneRows(hubs, 90).some(r => r.segments.some(s => s.press?.id === 'edit'))).toBe(false)
  })
})

describe('the pane with no graph', () => {
  it('has a heading and one thing to do: ask Claude to scan it', () => {
    for (const columns of WIDTHS) {
      const rows = emptyRows('unscanned', null, columns)
      expect(rows[0]).toMatchObject({ key: 'empty-head', segments: [{ text: 'No architecture graph yet', bold: true }] })
      const presses = rows.flatMap(r => r.segments.flatMap(s => (s.press ? [s.press] : [])))
      expect(presses).toEqual([{ id: 'scan-ask', label: 'ask Claude to scan it', hotkey: 'q' }])
      for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })
  it('offers no scan while the first load is on its way or after a load that failed: q there sends nothing', () => {
    for (const columns of WIDTHS) {
      for (const state of ['loading', 'unreadable'] as const) {
        const rows = emptyRows(state, null, columns)
        expect(rows.flatMap(r => r.segments.filter(s => s.press !== undefined))).toEqual([])
        for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
      }
    }
    expect(emptyRows('loading', null, 90)[0]?.segments[0]?.text).toBe('Reading the graph…')
    expect(emptyRows('unreadable', null, 90).map(plainText).join(' ')).toMatch(/^Could not read the graph .*retrying\.$/)
  })
  it('tells the states apart from the dashboard and the last load', () => {
    const none = { fetchedAt: null, failed: false }
    expect(noGraphOf(null, none)).toBe('loading')
    expect(noGraphOf(null, { fetchedAt: null, failed: true })).toBe('unreadable')
    const error = { status: 'error' } as Dashboard
    const unscanned = { status: 'unscanned' } as Dashboard
    expect(noGraphOf(error, { fetchedAt: 5, failed: false })).toBe('unreadable')
    expect(noGraphOf(unscanned, { fetchedAt: 5, failed: false })).toBe('unscanned')
    // Said unscanned once, but the latest load did not answer: no longer known to be unscanned.
    expect(noGraphOf(unscanned, { fetchedAt: 5, failed: true })).toBe('unreadable')
  })
})

describe('the pane at every width and height, with a large project', () => {
  const HEIGHTS = [24, 40, 60] as const
  const matrix = { boundaries: ['core', 'tests', 'hooks'], members: [10, 5, 3], boundaries_truncated: false, cells: [[40, 0, 0], [30, 20, 0], [2, 0, 9]], forbidden: [], edges: 101, truncated: false, truncation_reasons: [] }
  const big = full({
    hubs: Array.from({ length: 50 }, (_, i) => ({ ...hub(`Hub${i}`, `App\\Hub${i}`, i % 2 === 0 ? 'class' : 'method', 'core', 500 - i * 7, i % 9, i % 4), dependent_files: 80 - i, path: `src/Hub${i}.php`, line: 10 + i })),
    hotspots: [],
    fan_in: Array.from({ length: 50 }, (_, i) => ({ path: `src/Deep/Area${i % 4}/File${i}.php`, dependent_files: 500 - i * 9, boundaries: ['core'], boundary: i % 3 === 0 ? 'tests' : 'core' })),
    trend: [1, 2, 2, 3, 2, 4, 3].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 100 + i * 3 })),
    boundary_matrix: matrix,
  })
  const at = (over: Partial<KnossosView> = {}) => paneInput(big, brief(), FETCHED, IDLE, view(over), 0, true)
  const shown = (rows: Row[], prefix: string) => rows.filter(r => r.key.split('|').some(k => new RegExp(`^${prefix}-\\d+$`).test(k))).length
  /** Where each column of the wide grid ends: the last row with anything in its left or right half. */
  const ends = (rows: Row[]): [number, number] => {
    let left = -1
    let right = -1
    rows.forEach((r, i) => {
      if (r.split === undefined) return
      if (r.segments.slice(0, r.split).some(s => s.text.trim() !== '')) left = i
      if (r.segments.slice(r.split).some(s => s.text.trim() !== '')) right = i
    })
    return [left, right]
  }

  for (const columns of WIDTHS) {
    for (const height of HEIGHTS) {
      it(`fits ${columns}x${height}: every tab within the width, the long lists within the height`, () => {
        for (const tab of TABS) {
          const rows = paneRows(at({ tab }), columns, height)
          for (const r of rows) expect(rowWidth(r), `${tab} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
          // Lists give rows back to fit a pane that holds their minimums; only the shortest panes scroll.
          if ((tab === 'hubs' || tab === 'overview') && height >= 40) expect(rows.length, tab).toBeLessThanOrEqual(height)
        }
      })
    }
  }

  it('lists follow the height on every tier: a taller pane shows more of the long lists', () => {
    for (const columns of WIDTHS) {
      const hubs = HEIGHTS.map(h => shown(paneRows(at({ tab: 'hubs' }), columns, h), 'hub'))
      expect(hubs[1], `${columns}`).toBeGreaterThan(hubs[0]!)
      expect(hubs[2], `${columns}`).toBeGreaterThan(hubs[1]!)
    }
  })

  it('collapses the tiles to a line when narrow, under a header of two rows at every width', () => {
    for (const columns of WIDTHS) {
      const rows = paneRows(at(), columns, 40)
      const narrow = columns < 80
      expect(rows.some(r => r.key === 'tiles-line'), `${columns}`).toBe(narrow)
      expect(rows.some(r => r.key === 'tiles-top'), `${columns}`).toBe(!narrow)
      expect(rows.slice(0, 6).map(r => r.key).filter(k => k === 'title' || k === 'tabs'), `${columns}`).toEqual(['title', 'tabs'])
      expect(row(rows, 'summary'), `${columns}`).toBeUndefined()
    }
    // Off the Overview there are no tiles, at any width.
    for (const columns of WIDTHS) expect(paneRows(at({ tab: 'hubs' }), columns, 40).some(r => r.key.startsWith('tiles-'))).toBe(false)
  })

  it('ends the two columns of the wide Overview and Hubs on the same row: the cards of a grid row are equally tall', () => {
    for (const columns of [140, 200]) {
      for (const height of HEIGHTS) {
        for (const tab of ['overview', 'hubs'] as const) {
          const [left, right] = ends(paneRows(at({ tab }), columns, height))
          expect(left, `${tab} ${columns}x${height}`).toBe(right)
        }
      }
    }
  })

  it('adds the dependent files and the file to a wide table, and caps its bar', () => {
    const rows = paneRows(at({ tab: 'hubs' }), 200, 40)
    expect(plainText(row(rows, 'hub-head')!)).toMatch(/in +out +cross +files +where$/)
    expect(plainText(row(rows, 'hub-0')!)).toMatch(/ 500 +0 +0 +80 Hub0\.php:10$/)
    const bar = row(rows, 'hub-0')!.segments.filter(s => /^[━╸·]+$/.test(s.text)).reduce((n, s) => n + [...s.text].length, 0)
    expect(bar).toBeLessThanOrEqual(30)
    // Medium has no room for them: the in, out and cross stay, the file where it fits.
    expect(plainText(row(paneRows(at({ tab: 'hubs' }), 100, 40), 'hub-head')!)).not.toContain('files')
  })

  it('lists the files most depended on beside the hubs, walked after them, opening as files', () => {
    const rows = paneRows(at({ tab: 'hubs' }), 200, 40)
    expect(rows.some(r => r.key.endsWith('|files-head'))).toBe(true)
    const list = listFor(at({ tab: 'hubs' }))
    expect(list).toHaveLength(50 + 50)
    expect(list[50]).toMatchObject({ canonical: 'src/Deep/Area0/File0.php', file: true })
    expect(row(rows, 'files-0')!.segments.find(s => s.press)?.press?.id).toBe('row:50')
    // The filter narrows both lists.
    expect(listFor(at({ tab: 'hubs', filter: 'File1' })).map(o => o.canonical)).toEqual(['src/Deep/Area1/File1.php', ...Array.from({ length: 10 }, (_, i) => `src/Deep/Area${(10 + i) % 4}/File${10 + i}.php`)])
    expect(listFor(at({ tab: 'hubs', filter: 'Hub4' })).map(o => o.canonical)).toEqual(['App\\Hub4', ...Array.from({ length: 10 }, (_, i) => `App\\Hub${40 + i}`)])
  })

})

describe('the header: the project, where its checkout stands, its languages and a status pill', () => {
  const HEIGHTS = [24, 40, 60] as const
  const git = { rev: '610c7731cafe0000000000000000000000000000', branch: 'feat/claude-code-mod' }
  const pane = (over: Partial<PaneInput> = {}) => fullInput({ git, ...over })

  it('names the branch and the short commit, or the commit alone on a detached head, and nothing without git', () => {
    expect(gitLabel(git)).toBe('feat/claude-code-mod · 610c773')
    expect(gitLabel({ ...git, branch: null })).toBe('@ 610c773')
    expect(gitLabel(null)).toBe('')
    expect(gitLabel(undefined)).toBe('')
    expect(plainText(row(paneRows(pane({ git: null }), 140), 'title')!)).not.toContain('·')
  })

  it('is two rows on every tab at every width and height, the title row never wider than the pane', () => {
    for (const columns of WIDTHS) {
      for (const height of HEIGHTS) {
        for (const tab of TABS) {
          const rows = paneRows(pane({ tab }), columns, height)
          // Room around it on a tall pane (none above the name when narrow); on a short one, the rows that say something and the rule.
          const head = height <= 24 ? ['title', 'tabs', 'head-rule'] : columns < 80 ? ['title', 'head-gap', 'tabs', 'head-end', 'head-rule'] : ['head-top', 'title', 'head-gap', 'tabs', 'head-end', 'head-rule']
          expect(rows.slice(0, head.length).map(r => r.key), `${columns}x${height} ${tab}`).toEqual(head)
          // Padded in from the edges: one cell narrow, two from the medium tier on.
          expect(plainText(row(rows, 'title')!).startsWith(columns < 80 ? ' K' : '  K'), `${columns}x${height} ${tab}`).toBe(true)
          for (const r of rows) expect(rowWidth(r), `${columns}x${height} ${tab} ${r.key}`).toBeLessThanOrEqual(columns)
        }
      }
    }
  })

  it('gives way chips first, then the commit, then the branch, keeping the name, the pill and the rescan', () => {
    const at = (columns: number) => plainText(titleRow(pane(), columns, columns < 80 ? 'narrow' : columns <= 130 ? 'medium' : 'wide'))
    expect(at(200)).toMatch(/^Knossos-MCP {2}feat\/claude-code-mod · 610c773 {3}PHP {3}JS {3}RS +● stale 11h {3}r: rescan$/)
    expect(at(80)).toMatch(/^Knossos-MCP {2}feat\/claude-code-mod · 610c773 +● stale 11h {3}r: rescan$/)
    expect(at(66)).toMatch(/^Knossos-MCP +● stale 11h {3}r: rescan$/)
    // Narrow: the name and the pill alone, however much room.
    expect(at(79)).toMatch(/^Knossos-MCP +● stale 11h {3}r: rescan$/)
    expect(at(40)).toMatch(/^Knossos-MCP +● stale 11h {3}r: rescan$/)
    for (const columns of [10, 16, 20, 24, 30]) expect(rowWidth(titleRow(pane(), columns, 'narrow')), `${columns}`).toBeLessThanOrEqual(columns)
  })

  it('draws the status as a pill in its tone, the words on the fill, and why beside it', () => {
    const pill = (input: PaneInput) => row(paneRows(input, 140), 'title')!.segments.find(s => s.text.startsWith(' ● '))!
    expect(pill(pane())).toMatchObject({ text: ' ● stale 11h ', bg: 'warning', color: 'inverseText', bold: true })
    const live = paneInput(full({ freshness: { state: 'fresh', age_seconds: 7, drift_files: 0 } }), brief(), FETCHED, IDLE, view(), 0, true, null, null, undefined, null, { phase: 'live' }, { git })
    expect(pill(live)).toMatchObject({ text: ' ● live · 7s ', bg: 'success' })
    const failed = paneInput(full(), brief(), FETCHED, { phase: 'failed', reason: 'not an allowed root' }, view(), 0, true, null, null, undefined, null, undefined, { git })
    expect(pill(failed)).toMatchObject({ text: ' ● scan failed ', bg: 'error' })
    expect(plainText(row(paneRows(failed, 140), 'title')!)).toMatch(/not an allowed root {3}● scan failed {3}r: rescan$/)
  })

  it('becomes the way back in a detail: project › tab › what is shown', () => {
    const rows = paneRows({ ...detailPane(), git }, 140)
    expect(plainText(row(rows, 'title')!)).toMatch(/^ {2}Knossos-MCP › Overview › DashboardService +● stale 11h {3}r: rescan$/)
    expect(row(rows, 'tabs')).toBeUndefined()
    // Cut from the label: the project and the way back stay.
    const narrow = plainText(row(paneRows({ ...detailPane(), git }, 50), 'title')!)
    expect(narrow.startsWith(' Knossos-MCP › ')).toBe(true)
    for (const columns of WIDTHS) widthsFit(paneRows({ ...detailPane(), git }, columns), columns)
  })
})

describe('the marked row, tinted across', () => {
  it('keeps its marker and lays the tint from edge to edge of its card, at every tier', () => {
    for (const columns of WIDTHS) {
      const rows = paneRows(fullInput({ tab: 'hubs', selected: 1 }), columns)
      const marked = row(rows, 'hub-1')!
      expect(plainText(marked).startsWith('›'), `${columns}`).toBe(true)
      expect(marked.segments.filter(s => s.text !== '' && s.text !== '│').every(s => s.bg === 'userMessageBackground'), `${columns}`).toBe(true)
      expect(row(rows, 'hub-0')!.segments.some(s => s.bg !== undefined), `${columns}`).toBe(false)
      // The tinted part reaches the frame on a framed card, and the pane's edge on a narrow one.
      const raw = rows.find(r => r.key.split('|').includes('hub-1'))!
      const tinted = raw.segments.filter(s => s.bg === 'userMessageBackground').reduce((n, s) => n + [...s.text].length, 0)
      if (columns < 80) expect(tinted, `${columns}`).toBe(columns)
      else if (columns <= 130) expect(tinted, `${columns}`).toBe(columns - 2)
    }
  })
})

describe('the footer: moves on the left, actions on the right, and a word after an action', () => {
  const keyRows = (rows: Row[]) => rows.filter(r => r.key.startsWith('keys'))
  const keys = (rows: Row[]) => keyRows(rows).flatMap(r => r.segments.flatMap(s => (s.press?.hotkey ? [s.press.hotkey] : [])))

  it('sits on one row when it fits: the moves at the left edge, the actions against the right', () => {
    const rows = paneRows(fullInput({ tab: 'hubs' }), 200)
    expect(keyRows(rows)).toHaveLength(1)
    const text = rawText(keyRows(rows)[0]!)
    expect(text).toMatch(/^ {2}j: ↓ {2}k: ↑ +o: open .* h: keys {2}$/)
    // A bar: its ground laid across the whole pane, padding included.
    expect(rowWidth(keyRows(rows)[0]!)).toBe(200)
    expect(keyRows(rows)[0]!.segments.every(s => s.bg === 'composerSidebarBackground')).toBe(true)
  })

  it('offers only what does something here, and never drops a key as it wraps', () => {
    for (const columns of WIDTHS) {
      expect(keys(paneRows(fullInput({ tab: 'hubs' }), columns)), `${columns}`).toEqual(['j', 'k', 'o', 'c', 'q', 'n', 's', 'f', 'h'])
      expect(keys(paneRows(fullInput({ tab: 'cycles' }), columns)), `${columns}`).not.toContain('n')
      for (const r of keyRows(paneRows(fullInput({ tab: 'hubs' }), columns))) expect(rowWidth(r), `${columns}`).toBeLessThanOrEqual(columns)
    }
    expect(keys(paneRows({ ...detailPane() }, 140))[0]).toBe('b')
  })

  it('says what an action did while its time lasts, and nothing once it is up', () => {
    const said = { text: '✓ copied StableId', tone: 'ok' as const, until: 2_000 }
    const at = (now: number) => paneInput(full(), brief(), FETCHED, IDLE, view({ tab: 'hubs' }), now, true, null, null, undefined, null, undefined, { feedback: said })
    expect(at(1_999).feedback).toEqual(said)
    expect(at(2_000).feedback).toBeNull()
    const rows = paneRows(at(0), 200)
    expect(rawText(keyRows(rows)[0]!)).toMatch(/k: ↑ {3}✓ copied StableId +o: open/)
    expect(keyRows(rows)[0]!.segments.find(s => s.text === '✓ copied StableId')?.color).toBe('success')
    // A failure in the error colour; past the width the word takes a row of its own above the keys.
    const failed = paneInput(full(), brief(), FETCHED, IDLE, view({ tab: 'hubs' }), 0, true, null, null, undefined, null, undefined, { feedback: { text: '✗ no editor', tone: 'alert', until: 1 } })
    const narrow = paneRows(failed, 60)
    expect(row(narrow, 'keys-said')!.segments[1]).toMatchObject({ text: '✗ no editor', color: 'error' })
    expect(textOf(paneRows(at(5_000), 200))).not.toContain('copied')
  })
})

describe('compact numbers in narrow columns', () => {
  it('says a count in at most five cells: as it is, then in thousands or millions', () => {
    expect([0, 7, 999, 1_000, 1_049, 9_950, 31_740, 99_949, 99_950, 317_000, 999_499, 999_500, 1_234_567, 52_000_000, 2_500_000_000, -31_740].map(compact)).toEqual([
      '0',
      '7',
      '999',
      '1k',
      '1k',
      '10k',
      '31.7k',
      '99.9k',
      '100k',
      '317k',
      '999k',
      '1M',
      '1.2M',
      '52M',
      '2.5G',
      '-31.7k',
    ])
  })
  it('narrow tables say figures compactly; wider ones, tiles and details say them whole', () => {
    const big = full({ hubs: [hub('StableId', 'Knossos\\Store\\StableId', 'class', 'core', 31_740, 0, 0)], hotspots: [] })
    expect(textOf(paneRows(fullInput({ tab: 'hubs' }, big), 60))).toMatch(/StableId .* 31\.7k/)
    expect(textOf(paneRows(fullInput({ tab: 'hubs' }, big), 100))).toContain('31,740')
    expect(textOf(paneRows(fullInput({ tab: 'overview' }, full({ summary: { ...full().summary!, components: 31_740 } })), 100))).toContain('31,740')
  })
})

describe('cycles as chains', () => {
  it('open with ↻, colour every hop by its boundary, and close back on themselves', () => {
    const cycle = { size: 3, more: 0, nodes: [
      { name: 'a', canonical: 'A', boundary: 'core' },
      { name: 'b', canonical: 'B', boundary: 'hooks' },
      { name: 'c', canonical: 'C', boundary: null },
    ] }
    const groups = chainGroups(cycle, 20)
    expect(groups.map(g => g.map(x => x.text).join(''))).toEqual(['↻', '■ a →', '■ b →', 'c', '→ ↻'])
    // The colour is on the swatch; every name stays in the text tone, one in no boundary without a swatch.
    expect(groups[1]![0]!.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(groups[1]![1]!.color).toBeUndefined()
    expect(groups[3]![0]).toEqual({ text: 'c' })
    expect(chainGroups({ ...cycle, more: 7 }, 20).at(-1)!.map(x => x.text).join('')).toBe('… +7 more')
  })
})
