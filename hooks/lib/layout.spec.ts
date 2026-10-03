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
  lastTurnOf,
  listFor,
  mergeRanked,
  needsRescan,
  paneInput,
  paneRows,
  paneStatus,
  rowWidth,
  tabRows,
  summaryParts,
  tableSpec,
  wrapWords,
} from './layout'
import type { PaneInput, Row } from './layout'
import { findRow, plainText, rawText } from './__tests__/plain-text'
import { cycleRows } from './__tests__/tabs'
import { fitStart, shortName, wrapGroups } from './rows'
import { cyclesInput, issueCount, issuesInput, issuesList, superscript } from './views'

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
    expect(items).toEqual([{ name: 'K', canonical: 'App\\K', kind: 'class', boundary: null, in: 0, out: 0, cross: 0, hotspotOnly: true, loc: null }])
  })
})

describe('tableSpec', () => {
  const names = ['ArchitectureQueryService', 'StableId']
  const boundaries = ['core', 'typescript-wo']
  const numbers = [3, 3, 5]

  it('gives way in order: bars shrink, names truncate, the boundary goes, then the bars', () => {
    const at = (columns: number) => tableSpec(columns, names, boundaries, numbers)
    const full = at(120)
    // Names take what the longest needs, bars stop at their share of the width, and the rest stays at the right edge.
    expect(full.bar).toBe(36)
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
    expect(at(90)).toMatch(/ArchitectureQueryService +core/)
    expect(at(90)).toContain('━')
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
    // One marker over the Overview's lists: the last turn's two files come first, then the most depended on.
    const rows = paneRows(input({ selected: 3 }), 60)
    expect(plainText(row(rows, 'top-1')!)).toMatch(/^› {2}ArchitectureQueryService/)
    expect(plainText(row(rows, 'top-0')!)).toMatch(/^ {3}StableId/)
    expect(plainText(row(paneRows(input({ selected: 1 }), 60), 'turn-1')!)).toMatch(/^› {2}register\.tsx/)
    const past = paneRows(input({ selected: 99 }), 60)
    const last = input().items.length - 1
    expect(plainText(row(past, `top-${last}`)!).startsWith('›')).toBe(true)
  })

  it('makes each listed name pressable by its row', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 60)
    expect(row(rows, 'hub-3')!.segments.find(s => s.press)?.press).toEqual({ id: 'row:3', label: 'ProjectScanService::scan' })
    expect(plainText(row(rows, 'hub-3')!)).toMatch(/^ ◆ ProjectScanService::scan/)
  })

  it('colours a boundary and its bar alike, its track faint, and draws no boundary neutral', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 90)
    const core = row(rows, 'hub-0')!.segments.filter(s => s.text.trim() === 'core' || s.text.includes('━'))
    expect(core).toHaveLength(2)
    expect(core[0]!.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(core[0]!.color).toBe(core[1]!.color)
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

  it('shows the last turn, health and the most depended on on the overview', () => {
    const text = textOf(paneRows(input(), 60))
    expect(text).toContain('Last turn')
    expect(text).toMatch(/2 files → 27 dependents · 1 test/)
    expect(text).toMatch(/TurnBriefService\.php +core +[━╸]+·* +21/)
    expect(text).toMatch(/cycles +2/)
    expect(text).toMatch(/max degree +161/)
    expect(text).toMatch(/dead code +55\n {3}policy +✓ 0\n/)
    expect(text).toContain('Most depended on')
    // The last turn's files are walked first, each a file row that opens as its detail.
    const list = listFor(input())
    // Every component, not a top five: the card shows as many as the height allows and the marker scrolls it.
    expect(list).toHaveLength(2 + input().items.length)
    expect(list.slice(0, 2).map(o => [o.canonical, o.file])).toEqual([
      ['src/Query/TurnBriefService.php', true],
      ['hooks/register.tsx', true],
    ])
    expect(row(paneRows(input(), 60), 'turn-0')!.segments.find(s => s.press)?.press?.id).toBe('row:0')
    expect(row(paneRows(input(), 60), 'top-0')!.segments.find(s => s.press)?.press?.id).toBe('row:2')
  })

  it('draws a trend only from five snapshots that move', () => {
    expect(textOf(paneRows(input(), 60))).not.toContain('trend')
    const moving = dash({ trend: [1, 2, 2, 3, 2].map((c, i) => ({ snapshot_id: `s${i}`, cycles: c, max_degree: 100 + i })) })
    const text = textOf(paneRows(input({}, moving), 60))
    expect(text).toContain('trend (5 scans)')
    expect(text).toContain('▁▅▅█▅')
  })

  it('names a policy violation in the error colour', () => {
    const rows = paneRows(input({}, dash(), brief({ policy: { status: 'evaluated', total: 3, violations: [], truncated: false } })), 60)
    const seg = row(rows, 'health-policy')!.segments.find(s => s.text.trim() === '▲ 3')
    expect(seg?.color).toBe('error')
  })

  it("labels a last-turn file with its own boundary, never its dependents'", () => {
    const own = brief({ impact: { 'bin/router.php': { path: 'bin/router.php', dependent_files: 30, boundaries: ['tests'], boundary: null } } })
    expect(lastTurnOf(own)?.impact.map(f => f.boundary)).toEqual([null])
    expect(lastTurnOf(brief())?.impact.map(f => [f.name, f.boundary])).toEqual([
      ['TurnBriefService.php', 'core'],
      ['register.tsx', 'module:hooks'],
    ])
  })

  it('leaves the last turn out when there is no fresh brief', () => {
    expect(textOf(paneRows(input({}, dash(), null), 60))).not.toContain('Last turn')
    expect(lastTurnOf(brief({ status: 'scan-failed' }))).toBeNull()
  })

  it('says when the knossos that answered sends no boundary map, and walks nothing there or on cycles', () => {
    expect(textOf(paneRows(input({ tab: 'boundaries' }), 60))).toContain('sends no boundary map')
    for (const tab of ['boundaries', 'cycles'] as const) expect(listFor(input({ tab }))).toEqual([])
  })

  it('shows the key help on request', () => {
    const rows = paneRows(input({ showKeys: true }), 60)
    expect(textOf(rows)).toMatch(/1–6 +switch tabs \(or click one\)/)
    expect(textOf(rows)).toMatch(/\nq +ask Claude about the marked row/)
    // One key a line, the key in the accent; a long action wraps under its own column.
    expect(row(rows, 'help-0')!.segments[0]).toMatchObject({ text: '1–6    ', color: 'suggestion' })
    expect(rows.filter(r => r.key.startsWith('help-')).every(r => r.segments[0]!.text.length === 7)).toBe(true)
    expect(textOf(paneRows(input(), 60))).not.toContain('switch tabs')
  })
})

describe('tabRows', () => {
  it('names every tab in full when it fits and marks the active one on the terminal', () => {
    const [strip, rule] = tabRows('hubs', 80, true)
    expect(plainText(strip!)).toBe('1: Overview  2: Hubs  3: Boundaries  4: Cycles  5: Issues  6: Changes')
    expect(rule!.segments.find(s => s.text.includes('━'))?.text).toBe('━━━━━━━')
    expect(rowWidth(rule!)).toBe(80)
  })
  it('names the active tab in full and the others by their digit when all six names do not fit', () => {
    expect(plainText(tabRows('overview', 60, true)[0]!)).toBe('1: Overview  2  3  4  5  6')
    expect(plainText(tabRows('boundaries', 60, true, { issues: '⁴', changes: '⁸' })[0]!)).toBe('1  2  3: Boundaries  4  5⁴  6⁸')
    expect(plainText(tabRows('changes', 40, true, { changes: '¹²' })[0]!)).toBe('1  2  3  4  5  6: Changes ¹²')
    // Never an abbreviation: a pane too narrow even for that keeps the digits alone.
    expect(plainText(tabRows('boundaries', 20, true)[0]!)).toBe('1 2 3 4 5 6')
    for (const columns of [20, 40, 60, 72]) expect(plainText(tabRows('issues', columns, true)[0]!)).not.toMatch(/\b(Over|Bound|Cyc|Iss|Chg)\b/)
    const rule = tabRows('boundaries', 60, true)[1]!
    expect(rule.segments.find(s => s.text.includes('━'))?.text).toBe('━━━━━━━━━━━━━')
  })
  it('keeps every digit a hotkey: a digit-only tab carries it on a hidden twin that draws nothing', () => {
    const segments = tabRows('overview', 60, true)[0]!.segments
    const twin = segments.find(s => s.press?.id === 'tabkey:hubs')
    expect(twin).toMatchObject({ text: '', hidden: true, press: { hotkey: '2' } })
    // The twin comes before the digit it stands for, so a forward walk lands on the visible one.
    expect(segments.indexOf(twin!)).toBeLessThan(segments.findIndex(s => s.press?.id === 'tab:hubs'))
    expect(segments.find(s => s.press?.id === 'tab:hubs')).toMatchObject({ text: '2', dim: true, press: { label: '2' } })
    expect(segments.find(s => s.press?.id === 'tab:hubs')?.press?.hotkey).toBeUndefined()
    // Where all six fit, nothing hides.
    expect(tabRows('overview', 80, true)[0]!.segments.some(s => s.hidden)).toBe(false)
  })
  it('draws no rule where tabs are native buttons', () => {
    expect(tabRows('overview', 60, false)).toHaveLength(1)
  })
  it('gives every tab its digit as hotkey', () => {
    for (const columns of [60, 80]) {
      const presses = tabRows('overview', columns, true)[0]!.segments.flatMap(s => (s.press?.hotkey ? [s.press] : []))
      expect(presses.map(p => `${p.hotkey}:${p.id.replace('tabkey:', 'tab:')}`)).toEqual(['1:tab:overview', '2:tab:hubs', '3:tab:boundaries', '4:tab:cycles', '5:tab:issues', '6:tab:changes'])
    }
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
  it('keeps saying how old the figures on show are while a scan runs', () => {
    const scanning = { phase: 'scanning', reason: null } as const
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: false }, scanning, 61_000)).toEqual({ tone: 'warn', text: 'scanning… · stale · 11h' })
    expect(paneStatus(dash(), { fetchedAt: 1_000, failed: true }, scanning, 61_000).text).toBe('scanning… · refresh failed · 11h')
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
  for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
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
  it('lists violations, diagnostics, dead code and the largest files, each with its place', () => {
    const text = textOf(paneRows(fullInput({ tab: 'issues' }), 100))
    expect(text).toMatch(/Policy violations +▲ 7/)
    expect(text).toMatch(/ArchitectureQueryService::fileMetrics → FactCollector +core +ArchitectureQueryService\.php:191/)
    expect(text).toContain('+6 not listed')
    expect(text).toMatch(/Diagnostics +1 error · 1 warning · 1 note/)
    expect(text).toMatch(/✖ TS2307 Cannot find module/)
    expect(text).toMatch(/scanner\.ts:14$/m)
    expect(text).toMatch(/Dead code +55 · first 2/)
    expect(text).toMatch(/FactCollector::beforeTraverse +php-worker +FactCollector\.php:108/)
    expect(text).toMatch(/◇ helper/)
    expect(text).toMatch(/Largest files +lines/)
    expect(text).toMatch(/workers\/typescript\/src\/scanner\.js .*━+ +4,986/)
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
    expect(plainText(row(paneRows(fullInput(), 40), 'tabs')!)).toContain('5⁹')
    // Nothing to act on: no badge.
    expect(plainText(row(paneRows(input(), 90), 'tabs')!)).toMatch(/5: Issues {2}6: Changes$/)
  })
  it('says what it cannot know from an older knossos, and when no policy is declared', () => {
    const old = textOf(paneRows(input({ tab: 'issues' }), 60))
    expect(old).toMatch(/Policy violations +not reported/)
    expect(old).toMatch(/Diagnostics +not reported/)
    const undeclared = full({ policy: { status: 'not_evaluated', total: 0, truncated: false, truncation_reasons: [], items: [] } })
    expect(textOf(paneRows(fullInput({ tab: 'issues' }, undeclared), 60))).toMatch(/Policy violations +no policies declared/)
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
  it('draws each cycle as a chain closing on itself, under its boundary, members outside it coloured', () => {
    const rows = paneRows(fullInput({ tab: 'cycles' }), 100)
    const text = textOf(rows)
    expect(text).toMatch(/Cycles +2 · largest first/)
    expect(text).toContain('cycle 1 · 5 members')
    expect(text).toContain('Index::_add_instances → Index::module_declarations → ')
    expect(text).toContain('Index::safe_file ↺')
    const chain = rows.filter(r => r.key.startsWith('chain-0')).flatMap(r => r.segments)
    const coloured = (name: string) => chain.find(s => s.text === name)?.color
    // Most members are python-worker: the cycle's line names it, in its colour, and its members stay neutral.
    expect(plainText(row(rows, 'cycle-0')!)).toBe('›  cycle 1 · 5 members · python-worker')
    const home = row(rows, 'cycle-0')!.segments.find(s => s.text === 'python-worker')
    expect(home?.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(coloured('Index::_add_instances')).toBeUndefined()
    expect(coloured('Index::read_bounded')).toBeUndefined()
    // The one member in core is where the cycle crosses: drawn in core's colour.
    expect(coloured('Index::safe_file')).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(coloured('Index::safe_file')).not.toBe(home?.color)
    // The legend names only the colours drawn on members.
    expect(plainText(row(rows, 'cycles-legend')!)).toBe('   ■ core')
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
    expect(cut).toMatch(/Cycles +60\+ · 1 shown · largest first/)
    expect(cut).toContain('a → … +49 more')
  })
  it('walks the cycles: the marker on a cycle, o opens the member where it crosses, c copies its chain, q asks how to break it', () => {
    const input = fullInput({ tab: 'cycles', selected: 1 })
    const list = listFor(input)
    expect(list.map(c => c.name)).toEqual(['cycle 1', 'cycle 2'])
    const rows = paneRows(input, 100)
    expect(plainText(row(rows, 'cycle-1')!).startsWith('›')).toBe(true)
    expect(plainText(row(rows, 'cycle-0')!).startsWith('›')).toBe(false)
    expect(row(rows, 'cycle-0')!.segments.find(s => s.press)?.press).toEqual({ id: 'row:0', label: 'cycle 1' })
    const first = subjectOf({ ...input, selected: 0 })!
    // The member outside the cycle's own boundary is where it crosses: that one opens.
    expect(first.canonical).toBe(list[0]!.canonical)
    expect(first.canonical).toContain('safe_file')
    expect(first.copy).toMatch(/_add_instances → .* → .*_add_instances$/)
    expect(first.ask).toContain('how could I break this dependency cycle')
    expect(first.ask).toContain(first.copy)
    const keys = row(rows, 'keys')!.segments.flatMap(s => (s.press ? [s.press.hotkey] : []))
    expect(keys).toEqual(expect.arrayContaining(['j', 'k', 'o', 'c', 'q']))
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
    const rows = paneRows(detailPane(), 140)
    expect(plainText(row(rows, 'detail-head')!)).toMatch(/^DashboardService +class · core$/)
    expect(plainText(row(rows, 'detail-place')!)).toBe('src/Query/DashboardService.php:28')
    expect(plainText(row(rows, 'detail-canonical')!)).toBe('Knossos\\Query\\DashboardService')
    // One row holds both cards' top edges, and then a counterpart of each.
    expect(rows.find(r => r.key === 'used-head|uses-head')).toBeDefined()
    const pair = rows.find(r => r.key === 'used-0|uses-0')!
    expect(rawText(pair)).toMatch(/DashboardServiceTest::testDrift +tests +[━╸]+·* +3 +│ +│ +BoundaryLabels +core +[━╸]+·* +4 +│$/)
    expect(textOf(rows)).toContain('+17 not listed')
    expect(textOf(rows)).toMatch(/Annotations\n {3}note: Read-only: it never scans\./)
  })
  it('stacks uses under used by below the wide tier', () => {
    const rows = paneRows(detailPane(), 100)
    expect(rows.some(r => r.key.includes('|'))).toBe(false)
    const text = textOf(rows)
    expect(text.indexOf('Used by · 19')).toBeLessThan(text.indexOf('Uses · 2'))
    expect(plainText(row(rows, 'uses-0')!)).toMatch(/BoundaryLabels +core +[━╸]+·* +4$/)
  })
  it('makes every counterpart pressable, used by first, marks the one j and k reach, and offers back beside the list keys', () => {
    const input = detailPane()
    expect(listFor(input).map(i => i.name)).toEqual(['DashboardServiceTest::testDrift', 'BriefCommand::answer', 'BoundaryLabels', 'ProjectFindings'])
    const rows = paneRows(input, 60)
    expect(row(rows, 'uses-1')!.segments.find(s => s.press)?.press?.id).toBe('rel:3')
    const keys = row(rows, 'keys')!.segments.flatMap(s => (s.press ? [s.press.hotkey] : []))
    expect(keys.slice(0, 4)).toEqual(['b', 'j', 'k', 'o'])
    expect(keys).toContain('q')
    // The marker walks used by, then uses: the third counterpart is the first it uses.
    expect(plainText(row(rows, 'used-0')!).startsWith('›')).toBe(true)
    const third = paneRows({ ...input, selected: 2 }, 60)
    expect(plainText(row(third, 'used-0')!).startsWith('›')).toBe(false)
    expect(plainText(row(third, 'uses-0')!).startsWith('›')).toBe(true)
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
    expect(text).toMatch(/Used by · 1\n› {2}Kernel/)
    expect(text).not.toMatch(/█/)
  })
})

describe('tables packed to the left', () => {
  // The longest name meets its boundary with one space, whatever the width; width the table does not need stays at the right edge.
  it('keeps every boundary beside its name, on every tab, at every width', () => {
    for (const columns of [...WIDTHS, 100]) {
      const hubs = paneRows(fullInput({ tab: 'hubs' }), columns)
      const top = paneRows(fullInput({ tab: 'overview' }), columns)
      const issues = paneRows(fullInput({ tab: 'issues' }), columns)
      if (columns >= 60) {
        expect(plainText(row(hubs, 'hub-1')!), `hubs ${columns}`).toMatch(/ArchitectureQueryService core +━/)
        expect(plainText(row(top, 'top-1')!), `top ${columns}`).toMatch(/ArchitectureQueryService core +━/)
        expect(plainText(row(top, 'turn-0')!), `turn ${columns}`).toMatch(/TurnBriefService\.php core +━/)
      }
      if (columns >= 90) expect(plainText(row(issues, 'dead-0')!), `dead ${columns}`).toMatch(/FactCollector::beforeTraverse php-worker +FactCollector\.php:108$/)
      for (const r of [...hubs, ...top, ...issues]) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })
  it("sets the title, the sort and the note into the card's top edge, which spans the card like its rows", () => {
    const rows = paneRows(fullInput({ tab: 'hubs' }), 100)
    const head = row(rows, 'hubs-head')!
    expect(plainText(head)).toMatch(/^Hubs and hotspots · sorted by in +◆ hotspot only$/)
    expect(rowWidth(head)).toBe(100)
    expect(rowWidth(row(rows, 'hub-0')!)).toBe(100)
    const top = paneRows(fullInput({ tab: 'overview' }), 100)
    expect(rowWidth(row(top, 'top-head')!)).toBe(rowWidth(row(top, 'top-0')!))
  })
  it('makes every place on the Issues tab a link to its file and line', () => {
    const rows = paneRows(fullInput({ tab: 'issues' }), 90)
    expect(row(rows, 'dead-0')!.segments.find(seg => seg.link)?.link).toEqual({ path: '/work/Knossos-MCP/workers/php/src/FactCollector.php', line: 108 })
    expect(row(rows, 'large-0')!.segments.find(seg => seg.link)?.link?.line).toBeNull()
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
    expect(shown(paneRows(input({ tab: 'hubs' }, many), 100, 200), 'hub')).toBe(40)
  })

  it('scrolls a list to the marker: the marked hub is drawn, with how many are above it', () => {
    const rows = paneRows(input({ tab: 'hubs', selected: 30 }, many), 100, 24)
    expect(plainText(row(rows, 'hub-30')!)).toMatch(/^›/)
    expect(textOf(rows)).toMatch(/\d+ above ↑ · \d+ more ↓/)
  })

  it('switches layout at the tier thresholds: one column to 130, two from 131', () => {
    const grid = (columns: number) => paneRows(input({ tab: 'overview' }, many), columns, 40).some(r => r.key.includes('|'))
    expect([60, 79, 80, 130].map(grid)).toEqual([false, false, false, false])
    expect([131, 140, 200].map(grid)).toEqual([true, true, true])
    // Medium tables add out, cross and the file to the in-degree a narrow pane shows.
    expect(plainText(row(paneRows(input({}, many), 100), 'top-head')!)).not.toContain(' in')
    expect(textOf(paneRows(input({}, many), 100))).toMatch(/in +out +cross/)
    expect(textOf(paneRows(input({}, many), 60))).not.toMatch(/in +out +cross/)
  })

  it('puts the most depended on and a boundary map in the right half of a wide Overview', () => {
    const matrix = { boundaries: ['core', 'tests'], members: [10, 5], boundaries_truncated: false, cells: [[4, 0], [3, 2]], forbidden: [], edges: 9, truncated: false, truncation_reasons: [] }
    const rows = paneRows(input({ tab: 'overview' }, dash({ boundary_matrix: matrix })), 140, 60)
    const [left] = [Math.floor((140 - 2) / 2)]
    const head = rows.find(r => r.key.endsWith('|top-head'))!
    expect(plainText({ key: 'x', segments: head.segments.slice(head.split) })).toMatch(/^Most depended on/)
    expect(rows.some(r => r.key.endsWith('|map-head'))).toBe(true)
    expect(rowWidth({ key: 'x', segments: head.segments.slice(0, head.split) })).toBe(left + 2)
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
    expect(plainText(row(rows, 'hubs-head')!)).toMatch(/^Hubs and hotspots · sorted by out +◆ hotspot only$/)
    expect(plainText(row(rows, 'hub-0')!)).toMatch(/ProjectScanService::scan +core +[━╸]+·* +119 +58 +0$/)
    const keys = rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))
    expect(keys).toEqual(['j', 'k', 'o', 'c', 'q', 'f', 's', 'x', 'h'])
  })
  it('wraps the keys rather than dropping one that does not fit', () => {
    const rows = paneRows(input({ tab: 'hubs', filter: 'a' }), 40)
    const keyRows = rows.filter(r => r.key.startsWith('keys'))
    expect(keyRows.length).toBeGreaterThan(1)
    expect(keyRows.flatMap(r => r.segments.flatMap(s => (s.press ? [s.press.hotkey] : [])))).toEqual(['j', 'k', 'o', 'c', 'q', 'f', 's', 'x', 'h'])
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

describe('the overview health line', () => {
  it('shows the project policy and diagnostics when knossos reports them', () => {
    const rows = paneRows(fullInput(), 90)
    expect(plainText(row(rows, 'health-dead')!)).toMatch(/^ {3}dead code +55$/)
    expect(plainText(row(rows, 'health-policy')!)).toMatch(/^ {3}policy +▲ 7$/)
    expect(plainText(row(rows, 'health-diagnostics')!)).toMatch(/^ {3}diagnostics +▲ 2$/)
    expect(row(rows, 'health-diagnostics')!.segments.find(s => s.text.trim() === '▲ 2')?.color).toBe('warning')
    // One column: every figure ends where the others end.
    const ends = ['health-cycles', 'health-degree', 'health-dead', 'health-policy', 'health-diagnostics'].map(k => plainText(row(rows, k)!).trimEnd().length)
    expect(new Set(ends).size).toBe(1)
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
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'live' })).toEqual({ tone: 'ok', text: 'live' })
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'following' })).toEqual({ tone: 'ok', text: 'live · watched by another session' })
  })
  it("says so plainly when the session it follows stopped answering", () => {
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'following', stale: true })).toEqual({ tone: 'warn', text: "fresh · 11s · another session's watcher is stuck" })
  })
  it('says scanning while the watcher scans, and offers no rescan of its own then', () => {
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'scanning' })).toEqual({ tone: 'warn', text: 'scanning… · fresh · 11s' })
    const drifted = dash({ freshness: { state: 'stale', age_seconds: 5, drift_files: 3 } })
    expect(paneInput(drifted, null, FETCHED, IDLE, view(), 0, true, null, null, undefined, null, { phase: 'scanning' }).canRescan).toBe(false)
    expect(paneInput(drifted, null, FETCHED, IDLE, view(), 0, true, null, null, undefined, null, { phase: 'live' }).canRescan).toBe(true)
  })
  it('keeps the old states when the graph is not fresh, when starting, or off', () => {
    expect(paneStatus(dash(), FETCHED, IDLE, 0, { phase: 'live' }).text).toBe('stale · 11h')
    expect(paneStatus(fresh, FETCHED, IDLE, 6_000, { phase: 'starting' }).text).toBe('fresh · 11s')
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
      const top = paneRows(input({}, allCore), columns)
      // The narrowest top edge has no room for the note: the title stays whole.
      if (columns >= 60) expect(plainText(row(top, 'top-head')!)).toContain('all in core')
    }
  })
  it('draws a repeat dim, so the column reads by where the boundary changes', () => {
    const rows = paneRows(input({ tab: 'hubs' }), 120)
    const label = (key: string) => row(rows, key)!.segments.find(s => s.text.trim() === 'core')
    // StableId (core), ArchitectureQueryService (core): the second is the repeat.
    expect(label('hub-0')?.color).toMatch(/_FOR_SUBAGENTS_ONLY$/)
    expect(label('hub-1')).toMatchObject({ dim: true })
    expect(label('hub-1')?.color).toBeUndefined()
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
