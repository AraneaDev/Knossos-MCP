import { describe, expect, it } from 'vitest'
import type { BlastRadius, ComponentDetail, Dashboard, KnossosView, NoteState, RingsState } from '../../types'
import { detailInput, listFor, paneInput, paneLayout } from './layout'
import { ringRows, ringsInput, ringsList, RINGS_MIN } from './rings'
import { plainText, rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: { name: 'App\\Store\\StableId', label: 'StableId' }, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const member = (name: string, tested: boolean, boundary = 'core') => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 3, boundary, tested })
const radius = (n = 4): BlastRadius => ({
  status: 'ok',
  component: { name: 'StableId', canonical_name: 'App\\Store\\StableId', kind: 'class', boundary: 'core' },
  truncated: false,
  rings: [
    { hop: 1, count: n, tested: n - 1, items: Array.from({ length: n }, (_, i) => member(`Near${i}`, i > 0, i === 1 ? 'edge' : 'core')), tests: { count: 2, items: [{ path: 'tests/NearTest.php', hop: 2 }, { path: 'tests/Other/FarTest.php', hop: 3 }] } },
    { hop: 2, count: 30, tested: 30, items: Array.from({ length: 12 }, (_, i) => member(`MiddleComponentWithALongerName${i}`, true)), tests: { count: 9, items: Array.from({ length: 9 }, (_, i) => ({ path: `tests/Middle${i}Test.php`, hop: 3 })) } },
    { hop: 3, count: 0, tested: 0, items: [], tests: { count: 0, items: [] } },
  ],
})
const state = (a: BlastRadius | null, name = 'App\\Store\\StableId'): RingsState => ({ name, snapshot: 's1', phase: 'done', answer: a })
const detailAnswer: ComponentDetail = {
  status: 'ok',
  path: ROOT,
  name: 'App\\Store\\StableId',
  project_id: 'p1',
  snapshot_id: 's1',
  component: {
    name: 'App\\Store\\StableId',
    display_name: 'StableId',
    kind: 'class',
    path: 'src/Store/StableId.php',
    line: 9,
    boundary: 'core',
    boundaries: ['core'],
    used_by: { count: 1, truncated: false, names: [], items: [{ name: 'Caller', canonical_name: 'App\\Caller', kind: 'class', boundary: 'core', edges: 2 }] },
    uses: { count: 0, truncated: false, names: [], items: [] },
    annotations: [],
  },
  candidates: [],
}
const shown = detailInput({ name: 'App\\Store\\StableId', label: 'StableId' }, { snapshot_id: 's1', name: 'App\\Store\\StableId', detail: detailAnswer, phase: 'done' }, ROOT)
const pane = (rings: RingsState | null, v = view(), note: NoteState | null = null) => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, shown, null, undefined, null, undefined, { rings, note })
const text = (rings: RingsState | null, columns = 100, v = view()) => paneLayout(pane(rings, v), columns, 80).body.map(plainText).join('\n')

describe('the blast radius in a component detail', () => {
  it('says while it reads, and when nothing depends on the component', () => {
    expect(text(null)).toContain('Reading what depends on it, hop by hop…')
    expect(text(state(null))).toContain('knossos did not answer')
    expect(text(state({ ...radius(), status: 'not-found', component: null, rings: [] }))).toContain('knossos found no component by that name')
    const none = radius()
    none.rings.forEach(r => Object.assign(r, { count: 0, tested: 0, items: [], tests: { count: 0, items: [] } }))
    expect(text(state(none))).toContain('Nothing depends on it: a change here reaches no other component.')
    // The rings of another component never show on this one's detail.
    expect(text(state(radius(), 'App\\Other'))).toContain('Reading what depends on it')
  })

  it('draws the rings as nested frames, the furthest outermost and the component in the middle', () => {
    const rows = ringRows(ringsInput(state(radius()), 'App\\Store\\StableId', ROOT), 80, -1, 0)
    const raw = rows.map(rawText)
    expect(raw[0]).toMatch(/^╭─ 3\+ hops · 0 ─+ none ─╮$/)
    expect(raw.find(r => r.includes('2 hops'))).toMatch(/^│ ╭─ 2 hops · 30 ─+ ✓ all tested ─╮ │$/)
    expect(raw.find(r => r.includes('1 hop '))).toMatch(/^│ │ ╭─ 1 hop · 4 ─+ ▲ 1 untested ─╮ │ │$/)
    expect(raw.find(r => r.includes('◉'))).toMatch(/^│ │ │ +◉ StableId ■ core +│ │ │$/)
    expect(raw.at(-1)).toMatch(/^╰─+╯$/)
    expect(raw.at(-2)).toMatch(/^│ ╰─+╯ │$/)
    for (const r of rows) expect(rowWidth(r), rawText(r)).toBe(80)
    // The untested first, marked; a boundary named only where it is not the component's own.
    const near = raw.find(r => r.includes('Near0'))!
    expect(near).toMatch(/▲Near0 {3}Near1/)
    expect(near).toContain('Near1 ■ edge')
    expect(near).not.toContain('Near2 ■')
    // The tests that reach a ring, by file name, then how many more.
    expect(raw.find(r => r.includes('test files: Middle0Test.php'))).toMatch(/✓ 9 test files: Middle0Test\.php, .* \+\d+ +│ │$/)
    expect(raw.find(r => r.includes('NearTest'))).toContain('✓ 2 test files: NearTest.php, FarTest.php')
  })

  it('makes every named component a row the marker walks and o opens, after the detail\'s own', () => {
    const input = pane(state(radius()))
    const list = listFor(input)
    // The one dependent the detail lists, then the rings' four near and twelve further.
    expect(list.map(o => o.canonical)).toEqual(['App\\Caller', ...[0, 1, 2, 3].map(i => `App\\Near${i}`), ...Array.from({ length: 12 }, (_, i) => `App\\MiddleComponentWithALongerName${i}`)])
    expect(ringsList(ringsInput(state(radius()), 'App\\Store\\StableId', ROOT))[0]?.loc).toEqual({ path: `${ROOT}/src/Near0.php`, line: 3 })
    const rows = paneLayout(pane(state(radius()), view({ selected: 2 })), 100, 80).body
    const marked = rows.flatMap(r => r.segments).find(s => s.press?.id === 'rel:2')
    expect(marked).toMatchObject({ bg: 'userMessageBackground', bold: true })
  })

  it('fits every width and height, as frames from the width they fit and as lists below it', () => {
    for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
      for (const height of [24, 40, 60]) {
        for (const r of [null, state(radius(1)), state(radius(40))]) {
          const { body, footer } = paneLayout(pane(r), columns, height)
          for (const row of [...body, ...footer]) expect(rowWidth(row), `${columns}x${height} ${row.key}`).toBeLessThanOrEqual(columns)
        }
      }
    }
    expect(text(state(radius()), 40)).toMatch(/1 hop · 4 +▲ 1 untested/)
    expect(text(state(radius()), 40)).not.toContain('╭─ 1 hop')
    expect(RINGS_MIN).toBeLessThanOrEqual(60)
  })
})

describe('notes on a component detail', () => {
  const note = (over: Partial<NoteState> = {}): NoteState => ({ component: 'App\\Store\\StableId', phase: 'editing', value: 'Keep it pure', previous: null, reason: null, ...over })
  const rows = (n: NoteState | null, columns = 100) => paneLayout(pane(null, view(), n), columns, 80).body
  const said = (n: NoteState | null, columns = 100) => rows(n, columns).map(plainText).join('\n')
  const presses = (n: NoteState | null) => rows(n).flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [`${s.press.id}${s.press.hotkey === undefined ? '' : `=${s.press.hotkey}`}`])))

  it('offers m to add one, and says there is none yet', () => {
    expect(said(null)).toMatch(/Notes\n {3}No notes on StableId yet\./)
    expect(presses(null)).toContain('note=m')
  })

  it('takes the note in a field, asks before recording it, and says what it replaces', () => {
    const field = rows(note()).flatMap(r => r.segments).find(s => s.field !== undefined)
    expect(field?.field).toEqual({ id: 'note', value: 'Keep it pure', placeholder: 'what to remember about StableId' })
    expect(said(note())).toContain('Enter checks it with knossos; nothing is written yet.')
    expect(said(note({ phase: 'previewing' }))).toContain('checking with knossos…')
    const asked = said(note({ phase: 'confirming', previous: 'Pure: no I/O.' }))
    expect(asked.replace(/\s+/g, ' ')).toContain('Record this note on StableId? "Keep it pure" It replaces: "Pure: no I/O."')
    expect(presses(note({ phase: 'confirming' }))).toEqual(expect.arrayContaining(['note-yes=y', 'note-no=n']))
    expect(presses(note({ phase: 'confirming' }))).not.toContain('note=m')
    expect(said(note({ phase: 'saving' }))).toContain('recording…')
    expect(said(note({ phase: 'failed', reason: 'kind must be one of: note.' }))).toContain('✗ knossos did not record it: kind must be one of: note.')
    expect(presses(note({ phase: 'failed' }))).toContain('note=m')
  })

  it('answers by press alone while the allow-root question holds y and n', () => {
    const allowing = paneInput(d, { status: 'not-allowed', path: ROOT, refused_root: ROOT, roots_file: null } as never, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(), 0, true, shown, { phase: 'confirming', root: ROOT, reason: null }, undefined, null, undefined, { note: note({ phase: 'confirming' }) })
    const keys = paneLayout(allowing, 100, 80).body.flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [`${s.press.id}${s.press.hotkey === undefined ? '' : `=${s.press.hotkey}`}`])))
    expect(keys).toEqual(expect.arrayContaining(['allow-yes=y', 'note-yes', 'note-no']))
  })

  it('belongs to the component it was started on, and fits every width', () => {
    expect(said(note({ component: 'App\\Other' }))).not.toContain('Keep it pure')
    for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
      for (const n of [null, note(), note({ phase: 'confirming', value: 'x'.repeat(300), previous: 'y'.repeat(200) }), note({ phase: 'failed', reason: 'z'.repeat(200) })]) {
        for (const r of rows(n, columns)) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
      }
    }
  })
})
