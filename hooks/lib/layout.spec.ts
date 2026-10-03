import { describe, expect, it } from 'vitest'
import type { ComponentDetail, Dashboard, DetailState, KnossosView, PaneTab, TurnBrief } from '../../types'
import {
  CONTENT_MAX,
  SIDE_BY_SIDE,
  bar,
  detailInput,
  displayName,
  fit,
  hubList,
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
  summaryParts,
  tableSpec,
  wrapWords,
} from './layout'
import type { PaneInput, Row } from './layout'
import { fitStart, shortName, wrapGroups } from './rows'
import { cycleRows, cyclesInput, issueCount, issuesInput, issuesList, superscript } from './views'

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

  it('says the boundaries tab is coming, and walks nothing there or on cycles', () => {
    expect(textOf(paneRows(input({ tab: 'boundaries' }), 60))).toContain('coming next')
    for (const tab of ['boundaries', 'cycles'] as const) expect(listFor(input({ tab }))).toEqual([])
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
    largest_files: [
      { path: 'workers/typescript/src/scanner.js', language: 'javascript', lines: 4986 },
      { path: 'src/Discovery/ProjectDiscoverer.php', language: 'php', lines: 2835 },
    ],
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
  for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(Math.min(columns, CONTENT_MAX))
}

describe('the header summary', () => {
  it('counts components, declared boundaries and languages when knossos reports them', () => {
    expect(summaryParts(full(), 6)).toEqual(['7,878 components', '2 boundaries', '41 drifted', 'PHP JS RS'])
    expect(plainText(row(paneRows(fullInput(), 60), 'summary')!)).toBe('7,878 components · 2 boundaries · 41 drifted · PHP JS RS')
  })
  it('falls back to hubs, cycles and dead code from an older knossos', () => {
    expect(summaryParts(dash(), 6)).toEqual(['6 hubs', '2 cycles', '55 dead code', '41 drifted'])
  })
  it('counts every boundary, as a floor when cut, where none is declared', () => {
    const inferred = full({ boundaries: { items: [{ name: 'namespace:App', source: 'inferred', members: 3 }], truncated: true } })
    expect(summaryParts(inferred, 0)[1]).toBe('1+ boundaries')
  })
})

describe('the issues tab', () => {
  for (const columns of WIDTHS) {
    it(`fits ${columns} columns`, () => {
      widthsFit(paneRows(fullInput({ tab: 'issues' }), columns), columns)
      widthsFit(paneRows(fullInput({ tab: 'issues', showKeys: true, terminal: false }), columns), columns)
    })
  }
  it('lists violations, diagnostics, dead code and the largest files, each with its place', () => {
    const text = textOf(paneRows(fullInput({ tab: 'issues' }), 100))
    expect(text).toMatch(/POLICY VIOLATIONS +▲ 7/)
    expect(text).toMatch(/ArchitectureQueryService::fileMetrics → FactCollector +core +ArchitectureQueryService\.php:191/)
    expect(text).toContain('+6 more')
    expect(text).toMatch(/DIAGNOSTICS +1 error · 1 warning · 1 note/)
    expect(text).toMatch(/✖ TS2307 Cannot find module/)
    expect(text).toMatch(/scanner\.ts:14$/m)
    expect(text).toMatch(/DEAD CODE +55 · first 2/)
    expect(text).toMatch(/FactCollector::beforeTraverse +php-worker +FactCollector\.php:108/)
    expect(text).toMatch(/◇ helper/)
    expect(text).toMatch(/LARGEST FILES +lines/)
    expect(text).toMatch(/workers\/typescript\/src\/scanner\.js .*█+ +4986/)
  })
  it('gives way in order: names are cut, then the boundary goes, then the place', () => {
    const dead = (columns: number) => plainText(row(paneRows(fullInput({ tab: 'issues' }), columns), 'dead-0')!)
    expect(dead(90)).toMatch(/FactCollector::beforeTraverse +php-worker +FactCollector\.php:108$/)
    expect(dead(50)).toMatch(/FactCollector::be… php-worker …ollector\.php:108$/)
    expect(dead(40)).not.toContain('php-worker')
    expect(dead(40)).toMatch(/\.php:108$/)
    expect(dead(30)).not.toContain('.php')
    expect(dead(30)).toContain('FactCollector::')
  })
  it('walks violations then dead code, each row pressable by its index', () => {
    const input = fullInput({ tab: 'issues', selected: 1 })
    expect(listFor(input).map(i => i.canonical)).toEqual([
      'Knossos\\Query\\ArchitectureQueryService::fileMetrics',
      'KnossosPhpScanner\\FactCollector::beforeTraverse',
      'Knossos\\Support\\helper',
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
    expect(plainText(row(paneRows(fullInput(), 90), 'tabs')!)).toContain('5: Issues ⁹')
    expect(plainText(row(paneRows(fullInput(), 40), 'tabs')!)).toContain('5: Iss⁹')
    // Nothing to act on: no badge.
    expect(plainText(row(paneRows(input(), 90), 'tabs')!)).toMatch(/5: Issues$/)
  })
  it('says what it cannot know from an older knossos, and when no policy is declared', () => {
    const old = textOf(paneRows(input({ tab: 'issues' }), 60))
    expect(old).toMatch(/POLICY VIOLATIONS +not reported/)
    expect(old).toMatch(/DIAGNOSTICS +not reported/)
    const undeclared = full({ policy: { status: 'not_evaluated', total: 0, truncated: false, truncation_reasons: [], items: [] } })
    expect(textOf(paneRows(fullInput({ tab: 'issues' }, undeclared), 60))).toMatch(/POLICY VIOLATIONS +no policies declared/)
    expect(issuesList(issuesInput(undeclared))).toHaveLength(2)
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
  it('draws each cycle as a chain closing on itself, names coloured by boundary', () => {
    const rows = paneRows(fullInput({ tab: 'cycles' }), 100)
    const text = textOf(rows)
    expect(text).toMatch(/CYCLES +2 · largest first/)
    expect(text).toContain('cycle 1 · 5 members')
    expect(text).toContain('Index::_add_instances → Index::module_declarations → ')
    expect(text).toContain('Index::safe_file ↺')
    const chain = rows.filter(r => r.key.startsWith('chain-0')).flatMap(r => r.segments)
    const coloured = (name: string) => chain.find(s => s.text === name)?.color
    expect(coloured('Index::_add_instances')).toBeDefined()
    expect(coloured('Index::_add_instances')).toBe(coloured('Index::read_bounded'))
    expect(coloured('Index::_add_instances')).not.toBe(coloured('Index::safe_file'))
    // The legend names each colour once.
    expect(plainText(row(rows, 'cycles-legend')!)).toBe('   ■ python-worker  ■ core')
  })
  it('wraps a chain onto more rows as the pane narrows, keeping each name whole where it can', () => {
    const at = (columns: number) => paneRows(fullInput({ tab: 'cycles' }), columns).filter(r => r.key.startsWith('chain-0'))
    expect(at(100)).toHaveLength(2)
    expect(at(40).length).toBeGreaterThan(at(100).length)
    expect(at(40).map(plainText).join(' ')).toContain('Index::module_declarations →')
  })
  it('lists names alone from an older knossos, and says how many more a long cycle has', () => {
    const text = textOf(cycleRows(cyclesInput(full()), 60))
    expect(text).toContain('visit → walk → Scanner::scan → emit ↺')
    const long = full({ cycles: { count: 60, truncated: true, truncation_reasons: ['result_limit'], largest: [{ size: 50, members: [], nodes: [{ name: 'a', canonical_name: 'a', kind: 'function', boundary: null }], nodes_truncated: true }] } })
    const cut = textOf(cycleRows(cyclesInput(long), 60))
    expect(cut).toMatch(/CYCLES +60\+ · 1 shown · largest first/)
    expect(cut).toContain('a → … +49 more')
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
  it('heads with name, kind, boundary and place, then used by and uses side by side when wide', () => {
    const rows = paneRows(detailPane(), 100)
    expect(plainText(row(rows, 'detail-name')!)).toMatch(/^DashboardService +class · core$/)
    expect(plainText(row(rows, 'detail-place')!)).toBe('src/Query/DashboardService.php:28')
    expect(plainText(row(rows, 'detail-canonical')!)).toBe('Knossos\\Query\\DashboardService')
    expect(plainText(row(rows, 'side-0')!)).toMatch(/^USED BY 19 +edges +USES 2 +edges$/)
    expect(plainText(row(rows, 'side-1')!)).toMatch(/DashboardServiceTest::testDrift +tests +█+ +3 +BoundaryLabels +core +█+ +4$/)
    expect(textOf(rows)).toContain('+17 more')
    expect(textOf(rows)).toMatch(/ANNOTATIONS\n {3}note: Read-only: it never scans\./)
    expect(100).toBeGreaterThanOrEqual(SIDE_BY_SIDE)
  })
  it('stacks uses under used by when narrow', () => {
    const rows = paneRows(detailPane(), 60)
    expect(row(rows, 'side-0')).toBeUndefined()
    const text = textOf(rows)
    expect(text.indexOf('USED BY 19')).toBeLessThan(text.indexOf('USES 2'))
    expect(plainText(row(rows, 'uses-0')!)).toMatch(/BoundaryLabels +core +█+ +4$/)
  })
  it('makes every counterpart pressable, used by first, and offers back instead of the list keys', () => {
    const input = detailPane()
    expect(listFor(input).map(i => i.name)).toEqual(['DashboardServiceTest::testDrift', 'BriefCommand::answer', 'BoundaryLabels', 'ProjectFindings'])
    const rows = paneRows(input, 60)
    expect(row(rows, 'uses-1')!.segments.find(s => s.press)?.press?.id).toBe('rel:3')
    const keys = row(rows, 'keys')!.segments.flatMap(s => (s.press ? [s.press.hotkey] : []))
    expect(keys).toEqual(['b', 'h'])
    expect(row(rows, 'tabs')).toBeUndefined()
  })
  it('says it is looking, or what knossos said instead', () => {
    expect(textOf(paneRows(detailPane(null, { ...done(null), phase: 'loading' }), 60))).toContain('Inspecting DashboardService…')
    expect(textOf(paneRows(detailPane(null), 60))).toContain('No details for DashboardService: knossos said nothing.')
    expect(textOf(paneRows(detailPane({ ...detailAnswer(), status: 'not-found', component: null }), 60))).toContain('No component matched')
  })
  it('lists names without counts or bars from an older knossos', () => {
    const old = detailAnswer({ used_by: { count: 1, truncated: false, names: ['Kernel'] }, uses: { count: 0, truncated: false, names: [] } })
    const text = textOf(paneRows(detailPane(old), 60))
    expect(text).toMatch(/USED BY 1\n {3}Kernel/)
    expect(text).not.toMatch(/█/)
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
  it('names the sort, draws its bar, and offers f, s and x as keys', () => {
    const rows = paneRows(input({ tab: 'hubs', sort: 'out', filter: 'a' }), 90)
    expect(plainText(row(rows, 'hubs-head')!)).toMatch(/◆ hotspot only · by out$/)
    expect(plainText(row(rows, 'hub-0')!)).toMatch(/ProjectScanService::scan +core +█+ +119 +58 +0$/)
    const keys = rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))
    expect(keys).toEqual(['j', 'k', 'o', 'f', 's', 'x', 'h'])
  })
  it('wraps the keys rather than dropping one that does not fit', () => {
    const rows = paneRows(input({ tab: 'hubs', filter: 'a' }), 40)
    const keyRows = rows.filter(r => r.key.startsWith('keys'))
    expect(keyRows.length).toBeGreaterThan(1)
    expect(keyRows.flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))).toEqual(['j', 'k', 'o', 'f', 's', 'x', 'h'])
    widthsFit(rows, 40)
  })
})

describe('the overview health line', () => {
  it('shows the project policy and diagnostics when knossos reports them', () => {
    const rows = paneRows(fullInput(), 90)
    expect(plainText(row(rows, 'health-dead')!)).toMatch(/dead code +55 +policy ▲ 7 +diagnostics ▲ 2/)
    expect(row(rows, 'health-dead')!.segments.find(s => s.text === '▲ 2')?.color).toBe('yellow')
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
