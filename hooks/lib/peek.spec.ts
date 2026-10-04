import { describe, expect, it } from 'vitest'
import type { ComponentDetail, Dashboard, KnossosView, PaneTab } from '../../types'
import { detailInput, paneInput, paneLayout } from './layout'
import type { PaneInput } from './layout'
import { plainText, rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const hub = (i: number) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', boundary: i % 2 === 0 ? 'core' : 'tests', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0, dependent_files: 40 - i, path: `src/Hub${i}.php`, line: 3 })
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: Array.from({ length: 30 }, (_, i) => hub(i)), hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'hubs', selected: 1, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const answer: ComponentDetail = {
  status: 'ok',
  path: ROOT,
  name: 'App\\Hub1',
  project_id: 'p1',
  snapshot_id: 's1',
  component: {
    name: 'App\\Hub1',
    display_name: 'Hub1',
    kind: 'class',
    path: 'src/Hub1.php',
    line: 3,
    boundary: 'tests',
    boundaries: ['tests'],
    used_by: { count: 2, truncated: false, names: [], items: [{ name: 'Caller', canonical_name: 'App\\Caller', kind: 'class', boundary: 'core', edges: 4 }, { name: 'Other', canonical_name: 'App\\Other', kind: 'class', boundary: 'core', edges: 1 }] },
    uses: { count: 1, truncated: false, names: [], items: [{ name: 'Store', canonical_name: 'App\\Store', kind: 'class', boundary: 'core', edges: 2 }] },
  },
  candidates: [],
}
const peeked = detailInput({ name: 'App\\Hub1', label: 'Hub1' }, { snapshot_id: 's1', name: 'App\\Hub1', detail: answer, phase: 'done' }, ROOT)
const pane = (v: KnossosView, peek = peeked, dash: Dashboard = d): PaneInput => paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, undefined, null, undefined, {}, peek)
const has = (rows: { key: string }[]) => rows.some(r => r.key.includes('peek-'))

describe('master-detail on a wide pane', () => {
  it("draws the marked row's detail beside the list on the wide tabs, and only past 130 columns", () => {
    for (const tab of ['hubs', 'changes', 'issues', 'cycles', 'branch', 'churn'] as PaneTab[]) {
      expect(has(paneLayout(pane(view({ tab })), 140, 40).body), tab).toBe(true)
      expect(has(paneLayout(pane(view({ tab })), 130, 40).body), tab).toBe(false)
    }
    // Not where a row has no detail, nor over a detail, the finder or the drifted files.
    for (const tab of ['overview', 'boundaries'] as PaneTab[]) expect(has(paneLayout(pane(view({ tab })), 200, 40).body), tab).toBe(false)
    expect(has(paneLayout(pane(view(), null), 200, 40).body)).toBe(false)
  })

  it('keeps the list on the left, its marker on the row the detail is of, and the detail framed beside it', () => {
    const rows = paneLayout(pane(view()), 200, 40).body
    const marked = rows.find(r => r.key.startsWith('hub-1|'))!
    expect(plainText(marked)).toMatch(/^│ › +Hub1 /)
    const head = rows.find(r => r.key.includes('|peek-detail-head'))!
    expect(rawText(head)).toMatch(/╭─ ◇ Hub1 .*╮ *$/)
    // The detail's presses open what it lists, apart from the tab's rows.
    const presses = rows.flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [s.press.id])))
    expect(presses).toContain('peek:0')
    expect(presses).toContain('peek:2')
    expect(presses.filter(p => p.startsWith('rel:'))).toEqual([])
  })

  it("fills the pane's height with both panels, every row within the width", () => {
    for (const columns of [131, 140, 200]) {
      for (const height of [24, 40, 60]) {
        const { body, footer } = paneLayout(pane(view()), columns, height)
        for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
        expect(body.length + footer.length, `${columns}x${height}`).toBeGreaterThanOrEqual(height)
      }
    }
  })
})

describe('cards fill their height', () => {
  it('stretches the last card to the foot of the pane, never leaving blank rows under it', () => {
    // A card with a list at the foot: an empty one is a single line, with no frame to stretch.
    const listed = { ...d, complexity_hotspots: [{ path: 'src/Big.php', language: 'php', lines: 900, dependent_files: 12, score: 10_800 }] }
    for (const tab of ['issues', 'cycles', 'changes'] as PaneTab[]) {
      for (const columns of [100, 200]) {
        const { body } = paneLayout(pane(view({ tab }), null, listed), columns, 60)
        // The row before the gap above the footer is a card's bottom edge (or a stretched grid's).
        const last = body.at(-1)!
        expect(rawText(last), `${tab} ${columns}`).toMatch(/╰─+╯/)
        expect(body.some(r => r.key.startsWith('fill-')), `${tab} ${columns}`).toBe(false)
      }
    }
  })
})

describe('spare height on the Overview', () => {
  const rich = { ...d, complexity_hotspots: Array.from({ length: 20 }, (_, i) => ({ path: `src/deep/dir/Big${i}.php`, language: 'php', lines: 900 - i, dependent_files: 12, score: (900 - i) * 12 })), fan_in: Array.from({ length: 20 }, (_, i) => ({ path: `src/Hot${i}.php`, dependent_files: 90 - i, boundaries: [], boundary: null })) }
  const overview = (columns: number, height: number) => paneLayout(pane(view({ tab: 'overview' }), null, rich), columns, height).body
  it('goes to the riskiest and the most depended-on files when they fit, never past the pane', () => {
    for (const columns of [100, 140, 200]) {
      const tall = overview(columns, 80)
      expect(tall.some(r => r.key.includes('hotspots-head')), `${columns}`).toBe(true)
      expect(tall.some(r => r.key.includes('files-head')), `${columns}`).toBe(true)
      for (const height of [24, 40, 80]) expect(overview(columns, height).length, `${columns}x${height}`).toBeLessThanOrEqual(Math.max(height, 40))
    }
    // A short pane keeps its charts and leaves them out.
    expect(overview(100, 24).some(r => r.key.includes('hotspots-head'))).toBe(false)
    // Rows a person follows by clicking (a link to the file), never walked by the marker.
    const row = overview(200, 80).find(r => r.key.includes('hotspots-0'))!
    expect(row.segments.some(s => s.link !== undefined)).toBe(true)
    expect(row.segments.some(s => s.press !== undefined)).toBe(false)
  })
})
