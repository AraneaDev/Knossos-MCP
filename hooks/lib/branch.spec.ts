import { describe, expect, it } from 'vitest'
import type { BranchDiff, Dashboard, KnossosView } from '../../types'
import { branchCount, branchInput, branchList } from './branch'
import { paneInput, paneLayout } from './layout'
import { plainText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's9', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'branch', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const item = (name: string, boundary: string | null = 'core', kind = 'class') => ({ name, canonical_name: `App\\${name}`, kind, path: `src/${name}.php`, line: 7, boundary })
const touched = (n: number, count = n + 3) => ({ count, items: Array.from({ length: n }, (_, i) => ({ path: `src/Touched${i}.php`, added: 12 - i, deleted: i, dependents: 40 - i, boundary: 'core' })) })
const answer = (over: Partial<BranchDiff> = {}, n = 2): BranchDiff => ({
  status: 'ok',
  branch: 'feat/a-rather-long-branch-name-for-the-pane',
  default_branch: 'main',
  merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
  ahead: 153,
  base: { snapshot_id: 's1', rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: '2026-10-02T15:01:06Z', match: 'exact', commits: 0 },
  comparison: {
    crossing: { count: n * 3, items: Array.from({ length: n }, (_, i) => ({ source: item(`GreeterWithAnUnreasonablyLongName${i}`, 'core'), target: item(`Caller${i}`, 'module:cli (+composer:app/cli)') })) },
    cycles: { count: n, items: Array.from({ length: n }, (_, i) => ({ size: 3 + i, members: [item(`A${i}`), item(`B${i}`), item(`C${i}`)] })) },
    hubs: { count: n, items: Array.from({ length: n }, (_, i) => ({ component: item(`Hub${i}`), before: 20 + i, after: 31 + i })) },
    dead_code: { count: n, items: Array.from({ length: n }, (_, i) => item(`Unused${i}`, null, 'method')) },
    violations: { count: n, truncated: false, items: Array.from({ length: n }, (_, i) => ({ policy_id: 'core-stays-out', source: `App\\Core\\Greeter${i}::greet`, source_kind: 'method', target: `App\\Edge\\Caller${i}`, target_kind: 'class' })) },
  },
  files: touched(n),
  ...over,
})
const pane = (a: BranchDiff | null, v = view(), phase: 'loading' | 'done' = 'done') => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, undefined, null, undefined, { branch: { snapshot: 's9', phase, answer: a } })
const text = (a: BranchDiff | null, columns = 100, phase: 'loading' | 'done' = 'done') => paneLayout(pane(a, view(), phase), columns, 60).body.map(plainText).join('\n')

describe('the Branch tab', () => {
  it('says where the comparison stands: the branch, its merge base, and the snapshot compared with', () => {
    expect(text(answer()).replace(/\s+/g, ' ')).toContain('feat/a-rather-long-branch-name-for-the-pane against main. It left main at fd13714')
    expect(text(answer()).replace(/\s+/g, ' ')).toContain('Compared with the snapshot taken at the merge base (fd13714, 2026-10-02 15:01 UTC).')
    const before = text(answer({ base: { snapshot_id: 's0', rev: 'aaaaaaa1', at: '2026-10-01T09:00:00Z', match: 'before', commits: 4 } })).replace(/\s+/g, ' ')
    expect(before).toContain('nearest snapshot before the merge base (aaaaaaa, 2026-10-01 09:00 UTC, 4 commits earlier)')
  })

  it('says plainly when no snapshot near the merge base is kept, partial or not at all', () => {
    const partial = text(answer({ status: 'no-snapshot', base: { snapshot_id: 's5', rev: '4054d3a2fc', at: '2026-10-04T11:05:02Z', match: 'after', commits: 146 } })).replace(/\s+/g, ' ')
    expect(partial).toContain('▲ No snapshot near the merge base is retained. Compared with the oldest one after it (4054d3a, 2026-10-04 11:05 UTC), which already holds 146 of the branch\'s 153 commits')
    const none = text(answer({ status: 'no-snapshot', base: null, comparison: null })).replace(/\s+/g, ' ')
    expect(none).toContain('No snapshot near the merge base is retained, so there is nothing to compare with.')
    expect(text(answer({ status: 'on-default', branch: 'main', comparison: null }))).toContain('main is main itself: there is no branch to compare.')
    expect(text(answer({ status: 'no-git', comparison: null }))).toContain('No git repository')
    expect(text(null, 100, 'loading')).toContain('Comparing the branch with its merge base…')
    expect(text(null)).toContain('knossos did not answer')
  })

  it('lists what is new, each component a row that opens, and counts it all on the tab', () => {
    const input = branchInput({ snapshot: 's9', phase: 'done', answer: answer() }, ROOT)
    expect(branchCount(input)).toBe(6 + 2 + 2 + 2 + 2)
    expect(branchList(input).slice(0, 10).map(o => o.canonical)).toEqual([
      'App\\GreeterWithAnUnreasonablyLongName0', 'App\\GreeterWithAnUnreasonablyLongName1', 'App\\A0', 'App\\A1', 'App\\Hub0', 'App\\Hub1', 'App\\Unused0', 'App\\Unused1', 'App\\Core\\Greeter0::greet', 'App\\Core\\Greeter1::greet',
    ])
    expect(branchList(input)[4]?.loc).toEqual({ path: `${ROOT}/src/Hub0.php`, line: 7 })
    const t = text(answer())
    expect(t).toMatch(/New cross-boundary dependencies +▲ 6/)
    expect(t).toContain('+4 not listed')
    expect(t).toMatch(/Hubs that grew +▲ 2/)
    expect(t).toMatch(/Hub0 .*20 → 31 \+11/)
    expect(t).toMatch(/↻ 3 A0 → B0 → C0/)
    const rows = paneLayout(pane(answer(), view({ selected: 4 })), 100, 60).body
    expect(rows.find(r => r.key.includes('grown-0'))?.segments.find(s => s.press !== undefined)?.press?.id).toBe('row:4')
    expect(plainText(rows.find(r => r.key.includes('grown-0'))!)).toMatch(/›/)
    // Nothing new is one line each.
    const clean = answer({ comparison: { crossing: { count: 0, items: [] }, cycles: { count: 0, items: [] }, hubs: { count: 0, items: [] }, dead_code: { count: 0, items: [] }, violations: null } })
    expect(text(clean)).toMatch(/New cycles +✓ 0/)
    expect(text(clean)).not.toContain('New policy violations')
  })

  it('fits every width and height, empty or full', () => {
    for (const a of [answer({}, 30), answer({}, 0), answer({ status: 'no-snapshot', base: null, comparison: null }), null]) {
      for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
        for (const height of [24, 40, 60]) {
          const { body, footer } = paneLayout(pane(a), columns, height)
          for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
        }
      }
    }
  })
})

describe('the files the branch touched', () => {
  const none = { crossing: { count: 0, items: [] }, cycles: { count: 0, items: [] }, hubs: { count: 0, items: [] }, dead_code: { count: 0, items: [] }, violations: null }
  it('lists them after what is new, each a row that opens as its file, never counted on the tab', () => {
    const input = branchInput({ snapshot: 's9', phase: 'done', answer: answer() }, ROOT)
    expect(branchCount(input)).toBe(6 + 2 + 2 + 2 + 2)
    expect(branchList(input).slice(-2)).toEqual([
      { name: 'src/Touched0.php', canonical: 'src/Touched0.php', loc: { path: `${ROOT}/src/Touched0.php`, line: null }, file: true },
      { name: 'src/Touched1.php', canonical: 'src/Touched1.php', loc: { path: `${ROOT}/src/Touched1.php`, line: null }, file: true },
    ])
    const t = text(answer())
    expect(t).toMatch(/Files this branch touched +5 files/)
    expect(t).toMatch(/src\/Touched0\.php .*\+12 −0 +40 deps/)
    expect(t).toContain('+3 not listed')
    const rows = paneLayout(pane(answer(), view({ selected: 11 })), 100, 60).body
    const second = rows.find(r => r.key.includes('branch-files-1'))!
    expect(second.segments.find(s => s.press !== undefined)?.press?.id).toBe('row:11')
    expect(plainText(second)).toMatch(/›/)
  })

  it('needs no snapshot: with nothing to compare, they are still listed and walked', () => {
    const input = branchInput({ snapshot: 's9', phase: 'done', answer: answer({ status: 'no-snapshot', base: null, comparison: null }) }, ROOT)
    expect(branchList(input).map(o => o.canonical)).toEqual(['src/Touched0.php', 'src/Touched1.php'])
    const rows = paneLayout(pane(answer({ status: 'no-snapshot', base: null, comparison: null })), 100, 60).body
    expect(rows.find(r => r.key.includes('branch-files-0'))?.segments.find(s => s.press !== undefined)?.press?.id).toBe('row:0')
    expect(text(answer({ comparison: none, files: { count: 0, items: [] } }))).toMatch(/Files this branch touched +none/)
    expect(text(answer({ status: 'on-default', branch: 'main', comparison: null, files: null }))).not.toContain('Files this branch touched')
  })

  it('leaves the churn hotspots to the Churn tab', () => {
    const churn = { head: 'h', phase: 'done' as const, answer: { status: 'ok' as const, days: 30, commits: 9, files: Array.from({ length: 8 }, (_, i) => ({ path: `src/Hot${i}.php`, commits: 9 - i, dependents: 40 - i, score: (9 - i) * (40 - i), boundary: 'core' })) } }
    const rows = paneLayout(paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(), 0, true, null, null, undefined, null, undefined, { branch: { snapshot: 's9', phase: 'done', answer: answer({ comparison: none }) }, churn }), 200, 60).body
    expect(rows.some(r => r.key.includes('churn'))).toBe(false)
  })
})
