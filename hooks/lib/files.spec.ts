import { describe, expect, it } from 'vitest'
import type { Dashboard, DetailState, FileDetail, KnossosView, SessionChanges, TurnBrief } from '../../types'
import { accumulate, editTarget, fileDetailInput, listFor, NO_CHANGES, paneInput, paneRows, rowWidth, subjectOf } from './layout'
import { findRow, plainText } from './__tests__/plain-text'
import type { PaneInput, Row } from './layout'
import { huesOf } from './palette'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const ROOT = '/work/app'

const dash = (over: Partial<Dashboard> = {}): Dashboard => ({
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: {
    state: 'stale',
    age_seconds: 600,
    drift_files: 23,
    drifted: [
      { path: 'src/Http/Router.php', change: 'changed', boundary: 'Http' },
      { path: 'src/Http/New.php', change: 'added', boundary: null },
      { path: 'src/Core/Gone.php', change: 'deleted', boundary: 'Core' },
    ],
    drifted_truncated: true,
  },
  hubs: [{ name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 }],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [],
  fan_in_truncated: false,
  summary: { components: 1234, kinds: [], kinds_truncated: false, files: 80, languages: [{ language: 'php', files: 80 }], languages_truncated: false },
  boundaries: { items: [{ name: 'Http', source: 'explicit', members: 40 }, { name: 'Core', source: 'explicit', members: 30 }], truncated: false },
  ...over,
})

const brief = (over: Partial<TurnBrief> = {}): TurnBrief => ({
  status: 'ok',
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  scanned_at: 0,
  scan_ms: 5,
  reason: null,
  roots_file: null,
  refused_root: null,
  path: ROOT,
  changed_files: ['src/Http/Router.php'],
  added_files: [],
  deleted_files: [],
  impact: { 'src/Http/Router.php': { path: 'src/Http/Router.php', dependent_files: 41, boundaries: ['Core'], boundary: 'Http' } },
  tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }],
  policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
  ...over,
})

const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'overview', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })

/** What `file-detail --json` prints for src/Http/Router.php. */
const answer = (over: Partial<NonNullable<FileDetail['file']>> = {}): FileDetail => ({
  status: 'ok',
  path: `${ROOT}/src/Http/Router.php`,
  project_id: 'p1',
  snapshot_id: 's1',
  file: {
    path: 'src/Http/Router.php',
    language: 'php',
    lines: 240,
    boundary: 'Http',
    dependents: {
      count: 14,
      truncated: true,
      boundaries: ['tests', 'Core', 'Http'],
      items: [
        { path: 'src/Core/Kernel.php', edges: 6, boundary: 'Core' },
        { path: 'tests/Http/RouterTest.php', edges: 3, boundary: 'tests' },
      ],
    },
    components: {
      count: 3,
      truncated: true,
      items: [
        { name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', line: 9, boundary: 'Http', used_by: 12 },
        { name: 'dispatch', canonical_name: 'App\\Http\\Router::dispatch', kind: 'method', line: 30, boundary: 'Http', used_by: 4 },
      ],
    },
    ...over,
  },
})

const SHOWN = { name: 'src/Http/Router.php', label: 'src/Http/Router.php', file: true as const }
const done = (fileDetail: FileDetail | null): DetailState => ({ snapshot_id: 's1', name: SHOWN.name, file: true, detail: null, fileDetail, phase: 'done' })

const pane = (over: { view?: Partial<KnossosView>; d?: Dashboard; turn?: TurnBrief | null; state?: DetailState | null; session?: SessionChanges } = {}): PaneInput => {
  const d = over.d ?? dash()
  const v = view(over.view)
  const shown = v.inspect === null ? null : fileDetailInput(v.inspect, over.state ?? null, d.project_root, huesOf(d))
  return paneInput(d, over.turn === undefined ? brief() : over.turn, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, shown, null, over.session ?? NO_CHANGES)
}

const textOf = (rows: Row[]) => rows.map(plainText).join('\n')
const row = findRow
const keysOf = (rows: Row[]) => rows.filter(r => r.key.startsWith('keys')).flatMap(r => r.segments.filter(s => s.press).map(s => s.press!.hotkey ?? s.press!.id))

describe('a file detail', () => {
  const shown = pane({ view: { inspect: SHOWN }, state: done(answer()) })

  it('names who depends on the file, with counts and boundaries, and what it declares', () => {
    const rows = paneRows(shown, 90)
    const text = textOf(rows)
    expect(plainText(row(rows, 'detail-head')!)).toMatch(/^Router\.php +Http$/)
    expect(plainText(row(rows, 'detail-place')!)).toBe('src/Http/Router.php · PHP · 240 lines')
    expect(row(rows, 'detail-place')!.segments.find(s => s.link)!.link).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    expect(text).toMatch(/Depended on by · 14 files +edges/)
    // The dependents' boundaries in the project's colour order: declared ones first.
    expect(plainText(row(rows, 'deps-reach')!)).toBe('   reaching Http Core tests')
    expect(plainText(row(rows, 'dep-0')!)).toMatch(/^› {2}src\/Core\/Kernel\.php +Core +━+ +6$/)
    expect(plainText(row(rows, 'dep-1')!)).toMatch(/^ {3}tests\/Http\/RouterTest\.php +tests +[━╸]+·* +3$/)
    expect(plainText(row(rows, 'deps-more')!)).toBe('   +12 not listed')
    expect(text).toMatch(/Declares · 3 components +used by/)
    expect(plainText(row(rows, 'comp-1')!)).toMatch(/^ {3}Router::dispatch +━+·* +4$/)
    expect(plainText(row(rows, 'comps-more')!)).toBe('   +1 not listed')
  })

  it('walks the dependents (each a file) then the components, by the indexes their presses carry', () => {
    const list = listFor(shown)
    expect(list.map(o => [o.canonical, o.file === true])).toEqual([
      ['src/Core/Kernel.php', true],
      ['tests/Http/RouterTest.php', true],
      ['App\\Http\\Router', false],
      ['App\\Http\\Router::dispatch', false],
    ])
    const rows = paneRows(shown, 60)
    expect(row(rows, 'dep-1')!.segments.find(s => s.press)?.press?.id).toBe('row:1')
    expect(row(rows, 'comp-0')!.segments.find(s => s.press)?.press?.id).toBe('row:2')
    // A component opens at its line in the file.
    expect(list[3]!.loc).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: 30 })
  })

  it('copies, asks about and opens the file itself', () => {
    expect(subjectOf(shown)).toEqual({ name: 'src/Http/Router.php', canonical: 'src/Http/Router.php', loc: { path: `${ROOT}/src/Http/Router.php`, line: null }, file: true })
    expect(editTarget(shown)).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    expect(keysOf(paneRows(shown, 90))).toEqual(['b', 'j', 'k', 'o', 'e', 'c', 'q', 'h'])
  })

  it('says it is reading, what knossos said, or that it said nothing', () => {
    const loading = paneRows(pane({ view: { inspect: SHOWN }, state: null }), 60)
    expect(textOf(loading)).toContain('Reading what depends on src/Http/Router.php…')
    const missing = paneRows(pane({ view: { inspect: SHOWN }, state: done({ ...answer(), status: 'not-found', file: null }) }), 60)
    expect(textOf(missing)).toContain('src/Http/Router.php is not in the graph')
    expect(textOf(paneRows(pane({ view: { inspect: SHOWN }, state: done(null) }), 60))).toContain('knossos said nothing')
    // A component's stored answer is not a file's.
    const other: DetailState = { snapshot_id: 's1', name: SHOWN.name, detail: null, phase: 'done' }
    expect(fileDetailInput(SHOWN, other).loading).toBe(true)
  })

  it('says when nothing else depends on the file', () => {
    const alone = answer({ dependents: { count: 0, truncated: false, boundaries: [], items: [] } })
    const text = textOf(paneRows(pane({ view: { inspect: SHOWN }, state: done(alone) }), 60))
    expect(text).toContain('Depended on by · 0 files')
    expect(text).toContain('none: no other file depends on it')
  })

  it('never draws wider than the columns', () => {
    const long = answer({ path: 'src/a/rather/deeply/nested/directory/with/a/VeryLongControllerName.php' })
    for (const columns of WIDTHS) {
      for (const state of [done(long), null]) {
        for (const r of paneRows(pane({ view: { inspect: { ...SHOWN, label: long.file!.path } }, state }), columns)) {
          expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
        }
      }
    }
  })
})

describe('the drifted files', () => {
  it('make the drift count a button where it stands, and d lists them', () => {
    // Off the Overview the summary line says the figures; on it, from the medium tier, the tiles do.
    const rows = paneRows(pane({ view: { tab: 'hubs' } }), 90)
    const summary = row(rows, 'summary')!
    expect(plainText(summary)).toBe('1,234 components · 2 boundaries · 23 drifted · PHP')
    // The count in the accent, the word after it the button: the one pressable thing on a quiet line looks it.
    expect(summary.segments.find(s => s.press)?.press).toEqual({ id: 'drifted', label: 'drifted' })
    expect(summary.segments.find(s => s.text === '23 ')).toMatchObject({ color: 'suggestion' })
    expect(keysOf(rows)).toContain('d')
    expect(row(rows, 'drift-head')).toBeUndefined()
    // On the Overview the drifted tile carries the same press, and the summary line keeps only the languages.
    const overview = paneRows(pane(), 90)
    expect(plainText(row(overview, 'summary')!)).toBe('PHP')
    const label = row(overview, 'tiles-0-label')!.segments.find(s => s.press)
    expect(label).toMatchObject({ text: 'drifted', press: { id: 'drifted', label: 'drifted' } })
    expect(row(overview, 'tiles-0-value')!.segments.find(s => s.text === '23')).toMatchObject({ color: 'suggestion', bold: true })
  })

  it('are listed under the header, marked as Changes marks files, with how many more', () => {
    const open = pane({ view: { drift: true } })
    const rows = paneRows(open, 90)
    expect(plainText(row(rows, 'drift-head')!)).toMatch(/^Drifted since the snapshot +23$/)
    expect(plainText(row(rows, 'drift-0')!)).toMatch(/^› {2}src\/Http\/Router\.php +Http$/)
    expect(plainText(row(rows, 'drift-1')!)).toMatch(/^ \+ src\/Http\/New\.php/)
    expect(plainText(row(rows, 'drift-2')!)).toMatch(/^ − src\/Core\/Gone\.php +Core$/)
    expect(plainText(row(rows, 'drift-more')!)).toBe('   +20 not listed')
    expect(rows.findIndex(r => r.key === 'drift-head')).toBeLessThan(rows.findIndex(r => r.key === 'tabs'))
    // The marker is theirs while they are listed: the tab below draws none.
    expect(rows.filter(r => plainText(r).startsWith('›'))).toHaveLength(1)
    expect(listFor(open).map(o => [o.canonical, o.file])).toEqual([
      ['src/Http/Router.php', true],
      ['src/Http/New.php', true],
      ['src/Core/Gone.php', true],
    ])
    // A deleted file has nothing to open in the editor, but still a detail as the snapshot holds it.
    expect(editTarget({ ...open, selected: 2 })).toBeNull()
    expect(editTarget(open)).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    expect(row(rows, 'keys')!.segments.find(s => s.press?.id === 'drift')?.press?.label).toBe('hide drifted')
  })

  it('are not offered by a knossos that only counts them', () => {
    const counted = dash({ freshness: { state: 'stale', age_seconds: 600, drift_files: 3 } })
    const rows = paneRows(pane({ d: counted, view: { drift: true } }), 90)
    expect(row(rows, 'summary')!.segments.some(s => s.press)).toBe(false)
    expect(keysOf(rows)).not.toContain('d')
    expect(row(rows, 'drift-head')).toBeUndefined()
  })

  it('never draw wider than the columns, and keep the count when the summary is cut', () => {
    for (const columns of WIDTHS) {
      for (const r of paneRows(pane({ view: { drift: true } }), columns)) expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
    }
    // At 40 columns the summary drops its tail, the button with it; `d` in the keys still lists them.
    const narrow = paneRows(pane(), 40)
    expect(keysOf(narrow)).toContain('d')
  })
})

describe('the key model', () => {
  const session = accumulate(NO_CHANGES, brief())

  it('e on Overview opens the marked row, whatever list the marker is in', () => {
    const overview = pane({ session })
    // Look at now, then the last turn's file, then the most depended on.
    expect(listFor(overview).map(o => o.canonical)).toEqual(['src/Http/Router.php', 'src/Http/Router.php', 'App\\Http\\Router'])
    expect(editTarget(overview)).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    expect(editTarget({ ...overview, selected: 1 })).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    // A hub has no file on the dashboard: no `e` there, and its button goes with it.
    expect(editTarget({ ...overview, selected: 2 })).toBeNull()
    expect(keysOf(paneRows({ ...overview, selected: 2 }, 90))).not.toContain('e')
    expect(keysOf(paneRows(overview, 90))).toEqual(['j', 'k', 'o', 'e', 'c', 'q', 'd', 'h'])
  })

  it('keeps the same keys on Changes: c copies the marked file, t the test command', () => {
    const changes = pane({ session, view: { tab: 'changes' } })
    expect(keysOf(paneRows(changes, 90))).toEqual(['j', 'k', 'o', 'e', 'c', 'q', 't', 'd', 'h'])
    expect(subjectOf(changes)?.canonical).toBe('src/Http/Router.php')
  })

  it('lists every key in the help, t and d among them', () => {
    const text = textOf(paneRows({ ...pane({ session }), showKeys: true }, 90))
    expect(text).toMatch(/\no +open the marked row: a component or a file shows what depends on it/)
    expect(text).toMatch(/\ne +open the marked row's file in your editor\n/)
    expect(text).toMatch(/\nt +copy the command for the tests that reach this session's changes/)
    expect(text).toMatch(/\nd +list the files drifted since the snapshot/)
  })
})

describe('the scope of every count', () => {
  it("says which counts are this session's and which the last turn's, on Overview and Changes alike", () => {
    // Two turns: the session reaches two tests, the last turn one.
    const first = brief({ tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }, { path: 'tests/Core/KernelTest.php', distance: 2 }] })
    const last = brief()
    const session = accumulate(accumulate(NO_CHANGES, first), last)
    const overview = textOf(paneRows(pane({ session, turn: last }), 90))
    expect(overview).toMatch(/Look at now +this session/)
    expect(overview).toContain('2 tests reach these changes')
    expect(overview).toMatch(/Last turn +1 file → 41 dependents · 1 test/)
    const changes = textOf(paneRows(pane({ session, turn: last, view: { tab: 'changes' } }), 90))
    expect(changes).toMatch(/Changes this session +2 turns/)
    expect(changes).toMatch(/Tests that reach these changes · 2/)
  })
})
