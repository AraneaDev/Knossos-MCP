import { describe, expect, it } from 'vitest'
import type { Dashboard, GraphSearch, KnossosView, SearchState } from '../../types'
import { finderInput, finderList } from './finder'
import { listFor, paneInput, paneLayout } from './layout'
import { plainText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'issues', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', finding: true, ...over })
const answer = (n: number): GraphSearch => ({
  status: 'ok',
  query: 'dash',
  truncated: n > 10,
  results: Array.from({ length: n }, (_, i) =>
    i % 2 === 0
      ? { type: 'component' as const, name: 'cycle', canonical_name: `App\\Query\\DashboardServiceWithAVeryLongName${i}::cycle`, kind: 'method', path: `src/Query/deeply/nested/DashboardService${i}.php`, line: 40 + i, boundary: 'core' }
      : { type: 'file' as const, name: `src/Query/deeply/nested/DashboardService${i}.php`, canonical_name: `src/Query/deeply/nested/DashboardService${i}.php`, kind: 'file', path: `src/Query/deeply/nested/DashboardService${i}.php`, line: null, boundary: null },
  ),
})
const state = (over: Partial<SearchState>): SearchState => ({ query: 'dash', for: 'dash', phase: 'idle', answer: answer(4), ...over })
const pane = (s: SearchState, v = view()) => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, undefined, null, undefined, { search: s })

describe('the finder', () => {
  it('reads matches as components (shown as Class::member) and files, each opening its detail', () => {
    const f = finderInput(state({}), ROOT)
    expect(f.results.map(r => [r.name, r.file])).toEqual([
      ['DashboardServiceWithAVeryLongName0::cycle', false],
      ['src/Query/deeply/nested/DashboardService1.php', true],
      ['DashboardServiceWithAVeryLongName2::cycle', false],
      ['src/Query/deeply/nested/DashboardService3.php', true],
    ])
    expect(finderList(f)[1]).toEqual({ name: 'src/Query/deeply/nested/DashboardService1.php', canonical: 'src/Query/deeply/nested/DashboardService1.php', loc: { path: `${ROOT}/src/Query/deeply/nested/DashboardService1.php`, line: null }, file: true })
    expect(listFor(pane(state({})))).toHaveLength(4)
  })

  it('says it is searching until the answer for what is typed lands, keeping the last matches', () => {
    expect(finderInput(state({ query: 'dashs', phase: 'idle' }), ROOT).searching).toBe(true)
    expect(finderInput(state({ phase: 'searching' }), ROOT).searching).toBe(true)
    expect(finderInput(state({ query: 'dashs' }), ROOT).results).toHaveLength(4)
    expect(finderInput(state({ query: '' }), ROOT).results).toEqual([])
    expect(finderInput(state({ answer: null }), ROOT).failed).toBe(true)
  })

  it('stands over the tab with its field, hint or matches, and fits every width and height', () => {
    for (const s of [state({ query: '', for: null, answer: null }), state({}), state({ answer: answer(30) }), state({ answer: { ...answer(0), results: [] } }), state({ answer: null })]) {
      for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
        for (const height of [24, 40, 60]) {
          const { body, footer } = paneLayout(pane(s), columns, height)
          const rows = [...body, ...footer]
          for (const r of rows) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
          expect(rows.some(r => r.segments.some(seg => seg.field?.id === 'find'))).toBe(true)
          // The tab under it is not drawn, and the footer offers closing the finder.
          expect(rows.some(r => r.key.startsWith('pol-'))).toBe(false)
          expect(footer.some(r => r.segments.some(seg => seg.press?.id === 'find-close'))).toBe(true)
        }
      }
    }
    const text = (s: SearchState) => paneLayout(pane(s), 100, 40).body.map(plainText).join('\n')
    expect(text(state({ query: '', for: null, answer: null }))).toContain('Type letters of a component or file name, in order.')
    expect(text(state({ answer: { ...answer(0), results: [] } }))).toContain('Nothing matches "dash".')
    expect(text(state({ answer: null }))).toContain('knossos did not answer')
    expect(text(state({ answer: answer(30) }))).toMatch(/Find +30\+ found/)
  })

  it('keeps the marked match in view, the rows around it, however far down it is', () => {
    const s = state({ answer: answer(30) })
    const rowsAt = (selected: number) => paneLayout(pane(s, view({ selected })), 100, 24).body
    const found = (rows: ReturnType<typeof rowsAt>) => rows.filter(r => /^found-\d+$/.test(r.key)).map(r => Number(r.key.slice(6)))
    const top = found(rowsAt(0))
    expect(top[0]).toBe(0)
    // Far down: the marker is there and stands in the middle of what is shown, which says what is above and below.
    const rows = rowsAt(20)
    const shown = found(rows)
    expect(shown).toContain(20)
    expect(Math.abs(shown.indexOf(20) - Math.floor(shown.length / 2))).toBeLessThanOrEqual(1)
    expect(plainText(rows.find(r => r.key === 'found-more')!)).toMatch(/\d+ above ↑ · \d+ more ↓ · type more to narrow/)
    // At the end, the last matches fill the card.
    expect(found(rowsAt(29)).at(-1)).toBe(29)
  })
})
