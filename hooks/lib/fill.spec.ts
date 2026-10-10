import { describe, expect, it } from 'vitest'
import type { BranchDiff, Dashboard, KnossosView, PaneTab } from '../../types'
import { paneInput, paneLayout, statsOf } from './layout'
import { rawText } from './__tests__/plain-text'
import type { Row } from './rows'
import { tierOf } from './rows'

const ROOT = '/work/app'
const hub = (i: number) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', boundary: 'core', in_degree: 90 - i, out_degree: i, cross_boundary_degree: 0, dependent_files: 40 - i, path: `src/Hub${i}.php`, line: 3 })
const d = {
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: Array.from({ length: 4 }, (_, i) => hub(i)),
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [],
  fan_in_truncated: false,
  complexity_hotspots: [{ path: 'src/Big.php', language: 'php', lines: 900, dependent_files: 12, score: 10_800 }],
} as Dashboard
const view = (tab: PaneTab): KnossosView => ({ inspect: null, isBandHidden: false, tab, selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' })
const list = <T>(n: number, of: (i: number) => T) => ({ count: n, items: Array.from({ length: n }, (_, i) => of(i)) })
const item = (name: string) => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 7, boundary: 'core' })
/** A comparison with `n` new hubs and nothing else new: four of its cards collapse to one line each. */
const branch = (n: number): BranchDiff => ({
  status: 'ok',
  branch: 'feat/x',
  default_branch: 'main',
  merge_base: { rev: 'fd137146c3', at: 1_790_953_266 },
  ahead: 3,
  base: { snapshot_id: 's0', rev: 'fd137146c3', at: '2026-10-02T15:01:06Z', match: 'exact', commits: 0 },
  comparison: {
    crossing: list(0, () => ({ source: item('A'), target: item('B') })),
    cycles: list(0, () => ({ size: 2, members: [item('A'), item('B')] })),
    hubs: list(n, i => ({ component: item(`Hub${i}`), before: 10, after: 12 + i })),
    dead_code: list(0, i => item(`Dead${i}`)),
    violations: { ...list(0, () => ({ policy_id: 'p', source: 'App\\A', source_kind: 'class', target: 'App\\B', target_kind: 'class' })), truncated: false },
  },
})
/** Churn with the branch's hub count of files: none, a few, many. */
const churned = (n: number) => ({ head: 'h', phase: 'done' as const, answer: { status: 'ok' as const, days: 30, commits: 9, files: Array.from({ length: n }, (_, i) => ({ path: `src/F${i}.php`, commits: 9 - (i % 9), dependents: 40 - i, score: (9 - (i % 9)) * (40 - i), boundary: 'core' })) } })
const pane = (tab: PaneTab, b: BranchDiff) => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(tab), 0, true, null, null, undefined, null, undefined, { branch: { snapshot: 's1', phase: 'done', answer: b }, churn: churned(b.comparison?.hubs.count ?? 0) })

const blankRow = (r: Row): boolean => r.code === undefined && rawText(r).trim() === ''

/** The longest run of blank rows that has something drawn after it, below the header's rule. */
function interiorGap(body: Row[]): number {
  const start = body.findIndex(r => r.key === 'head-rule') + 1
  let longest = 0
  let run = 0
  for (const r of body.slice(start)) {
    if (blankRow(r)) run++
    else {
      longest = Math.max(longest, run)
      run = 0
    }
  }
  return longest
}

/** Blank rows at the foot of the body: what is left when no card took the height. */
function trailing(body: Row[]): number {
  let n = 0
  for (let i = body.length - 1; i >= 0 && blankRow(body[i]!); i--) n++
  return n
}

describe('cards fill the height without gaps', () => {
  // A sweep over every width and height: seconds under coverage, so it gets its own bound rather than the default 5 s.
  it('never leaves blank rows between collapsed cards and the cards after them, on any tab', { timeout: 30_000 }, () => {
    for (const tab of ['overview', 'hubs', 'boundaries', 'cycles', 'issues', 'changes', 'branch', 'churn'] as PaneTab[]) {
      for (const b of [branch(0), branch(2), branch(12)]) {
        for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
          for (const height of [24, 40, 60]) {
            const { body } = paneLayout(pane(tab, b), columns, height)
            expect(interiorGap(body), `${tab} ${b.comparison?.hubs.count} ${columns}x${height}`).toBeLessThanOrEqual(1)
          }
        }
      }
    }
  })

  it('gives the height to the framed cards: no blank rows at the foot of a framed pane', () => {
    for (const tab of ['issues', 'cycles', 'branch', 'churn'] as PaneTab[]) {
      for (const b of [branch(0), branch(2)]) {
        for (const columns of [60, 80, 100, 130, 140, 200]) {
          if (tierOf(columns) === 'narrow') continue
          for (const height of [40, 60]) {
            const { body, pinned } = paneLayout(pane(tab, b), columns, height)
            if (pinned) continue
            expect(trailing(body), `${tab} ${b.comparison?.hubs.count} ${columns}x${height}`).toBe(0)
          }
        }
      }
    }
  })
})

describe('the stat tiles', () => {
  const stats = (over: Partial<Dashboard>, policy: string | null = null, diagnostics: number | null = null) =>
    statsOf({ ...d, summary: { components: 120, boundaries: 4, files: 40, languages: [], kinds: [] }, ...over } as Dashboard, ['120 components', '4 boundaries'], policy, diagnostics, false)
  const press = (list: ReturnType<typeof stats>, key: string) => list.find(s => s.key === key)?.press

  it('press to the list a figure counts, where it has something to list', () => {
    const list = stats({ cycles: { count: 3, truncated: false, truncation_reasons: [], largest: [] }, dead_code_candidates: 7 }, '2', 5)
    expect(press(list, 'cycles')).toBe('stat:cycles')
    expect(press(list, 'dead')).toBe('stat:dead')
    expect(press(list, 'policy')).toBe('stat:policy')
    expect(press(list, 'diagnostics')).toBe('stat:diagnostics')
    expect(press(list, 'components')).toBe('stat:components')
    expect(press(list, 'boundaries')).toBe('stat:boundaries')
    // A figure, not a set: nothing to list.
    expect(press(list, 'degree')).toBeUndefined()
  })

  it('show a cycle search that stopped before it found any as unknown, not as zero', () => {
    const list = stats({ cycles: { count: 0, truncated: true, truncation_reasons: ['time_limit'], largest: [] } })
    const tile = list.find(s => s.key === 'cycles')
    expect(tile?.value).toBe('?')
    expect(tile?.tone).toBe('warn')
    // The Cycles tab says why.
    expect(tile?.press).toBe('stat:cycles')
    // A capped count that found some still reads as a floor.
    expect(stats({ cycles: { count: 50, truncated: true, truncation_reasons: ['result_limit'], largest: [] } }).find(s => s.key === 'cycles')?.value).toBe('50+')
  })

  it('stay text at zero', () => {
    const list = stats({ cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, dead_code_candidates: 0 }, '0', 0)
    for (const key of ['cycles', 'dead', 'policy', 'diagnostics']) expect(press(list, key), key).toBeUndefined()
    // An empty graph: no component and no boundary to list either.
    const empty = statsOf({ ...d, summary: { components: 0, boundaries: 0, files: 0, languages: [], kinds: [] } } as unknown as Dashboard, ['0 components', '0 boundaries'], null, null, false)
    for (const key of ['components', 'boundaries']) {
      expect(empty.some(s => s.key === key), key).toBe(true)
      expect(press(empty, key), key).toBeUndefined()
    }
  })
})
