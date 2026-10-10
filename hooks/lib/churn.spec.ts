import { describe, expect, it } from 'vitest'
import type { Churn, ChurnState, Dashboard, KnossosView } from '../../types'
import { churnInput, churnList, scatterRows } from './churn'
import { parseChurn } from './envelopes'
import { listFor, paneInput, paneLayout } from './layout'
import { plainText, rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'churn', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const file = (i: number) => ({ path: `src/a/rather/deep/directory/Service${i}.php`, commits: Math.max(1, 30 - i), dependents: Math.max(0, 300 - i * 9), score: Math.max(0, 30 - i) * Math.max(0, 300 - i * 9), boundary: i % 2 === 0 ? 'core' : 'tests' })
const answer = (n: number, over: Partial<Churn> = {}): Churn => ({ status: 'ok', days: 30, head: 'abc', commits: 120, truncated: false, files: Array.from({ length: n }, (_, i) => file(i)), ...over })
const state = (a: Churn | null, phase: ChurnState['phase'] = 'done'): ChurnState => ({ head: 'abc', phase, answer: a })
const pane = (s: ChurnState | null, v = view()) => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, undefined, null, undefined, { churn: s })
const text = (s: ChurnState | null, columns = 100, height = 60, v = view()) => paneLayout(pane(s, v), columns, height).body.map(plainText).join('\n')

describe('the Churn tab', () => {
  it('says what it is doing, or why there is nothing to rank', () => {
    expect(text(null)).toContain('Reading the last 30 days of commits…')
    expect(text(state(null, 'done'))).toContain('knossos did not answer')
    expect(text(state({ status: 'no-git', files: [] }))).toContain('▲  No git repository, or git did not answer: there is no history to rank.')
    expect(text(state(answer(0)))).toContain('No file the graph holds changed in the last 30 days.')
  })

  it('says it could not read the history when git printed no log, never an empty window', () => {
    const unread = parseChurn(JSON.stringify({ status: 'unreadable', days: 30, head: 'abc', commits: 0, truncated: false, files: [] }))
    expect(unread?.status).toBe('unreadable')
    const t = text(state(unread))
    expect(t).toContain('▲  Could not read the git history')
    expect(t).not.toContain('No file the graph holds changed')
  })

  it('ranks the files by commits times dependents, the nine highest by their rank, each opening its file', () => {
    const t = text(state(answer(12)))
    expect(t).toMatch(/Churn hotspots · commits × dependents +12 files/)
    expect(t).toMatch(/1 src\/a\/rather\/deep\/directory\/Service0\.php .* 30 +300/)
    expect(t).toMatch(/9 src\/a\/rather\/deep\/directory\/Service8\.php/)
    // The tenth has no rank: only nine are marked on the scatter.
    expect(t).toMatch(/\n {3}src\/a\/rather\/deep\/directory\/Service9\.php/)
    const list = churnList(churnInput(state(answer(3)), ROOT))
    expect(list[0]).toEqual({ name: 'src/a/rather/deep/directory/Service0.php', canonical: 'src/a/rather/deep/directory/Service0.php', loc: { path: `${ROOT}/src/a/rather/deep/directory/Service0.php`, line: null }, file: true })
    expect(listFor(pane(state(answer(3))))).toHaveLength(3)
    const rows = paneLayout(pane(state(answer(3)), view({ selected: 2 })), 100, 60).body
    expect(rows.find(r => r.key.includes('churn-2'))?.segments.find(s => s.press !== undefined)?.press?.id).toBe('row:2')
  })

  it('draws a scatter: commits across, dependents up, ranks as digits and the marked file as the accent dot', () => {
    const files = churnInput(state(answer(12)), ROOT).files
    const rows = scatterRows(files, 60, 9, 4)
    const plot = rows.slice(0, 9).map(rawText)
    // The most commits and the most dependents: the first file stands top right.
    expect(plot[0]).toMatch(/^300 │.*1$/)
    expect(plot.join('')).not.toContain('5')
    expect(rows.slice(0, 9).flatMap(r => r.segments).find(s => s.text === '◉')).toMatchObject({ color: 'suggestion' })
    // The axis from the fewest dependents listed, the commits' ends labelled under it.
    expect(plot[8]).toMatch(/^201 │/)
    expect(rawText(rows[9]!)).toMatch(/^ {4}╰─+$/)
    expect(rawText(rows[10]!)).toMatch(/0 +commits → +30$/)
  })

  // A sweep over every width and height: seconds under coverage, so it gets its own bound rather than the default 5 s.
  it('fits every width and height, empty or full', { timeout: 30_000 }, () => {
    for (const s of [null, state(answer(40)), state(answer(0)), state({ status: 'no-git', files: [] })]) {
      for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
        for (const height of [24, 40, 60]) {
          const { body, footer } = paneLayout(pane(s), columns, height)
          for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
          for (const r of scatterRows(churnInput(s, ROOT).files, Math.max(30, columns - 4), 9, 0)) expect(rowWidth(r), `${columns}`).toBeLessThanOrEqual(Math.max(30, columns - 4))
        }
      }
    }
  })

  it('shows the marked file beside the list on a wide pane', () => {
    const peeked = paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(), 0, true, null, null, undefined, null, undefined, { churn: state(answer(5)) }, { label: 'x', loading: true, messages: null, component: null })
    expect(paneLayout(peeked, 140, 40).body.some(r => r.key.includes('peek-'))).toBe(true)
  })
})
