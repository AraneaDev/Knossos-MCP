import { describe, expect, it } from 'vitest'
import type { Dashboard, DetailState, FileDetail, KnossosView, SessionChanges, TurnBrief } from '../../types'
import { accumulate, editTarget, fileDetailInput, listFor, NO_CHANGES, paneInput, paneRows, rowWidth, subjectOf } from './layout'
import { findRow, plainText, rawText } from './__tests__/plain-text'
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
    uses: { count: 1, truncated: false, items: [{ path: 'src/Core/Container.php', edges: 2, boundary: 'Core' }] },
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

  it('draws who depends on the file and what it depends on round it, with counts and boundaries, and what it declares', () => {
    const rows = paneRows(shown, 90)
    const text = textOf(rows)
    const raw = rows.map(rawText).join('\n')
    expect(plainText(row(rows, 'detail-head')!)).toMatch(/^Router\.php +■ Http$/)
    expect(plainText(row(rows, 'detail-place')!)).toBe('src/Http/Router.php · PHP · 240 lines')
    expect(row(rows, 'detail-place')!.segments.find(s => s.link)!.link).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: null })
    expect(text).toMatch(/Dependencies · used by 14 · uses 1 +edges/)
    // The dependents' boundaries in the project's colour order: declared ones first.
    expect(plainText(row(rows, 'deps-reach')!)).toBe('   reaching ■ Http ■ Core ■ tests')
    // Each file by its name in a box, its count on its edge; the twelve not listed as one box.
    expect(raw).toMatch(/│ Kernel\.php +├─+ 6 ─+[╮┤┼]/)
    expect(raw).toContain('│ +12 more')
    expect(raw).toMatch(/─ 2 ─+►│ Container\.php/)
    expect(raw).toMatch(/►│ Router\.php ├/)
    expect(text).toMatch(/Declares · 3 components +used by/)
    expect(plainText(row(rows, 'comp-1')!)).toMatch(/^ {3}Router::dispatch +━+·* +4$/)
    expect(plainText(row(rows, 'comps-more')!)).toBe('   +1 not listed')
    // Below fifty columns the two lists as tables.
    const narrow = paneRows(shown, 40)
    expect(plainText(row(narrow, 'dep-0')!)).toMatch(/^› {2}…?[a-zA-Z/]*Kernel\.php +■ Core +━+ +6$/)
    expect(plainText(row(narrow, 'deps-more')!)).toBe('   +12 not listed')
    expect(plainText(row(narrow, 'use-0')!)).toMatch(/Container\.php +■ Core/)
  })

  it('walks the dependents, then what it depends on (each a file), then the components, by the indexes their presses carry', () => {
    const list = listFor(shown)
    expect(list.map(o => [o.canonical, o.file === true])).toEqual([
      ['src/Core/Kernel.php', true],
      ['tests/Http/RouterTest.php', true],
      ['src/Core/Container.php', true],
      ['App\\Http\\Router', false],
      ['App\\Http\\Router::dispatch', false],
    ])
    const rows = paneRows(shown, 60)
    const press = (id: string) => rows.flatMap(r => r.segments).find(s => s.press?.id === id)
    expect(press('row:1')?.text).toBe('RouterTest.php')
    expect(press('row:2')?.text).toBe('Container.php')
    expect(row(rows, 'comp-0')!.segments.find(s => s.press)?.press?.id).toBe('row:3')
    // A component opens at its line in the file.
    expect(list[4]!.loc).toEqual({ path: `${ROOT}/src/Http/Router.php`, line: 30 })
    // An older knossos says nothing of what the file depends on: its dependents alone.
    const old = pane({ view: { inspect: SHOWN }, state: done(answer({ uses: undefined })) })
    expect(listFor(old).map(o => o.canonical)).toEqual(['src/Core/Kernel.php', 'tests/Http/RouterTest.php', 'App\\Http\\Router', 'App\\Http\\Router::dispatch'])
    expect(textOf(paneRows(old, 90))).toMatch(/used by 14 · uses 0/)
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
    expect(paneRows(pane({ view: { inspect: SHOWN }, state: done(alone) }), 90).map(rawText).join('\n')).toContain('nothing uses it')
    const text = textOf(paneRows(pane({ view: { inspect: SHOWN }, state: done(alone) }), 40))
    expect(text).toContain('Depended on by · 0')
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
  it('are a key away on every tab, and a press on the drifted tile on the Overview', () => {
    // The header is the project and the tabs alone: off the Overview `d` in the keys lists them.
    const rows = paneRows(pane({ view: { tab: 'hubs' } }), 90)
    expect(row(rows, 'summary')).toBeUndefined()
    expect(keysOf(rows)).toContain('d')
    expect(row(rows, 'drift-head')).toBeUndefined()
    // On the Overview the drifted tile carries the press, its count in the accent.
    const overview = paneRows(pane(), 90)
    const label = row(overview, 'tiles-0-label')!.segments.find(s => s.press)
    expect(label).toMatchObject({ text: 'drifted', press: { id: 'drifted', label: 'drifted' } })
    expect(row(overview, 'tiles-0-value')!.segments.find(s => s.text === '23')).toMatchObject({ color: 'suggestion', bold: true })
  })

  it('are listed under the tabs, marked as Changes marks files, with how many more', () => {
    const open = pane({ view: { drift: true } })
    const rows = paneRows(open, 90)
    expect(plainText(row(rows, 'drift-head')!)).toMatch(/^Drifted since the snapshot +23$/)
    expect(plainText(row(rows, 'drift-0')!)).toMatch(/^› {2}src\/Http\/Router\.php +■ Http$/)
    expect(plainText(row(rows, 'drift-1')!)).toMatch(/^ \+ src\/Http\/New\.php/)
    expect(plainText(row(rows, 'drift-2')!)).toMatch(/^ − src\/Core\/Gone\.php +■ Core$/)
    expect(plainText(row(rows, 'drift-more')!)).toBe('   +20 not listed')
    expect(rows.findIndex(r => r.key === 'drift-head')).toBeGreaterThan(rows.findIndex(r => r.key === 'tabs'))
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
    expect(rows.some(r => r.segments.some(s => s.press?.id === 'drifted'))).toBe(false)
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

  it('e on Overview opens nothing: its rows open other tabs, never a file', () => {
    const overview = pane({ session })
    // The way to Changes first; the charts' bars after it, when the dashboard sends them.
    expect(listFor(overview).map(o => o.canonical)).toEqual(['Changes'])
    expect(listFor(overview)[0]?.jump).toEqual({ tab: 'changes' })
    expect(editTarget(overview)).toBeNull()
    // `t` stands in the session card, beside the tests it copies, not in the bar.
    expect(keysOf(paneRows(overview, 90))).toEqual(['j', 'k', 'o', 'c', 'q', 'd', 'h'])
    expect(paneRows(overview, 90).flatMap(r => r.segments).filter(s => s.press?.hotkey === 't')).toHaveLength(1)
  })

  it('keeps the same keys on Changes: c copies the marked file, t the test command', () => {
    const changes = pane({ session, view: { tab: 'changes' } })
    expect(keysOf(paneRows(changes, 90))).toEqual(['j', 'k', 'o', 'e', 'c', 'q', 't', 'd', 'h'])
    expect(subjectOf(changes)?.canonical).toBe('src/Http/Router.php')
  })

  it('lists every key in the help, t and d among them', () => {
    const text = textOf(paneRows({ ...pane({ session }), showKeys: true }, 90))
    expect(text).toMatch(/\n {2}o +open the marked row: a component or a file shows what depends on/)
    expect(text).toMatch(/\n {2}e +open the marked row's file in your editor\n/)
    expect(text).toMatch(/\n {2}t +on Overview and Changes: copy the command for the tests/)
    expect(text).toMatch(/\n {2}d +list the files drifted since the snapshot/)
  })
})

describe('the scope of every count', () => {
  it("says the counts are this session's, on Overview and Changes alike", () => {
    // Two turns: the session reaches two tests, the last turn one.
    const first = brief({ tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }, { path: 'tests/Core/KernelTest.php', distance: 2 }] })
    const last = brief()
    const session = accumulate(accumulate(NO_CHANGES, first), last)
    const overview = textOf(paneRows(pane({ session, turn: last }), 90))
    expect(overview).toMatch(/This session/)
    expect(overview).toMatch(/1 file · 41 dependents · 2 tests reach them/)
    const changes = textOf(paneRows(pane({ session, turn: last, view: { tab: 'changes' } }), 90))
    expect(changes).toMatch(/Changes this session +2 turns/)
    expect(changes).toMatch(/Tests that reach these changes · 2/)
  })
})
