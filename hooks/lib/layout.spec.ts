import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, PaneTab, TurnBrief } from '../../types'
import {
  CONTENT_MAX,
  bar,
  displayName,
  fit,
  lastTurnOf,
  listFor,
  mergeRanked,
  needsRescan,
  paneInput,
  paneRows,
  paneStatus,
  plainText,
  rowWidth,
  tabRows,
  tableSpec,
  wrapWords,
} from './layout'
import type { PaneInput, Row } from './layout'

const WIDTHS = [40, 60, 90, 120] as const
const TABS: PaneTab[] = ['overview', 'hubs', 'boundaries', 'cycles', 'issues']

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
    'hooks/register.tsx': { path: 'hooks/register.tsx', dependent_files: 6, boundaries: ['module:hooks'] },
    'src/Query/TurnBriefService.php': { path: 'src/Query/TurnBriefService.php', dependent_files: 21, boundaries: ['core'] },
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
  ...over,
})

const IDLE = { phase: 'idle', reason: null } as const
const FETCHED = { fetchedAt: 0, failed: false }

const input = (over: Partial<PaneInput> = {}, d = dash(), b: TurnBrief | null = brief()): PaneInput => ({
  ...paneInput(d, b, FETCHED, IDLE, view(), 0, true),
  ...over,
})

const textOf = (rows: Row[]) => rows.map(plainText).join('\n')
const row = (rows: Row[], key: string) => rows.find(r => r.key === key)

describe('fit and bar', () => {
  it('cuts with an ellipsis only when it has to', () => {
    expect(fit('StableId', 8)).toBe('StableId')
    expect(fit('StableId', 5)).toBe('Stab…')
    expect(fit('StableId', 0)).toBe('')
  })
  it('draws a bar in eighth blocks scaled to the maximum', () => {
    expect(bar(10, 10, 4)).toBe('████')
    expect(bar(5, 10, 4)).toBe('██')
    expect(bar(1, 16, 2)).toBe('▏')
    expect(bar(3, 16, 2)).toBe('▍')
  })
  it('gives any non-zero value a sliver and zero nothing', () => {
    expect(bar(1, 100_000, 10)).toBe('▏')
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
    expect(items).toEqual([{ name: 'K', canonical: 'App\\K', kind: 'class', boundary: null, in: 0, out: 0, cross: 0, hotspotOnly: true }])
  })
})

describe('tableSpec', () => {
  const names = ['ArchitectureQueryService', 'StableId']
  const boundaries = ['core', 'typescript-wo']
  const numbers = [3, 3, 5]

  it('gives way in order: bars shrink, names truncate, the boundary goes, then the bars', () => {
    const at = (columns: number) => tableSpec(columns, names, boundaries, numbers)
    const full = at(120)
    expect(full.name).toBe(24)
    expect(full.boundary).toBe(12)
    const shrinking = at(70)
    expect(shrinking.name).toBe(24)
    expect(shrinking.bar).toBeLessThan(full.bar)
    const truncating = at(55)
    expect(truncating.bar).toBe(4)
    expect(truncating.boundary).toBe(12)
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
            for (const r of rows) expect(rowWidth(r), `${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(Math.min(columns, CONTENT_MAX))
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

  it('keeps every row of a wide pane to the content width', () => {
    expect(Math.max(...paneRows(input(), 120).map(rowWidth))).toBe(CONTENT_MAX)
  })

  it('degrades the hubs table as the pane narrows', () => {
    const at = (columns: number) => textOf(paneRows(input({ tab: 'hubs' }), columns))
    // Wide: full names, boundary labels and bars.
    expect(at(90)).toContain('ArchitectureQueryService core')
    expect(at(90)).toContain('█')
    // Narrow: names cut before anything else goes; the numbers stay.
    expect(at(40)).toContain('…')
    expect(at(40)).toContain('525')
  })

  it('draws the header with the project, the status and the rescan action', () => {
    const rows = paneRows(input(), 60)
    expect(plainText(row(rows, 'title')!)).toMatch(/^Knossos-MCP +● stale · 11h {2}r: rescan$/)
    expect(plainText(row(rows, 'summary')!)).toBe('6 hubs · 2 cycles · 55 dead code · 41 drifted')
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
    const rows = paneRows(input({ selected: 1 }), 60)
    expect(plainText(row(rows, 'top-1')!)).toMatch(/^› {2}ArchitectureQueryService/)
    expect(plainText(row(rows, 'top-0')!)).toMatch(/^ {3}StableId/)
    const past = paneRows(input({ selected: 99 }), 60)
    expect(plainText(row(past, 'top-4')!).startsWith('›')).toBe(true)
  })

  it('makes each listed name pressable by its row', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 60)
    expect(row(rows, 'hub-3')!.segments.find(s => s.press)?.press).toEqual({ id: 'row:3', label: 'ProjectScanService::scan' })
    expect(plainText(row(rows, 'hub-3')!)).toMatch(/^ ◆ ProjectScanService::scan/)
  })

  it('colours a boundary and its bar alike, and leaves no boundary dim', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 90)
    const core = row(rows, 'hub-0')!.segments.filter(s => s.text.trim() === 'core' || s.text.includes('█'))
    expect(core).toHaveLength(2)
    expect(core[0]!.color).toBeDefined()
    expect(core[0]!.color).toBe(core[1]!.color)
    const none = row(rows, 'hub-5')!.segments.find(s => /[▏▎▍▌▋▊▉█]/.test(s.text))
    expect(none?.dim).toBe(true)
  })

  it('shows the last turn, health and the top five on the overview', () => {
    const text = textOf(paneRows(input(), 60))
    expect(text).toContain('LAST TURN')
    expect(text).toMatch(/2 files → 27 deps · 1 test/)
    expect(text).toMatch(/TurnBriefService\.php +core +█+ +21/)
    expect(text).toMatch(/cycles +2/)
    expect(text).toMatch(/max degree +161/)
    expect(text).toMatch(/dead code +55 +policy ✓ 0/)
    expect(text).toContain('MOST DEPENDED ON')
    expect(listFor(input())).toHaveLength(5)
  })

  it('draws a trend only from five snapshots that move', () => {
    expect(textOf(paneRows(input(), 60))).not.toContain('trend')
    const moving = dash({ trend: [1, 2, 2, 3, 2].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 100 + i })) })
    const text = textOf(paneRows(input({}, moving), 60))
    expect(text).toContain('trend (5 scans)')
    expect(text).toContain('▁▅▅█▅')
  })

  it('names a policy violation in red', () => {
    const rows = paneRows(input({}, dash(), brief({ policy: { status: 'evaluated', total: 3, violations: [], truncated: false } })), 60)
    const seg = row(rows, 'health-dead')!.segments.find(s => s.text === '▲ 3')
    expect(seg?.color).toBe('red')
  })

  it('leaves the last turn out when there is no fresh brief', () => {
    expect(textOf(paneRows(input({}, dash(), null), 60))).not.toContain('LAST TURN')
    expect(lastTurnOf(brief({ status: 'scan-failed' }))).toBeNull()
  })

  it('says the later tabs are coming', () => {
    for (const tab of ['boundaries', 'cycles', 'issues'] as const) {
      expect(textOf(paneRows(input({ tab }), 60))).toContain('coming next')
      expect(listFor({ tab, items: input().items })).toEqual([])
    }
  })

  it('shows the key help on request', () => {
    expect(textOf(paneRows(input({ showKeys: true }), 60))).toContain('1–5 or a click switch tabs')
    expect(textOf(paneRows(input(), 60))).not.toContain('switch tabs')
  })
})

describe('tabRows', () => {
  it('names every tab in full when it fits and marks the active one on the terminal', () => {
    const [strip, rule] = tabRows('hubs', 60, true)
    expect(plainText(strip!)).toBe('1: Overview  2: Hubs  3: Boundaries  4: Cycles  5: Issues')
    expect(rule!.segments.find(s => s.text.includes('━'))?.text).toBe('━━━━━━━')
    expect(rowWidth(rule!)).toBe(60)
  })
  it('shortens labels, then gaps, then keeps the initials', () => {
    expect(plainText(tabRows('overview', 50, true)[0]!)).toBe('1: Over  2: Hubs  3: Bound  4: Cyc  5: Iss')
    expect(plainText(tabRows('overview', 40, true)[0]!)).toBe('1: Over 2: Hubs 3: Bound 4: Cyc 5: Iss')
    expect(plainText(tabRows('overview', 30, true)[0]!)).toBe('1: O 2: H 3: B 4: C 5: I')
  })
  it('draws no rule where tabs are native buttons', () => {
    expect(tabRows('overview', 60, false)).toHaveLength(1)
  })
  it('gives every tab its digit as hotkey', () => {
    const presses = tabRows('overview', 60, true)[0]!.segments.flatMap(s => (s.press ? [s.press] : []))
    expect(presses.map(p => `${p.hotkey}:${p.id}`)).toEqual(['1:tab:overview', '2:tab:hubs', '3:tab:boundaries', '4:tab:cycles', '5:tab:issues'])
  })
})

describe('paneStatus and needsRescan', () => {
  it('reads the snapshot state and an age that keeps counting', () => {
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: false }, IDLE, 61_000)).toEqual({ tone: 'warn', text: 'stale · 11h' })
    const fresh = dash({ freshness: { state: 'fresh', age_seconds: 5, drift_files: 0 } })
    expect(paneStatus(fresh, { fetchedAt: 1_000, failed: false }, IDLE, 6_000)).toEqual({ tone: 'ok', text: 'fresh · 10s' })
  })
  it('puts a failed refresh or rescan in red', () => {
    expect(paneStatus(dash(), { fetchedAt: 0, failed: true }, IDLE, 0).tone).toBe('alert')
    expect(paneStatus(dash(), FETCHED, { phase: 'failed', reason: 'not-allowed' }, 0)).toEqual({ tone: 'alert', text: 'rescan failed: not-allowed' })
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
