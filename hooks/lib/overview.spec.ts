import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, SessionChanges } from '../../types'
import { cardRows } from './cards'
import { changesInput, lookAtOf, NO_CHANGES } from './changes'
import { listFor, paneInput, paneLayout } from './layout'
import { bucketLabel, compositionBlock, concentrationBlock, flowsBlock, healthBlock, overviewData, overviewList, percent, sessionBlock, shares } from './overview'
import { BOUNDARY_COLOURS, huesOf } from './palette'
import { cells, rowWidth, tierOf } from './rows'
import type { Row } from './rows'
import { plainText, rawText } from './__tests__/plain-text'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const HEIGHTS = [24, 40, 60] as const
const ROOT = '/work/p'

const dash: Dashboard = {
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's6',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [
    { name: 'StableId', canonical_name: 'App\\StableId', kind: 'class', boundary: 'core', in_degree: 516, out_degree: 0, cross_boundary_degree: 0 },
    { name: 'Envelope', canonical_name: 'App\\Envelope', kind: 'class', boundary: 'core', in_degree: 60, out_degree: 1, cross_boundary_degree: 0 },
    { name: 'Helper', canonical_name: 'App\\Helper', kind: 'class', boundary: 'tests', in_degree: 4, out_degree: 1, cross_boundary_degree: 0 },
  ],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 3,
  dead_code_truncated: false,
  cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [] },
  trend: [2, 2, 3, 3, 2, 2].map((cycles, i) => ({ snapshot_id: `s${i + 1}`, cycles, max_degree: 160 + i, dead_code: 30 - i, diagnostics: 0, components: 9_000 + i })),
  fan_in: [],
  fan_in_truncated: false,
  summary: {
    components: 9_186,
    kinds: [
      { kind: 'method', count: 6_197 },
      { kind: 'function', count: 1_164 },
      { kind: 'property', count: 833 },
      { kind: 'class', count: 705 },
      { kind: 'type_alias', count: 125 },
    ],
    kinds_truncated: true,
    files: 694,
    languages: [
      { language: 'php', files: 604 },
      { language: 'javascript', files: 45 },
      { language: 'typescript', files: 29 },
      { language: 'rust', files: 9 },
      { language: 'python', files: 7 },
    ],
    languages_truncated: false,
  },
  boundaries: { items: [], truncated: false },
  boundary_matrix: {
    boundaries: ['tests', 'core', 'hooks', 'types', 'tooling', 'php-worker', 'rust-worker'],
    members: [5_095, 2_196, 539, 47, 78, 207, 288],
    labelled: 9_167,
    boundaries_truncated: true,
    cells: [],
    forbidden: [[1, 0]],
    flows: [
      { from: 0, to: 1, edges: 11_076, forbidden: false },
      { from: 2, to: 3, edges: 188, forbidden: false },
      { from: 1, to: 0, edges: 12, forbidden: true },
    ],
    edges: 32_448,
    truncated: false,
    truncation_reasons: [],
  },
  deltas: { against: 's5', components: 1, cycles: 0, max_degree: 1, dead_code: -1, diagnostics: 0 },
  in_degree: {
    buckets: [
      { from: 0, to: 0, components: 917 },
      { from: 1, to: 5, components: 2_130 },
      { from: 6, to: 20, components: 386 },
      { from: 21, to: 100, components: 183 },
      { from: 101, to: null, components: 26 },
    ],
    truncated: false,
  },
}

const hues = huesOf(dash)
const data = overviewData(dash, hues)
const session: SessionChanges = {
  turns: 1,
  files: { 'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['core'], boundary: 'core' } },
  tests: { 'tests/RouterTest.php': 1 },
  violations: [],
  truncated: false,
}
const changes = changesInput(session, ROOT, hues)
const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'overview', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }

/** The rows a card draws at `columns`, framed as the pane frames it at that width's tier. */
function rowsOf(block: { make: (inner: number, limit: number) => import('./cards').Section | null }, columns: number): Row[] {
  const tier = tierOf(columns)
  const section = block.make(tier === 'narrow' ? columns : columns - 4, 0)
  return section === null ? [] : cardRows(section, columns, tier)
}

const boundaryHues = new Set<string>(BOUNDARY_COLOURS)

describe('the overview data', () => {
  it('reads each dimension as parts of a whole, the largest few named and the rest "other"', () => {
    const [boundaries, languages, kinds] = data.composition
    expect(boundaries!.parts.map(p => [p.label, p.value])).toEqual([
      ['tests', 5_095],
      ['core', 2_196],
      ['hooks', 539],
      ['rust-worker', 288],
      ['php-worker', 207],
      ['other', 9_167 - 5_095 - 2_196 - 539 - 288 - 207],
    ])
    // A boundary keeps its own colour; the rest is faint.
    expect(boundaries!.parts[0]!.colour).toBe(hues.get('tests'))
    expect(boundaries!.parts.at(-1)!.colour).toBe('subtle')
    expect(languages!.parts.map(p => p.label)).toEqual(['PHP', 'JS', 'TS', 'other'])
    expect(languages!.parts.at(-1)!.value).toBe(16)
    // Languages and kinds are one ink, told apart by texture: no colour means two things.
    expect(new Set(languages!.parts.slice(0, 3).map(p => p.colour))).toEqual(new Set(['inactive']))
    expect(languages!.parts.map(p => p.glyph)).toEqual(['█', '▓', '▒', '░'])
    expect(kinds!.parts.map(p => p.label)).toEqual(['method', 'function', 'property', 'other'])
    expect(kinds!.total).toBe(9_186)
  })

  it('takes the buckets, the health figures and the flows as sent, and nothing an older knossos does not send', () => {
    expect(data.buckets.map(bucketLabel)).toEqual(['0', '1–5', '6–20', '21–100', '101+'])
    expect(data.health.map(s => s.label)).toEqual(['cycles', 'unreferenced', 'max degree', 'diagnostics'])
    expect(data.flows.map(f => [f.from, f.to, f.edges, f.forbidden])).toEqual([
      ['tests', 'core', 11_076, false],
      ['hooks', 'types', 188, false],
      ['core', 'tests', 12, true],
    ])
    const old = overviewData({ ...dash, summary: undefined, boundary_matrix: undefined, in_degree: undefined, trend: dash.trend.map(t => ({ snapshot_id: t.snapshot_id, cycles: t.cycles, max_degree: t.max_degree })) }, hues)
    expect(old.composition).toEqual([])
    expect(old.buckets).toEqual([])
    expect(old.flows).toEqual([])
    expect(old.health.map(s => s.label)).toEqual(['cycles', 'max degree'])
    // Malformed buckets are left out rather than drawn wrong.
    expect(overviewData({ ...dash, in_degree: { buckets: [{ from: 'x' } as never], truncated: false } }, hues).buckets).toEqual([])
  })

  it('shares a bar out by value, every part with a cell while there are cells, the whole bar used', () => {
    expect(shares([50, 30, 20], 10)).toEqual([5, 3, 2])
    expect(shares([1000, 1, 1], 10)).toEqual([8, 1, 1])
    expect(shares([0, 0], 10)).toEqual([0, 0])
    for (const width of [4, 17, 60]) expect(shares([5_095, 2_196, 539, 288, 207, 842], width).reduce((a, b) => a + b, 0)).toBe(width)
    expect(percent(1, 1_000)).toBe('<1%')
    expect(percent(0, 10)).toBe('0%')
    expect(percent(5_095, 9_167)).toBe('56%')
  })

  it('walks the way to Changes, then the buckets, then the flows, each opening another tab', () => {
    const list = overviewList(data, changes)
    expect(list.map(o => o.name)).toEqual(['Changes', 'in-degree 0', 'in-degree 1–5', 'in-degree 6–20', 'in-degree 21–100', 'in-degree 101+', 'tests → core', 'hooks → types', 'core → tests'])
    expect(list[0]!.jump).toEqual({ tab: 'changes' })
    expect(list[5]!.jump).toEqual({ tab: 'hubs', degree: { from: 101, to: null } })
    expect(list[6]!.jump).toEqual({ tab: 'boundaries', selected: 0, target: 'core' })
    expect(list.every(o => o.loc === undefined && o.ask !== undefined)).toBe(true)
    expect(overviewList(data, changesInput(NO_CHANGES, ROOT)).map(o => o.name)[0]).toBe('in-degree 0')
  })
})

describe('the overview cards', () => {
  it('fit every width, and keep text in text tones: hue only on swatches and bars', () => {
    const blocks = [compositionBlock(data)!, concentrationBlock(data, 2, 1)!, healthBlock(data)!, flowsBlock(data, hues, 7, 6), sessionBlock(changes, lookAtOf(changes), 0)]
    for (const columns of WIDTHS) {
      for (const block of blocks) {
        const rows = rowsOf(block, columns)
        expect(rows.length, `${block.key} ${columns}`).toBeGreaterThan(0)
        for (const r of rows) {
          expect(rowWidth(r), `${block.key} ${columns} ${r.key}: ${rawText(r)}`).toBeLessThanOrEqual(columns)
          for (const s of r.segments) {
            if (s.color !== undefined && boundaryHues.has(s.color)) expect(s.text, `${block.key} ${columns}: "${s.text}" in ${s.color}`).toMatch(/^[■█▌━╸]+$/)
          }
        }
      }
    }
  })

  it('draws each dimension as one stacked bar with its whole beside it and a legend of shares under it', () => {
    const rows = rowsOf(compositionBlock(data)!, 140)
    const bar = rows.find(r => r.key === 'comp-boundaries')!
    expect(plainText(bar)).toMatch(/^boundaries [█▌]+░+ +9,167 components$/)
    // Two hues side by side keep a sliver of ground: each full-block part ends in a half block.
    expect(bar.segments.filter(s => /^█*▌$/.test(s.text)).length).toBeGreaterThanOrEqual(4)
    const legend = rows.filter(r => r.key.startsWith('comp-boundaries-legend')).map(plainText).join(' ')
    expect(legend).toMatch(/tests +56%/)
    expect(legend).toMatch(/other +9%/)
    // The shares and wholes are figures in the text tone; the labels dim.
    expect(rows.flatMap(r => r.segments).find(s => s.text === '56%')).toMatchObject({ color: 'text' })
    expect(rows.flatMap(r => r.segments).find(s => s.text.trim() === 'tests')).toMatchObject({ dim: true })
    // Narrow, the name and the whole stand above a bar as wide as the card.
    const narrow = rowsOf(compositionBlock(data)!, 40)
    expect(plainText(narrow.find(r => r.key === 'comp-boundaries-head')!)).toMatch(/^boundaries +9,167 components$/)
    expect(rowWidth(narrow.find(r => r.key === 'comp-boundaries')!)).toBe(40)
  })

  it('draws the in-degree histogram in one hue, counts right-aligned, the hubs bucket marked, each bucket a press', () => {
    const rows = rowsOf(concentrationBlock(data, 5, 1)!, 100)
    const top = rows.find(r => r.key === 'degree-4')!
    expect(plainText(top)).toMatch(/^›◆ 101\+ +━*╸?·+ +26 hubs$/)
    expect(top.tint).toBe('userMessageBackground')
    expect(top.segments.find(s => s.press)?.press).toEqual({ id: 'row:5', label: '101+' })
    const bars = rows.flatMap(r => r.segments).filter(s => /^[━╸]+$/.test(s.text))
    expect(new Set(bars.map(s => s.color))).toEqual(new Set(['suggestion']))
    // The counts end in one column.
    const ends = rows.filter(r => /^degree-\d$/.test(r.key)).map(r => rawText(r).search(/\d(?=[^\d,]*$)/))
    expect(new Set(ends).size).toBe(1)
    expect(plainText(rows.find(r => r.key === 'concentration-head')!)).toMatch(/Dependency concentration +3,642 components/)
    expect(rows.some(r => plainText(r).includes('tests and external code left out'))).toBe(true)
  })

  it('draws health as one row per figure on one time axis, each its own scale, and only from five snapshots', () => {
    const rows = rowsOf(healthBlock(data)!, 100)
    const plots = rows.filter(r => /^health-\d$/.test(r.key)).map(r => r.segments.find(s => /^[▁-█]+$/.test(s.text))!)
    expect(plots).toHaveLength(4)
    // The same snapshots, column for column: every row's chart is as wide as the others.
    expect(new Set(plots.map(p => cells(p.text))).size).toBe(1)
    // A figure that moved is drawn in the accent; one that never did lies flat in the faint ink and says so.
    expect(plots[0]!.color).toBe('suggestion')
    expect(plots[3]!.color).toBe('subtle')
    expect(plainText(rows.find(r => r.key === 'health-3')!)).toMatch(/diagnostics +▁+ +0 +no change/)
    expect(plainText(rows.find(r => r.key === 'health-axis')!)).toMatch(/oldest─+newest/)
    expect(healthBlock({ ...data, health: data.health.map(s => ({ ...s, values: s.values.slice(-4) })) })).toBeNull()
  })

  it('marks a forbidden flow with the error colour, a × and the word, never the colour alone', () => {
    const rows = rowsOf(flowsBlock(data, hues, -1, 6), 140)
    const forbidden = rows.find(r => r.key === 'flow-2')!
    expect(plainText(forbidden)).toMatch(/■ core +→ ■ tests +━*╸?·* +12 × forbidden$/)
    expect(forbidden.segments.find(s => s.text === ' × forbidden')?.color).toBe('error')
    expect(forbidden.segments.find(s => /^[━╸]+$/.test(s.text))?.color).toBe('error')
    expect(rows.find(r => r.key === 'flow-0')!.segments.find(s => /^[━╸]+$/.test(s.text))?.color).toBe('suggestion')
    expect(rows.find(r => r.key === 'flow-0')!.segments.find(s => s.press)?.press?.id).toBe('row:6')
    // None to show: one line.
    const none = rowsOf(flowsBlock({ ...data, flows: [] }, hues, -1, 0), 100)
    expect(none).toHaveLength(1)
    expect(plainText(none[0]!)).toMatch(/^Cross-boundary flows +none$/)
  })

  it('sums the session up in a few rows, and says nothing changed in one', () => {
    const rows = rowsOf(sessionBlock(changes, lookAtOf(changes), 0), 100)
    expect(rows.map(plainText).join('\n')).toMatch(/1 file · 41 dependents · 1 test reaches them/)
    const quiet = rowsOf(sessionBlock(changesInput(NO_CHANGES, ROOT), null, -1), 100)
    expect(quiet).toHaveLength(1)
    expect(plainText(quiet[0]!)).toMatch(/^This session +nothing changed yet$/)
    const untested = changesInput({ ...session, tests: {} }, ROOT, hues)
    const warned = rowsOf(sessionBlock(untested, lookAtOf(untested), -1), 100).flatMap(r => r.segments).find(s => s.text.startsWith('▲'))
    expect(warned).toMatchObject({ text: '▲ no test reaches them', color: 'warning' })
  })
})

describe('the overview pane', () => {
  it('lays every card out at every width and height, within the width, on a grid of equal columns when wide', () => {
    for (const columns of WIDTHS) {
      for (const height of HEIGHTS) {
        for (const selected of [0, 3, 8]) {
          const input = paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, { ...VIEW, selected }, 0, true, null, null, session)
          const { body, footer } = paneLayout(input, columns, height)
          for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
          const keys = body.flatMap(r => r.key.split('|'))
          for (const card of ['session-open', 'composition-head', 'concentration-head', 'health-head', 'flows-head']) expect(keys, `${columns}x${height} ${card}`).toContain(card)
          // Wide, the composition and the concentration share a row of the grid.
          expect(body.some(r => r.key.includes('composition-head|concentration-head')), `${columns}`).toBe(columns > 130)
        }
      }
    }
    // The marker's list is the Overview's walk.
    const input = paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, VIEW, 0, true, null, null, session)
    expect(listFor(input)).toHaveLength(1 + 5 + 3)
  })
})
