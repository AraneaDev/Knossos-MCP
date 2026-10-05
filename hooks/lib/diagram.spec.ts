import { describe, expect, it } from 'vitest'
import { ARROWS, boxWidth, canvas, cycleDiagram, edgeLabel, line, neighbourhood, pairColumns, pairDiagram, toRows, write } from './diagram'
import { joinErrors } from './__tests__/joins'
import type { DiagramNode, Hop, Neighbour } from './diagram'
import { rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const

const NAMES = ['ProjectModuleIndex::_add_instances', 'module_declarations', 'read_bounded', 'Index::_add_reexports', 'safe_file', 'adopt_parsed', 'names_python_in_shebang', 'handle', 'scan', '_scan_one', 'shebang_refusal_evidence', 'X', 'ProjectModuleIndex::module_file']
const nodes = (n: number, selected = -1): DiagramNode[] => Array.from({ length: n }, (_, i) => ({ key: `n${i}`, label: NAMES[i % NAMES.length]!, press: { id: `row:${i}`, label: 'x' }, selected: i === selected }))
const hops = (n: number, every = 3): Hop[] => Array.from({ length: n }, (_, i) => ({ crosses: i % every === every - 1 }))
const text = (rows: { segments: { text: string }[] }[]) => rows.map(r => rawText(r as never)).join('\n')

/** Every rule the diagrams keep: no row wider than the width, every connector joined, nothing drawn over anything. */
function clean(layout: { rows: Parameters<typeof rowWidth>[0][]; canvas: ReturnType<typeof canvas> }, width: number, what: string) {
  for (const r of layout.rows) expect(rowWidth(r), `${what}: ${rawText(r)}`).toBeLessThanOrEqual(width)
  expect(joinErrors(layout.canvas), `${what}\n${text(layout.rows)}`).toEqual([])
  expect(layout.canvas.overlaps, `${what}\n${text(layout.rows)}`).toBe(0)
}

describe('the canvas', () => {
  it('reads each connector cell off the directions it opens to, so lines that meet join', () => {
    const c = canvas(7, 3)
    line(c, [[0, 1], [6, 1]])
    line(c, [[3, 0], [3, 2]])
    line(c, [[0, 0], [2, 0], [2, 1]])
    expect(text(toRows(c, 't'))).toBe('──╮│\n──┴┼───\n   │')
  })
  it('ends a line in an arrowhead pointing the way it runs, and sets a label into a line', () => {
    const c = canvas(12, 1)
    edgeLabel(c, 3, 0, '12')
    line(c, [[0, 0], [11, 0]], undefined, true)
    expect(text(toRows(c, 't'))).toBe('─── 12 ────►')
    expect(Object.values(ARROWS)).toEqual(['►', '◄', '▲', '▼'])
  })
  it('counts text written over a line, and a line run through text, as overlaps; a loose end as a join error', () => {
    const c = canvas(6, 1)
    write(c, 0, 0, [{ text: 'abc' }])
    line(c, [[2, 0], [5, 0]])
    expect(c.overlaps).toBe(1)
    const loose = canvas(4, 1)
    line(loose, [[0, 0], [3, 0]], undefined, true)
    // An arrowhead pointing at nothing leads nowhere.
    expect(joinErrors(loose)).not.toEqual([])
  })
  it('draws a box three rows tall, its label cut to fit, a press on the label alone', () => {
    const c = canvas(14, 3)
    const node: DiagramNode = { key: 'a', label: 'ProjectModuleIndex', press: { id: 'row:4', label: 'x' } }
    const rows = toRows(c, 'b')
    expect(rows).toHaveLength(3)
    const drawn = cycleDiagram([node], [{ crosses: false }], 20).rows
    expect(text(drawn)).toMatch(/│ ProjectModul… │/)
    expect(drawn.flatMap(r => r.segments).filter(s => s.press !== undefined).map(s => s.text)).toEqual(['ProjectModul…'])
    expect(boxWidth(5)).toBe(9)
  })
})

describe('the cycle diagram', () => {
  it('lays the members out left to right, then right to left, and closes the loop back to the first', () => {
    const drawn = cycleDiagram(nodes(5).map(n => ({ ...n, label: n.key })), hops(5, 99), 40)
    expect(drawn.perRow).toBe(3)
    expect(text(drawn.rows)).toBe(
      [
        '   ╭────╮         ╭────╮         ╭────╮',
        '╭─►│ n0 ├────────►│ n1 ├────────►│ n2 │',
        '│  ╰────╯         ╰────╯         ╰──┬─╯',
        '│                                   │',
        '│                                   ▼',
        '│                 ╭────╮         ╭────╮',
        '│                 │ n4 │◄────────┤ n3 │',
        '│                 ╰──┬─╯         ╰────╯',
        '╰────────────────────╯',
      ].join('\n'),
    )
  })
  it('marks a hop that crosses a boundary, and draws the marked node and its hops in the accent', () => {
    const drawn = cycleDiagram(nodes(4, 1).map(n => ({ ...n, label: n.key })), [{ crosses: true }, { crosses: false }, { crosses: false }, { crosses: false }], 80)
    expect(text(drawn.rows)).toMatch(/│ n0 ├─+╫─+►│ n1 ├/)
    const segments = drawn.rows.flatMap(r => r.segments)
    expect(segments.find(s => s.text === 'n1')).toMatchObject({ bold: true, bg: 'userMessageBackground' })
    expect(segments.find(s => s.text.includes('╫'))?.color).toBe('warning')
    expect(segments.filter(s => s.color === 'suggestion').length).toBeGreaterThan(2)
  })
  for (const width of WIDTHS) {
    it(`stays clean at ${width} columns, from two members to forty`, () => {
      for (const n of [1, 2, 3, 5, 7, 13, 20, 40]) {
        for (const selected of [-1, 0, n - 1]) clean(cycleDiagram(nodes(n, selected), hops(n), width), width, `${width} cols, ${n} members, marked ${selected}`)
      }
    })
  }
  it('says "back to the start" on the return lane where it has room', () => {
    expect(text(cycleDiagram(nodes(8).map(n => ({ ...n, label: n.key })), hops(8, 99), 140).rows)).toContain('back to the start')
  })
})

describe('two boundaries', () => {
  for (const width of WIDTHS) {
    it(`stay clean at ${width} columns, forbidden or not`, () => {
      for (const forbidden of [false, true]) {
        for (const [a, b] of [['tests', 'core'], ['typescript-worker', 'composer:knossos/very-long-name']]) {
          clean(pairDiagram({ key: 'a', label: a! }, { key: 'b', label: b! }, '10,779 deps', width, forbidden), width, `${width} ${a}→${b} ${forbidden}`)
        }
      }
    })
  }
  it('carry their count on the arrow, red and marked when a policy forbids it', () => {
    const ok = text(pairDiagram({ key: 'a', label: 'tests' }, { key: 'b', label: 'core' }, '812 deps', 60).rows)
    expect(ok.split('\n')[1]).toMatch(/^│ tests ├─+ 812 deps ─+►│ core │$/)
    const no = pairDiagram({ key: 'a', label: 'core' }, { key: 'b', label: 'tests' }, '3 deps', 60, true)
    expect(text(no.rows)).toContain('✕ 3 deps')
  })
  it('list their couplings in columns: the arrows line up, the counts right-aligned, nothing past the width', () => {
    const items = [
      { source: 'ScanTest::scan', target: 'StableId', edges: '812' },
      { source: 'Fixtures', target: 'SqliteConnection::open', edges: '97' },
    ]
    for (const width of WIDTHS) {
      const rows = pairColumns(items, width, {}, {})
      for (const r of rows) expect(rowWidth(r), `${width}`).toBeLessThanOrEqual(width)
      expect(rawText(rows[0]!).indexOf('──►'), `${width}`).toBe(rawText(rows[1]!).indexOf('──►'))
      expect(rowWidth(rows[0]!), `${width}`).toBe(rowWidth(rows[1]!))
    }
  })
})

describe('the neighbourhood', () => {
  const side = (n: number, prefix: string, selected = -1): Neighbour[] =>
    Array.from({ length: n }, (_, i) => ({ node: { key: `${prefix}${i}`, label: `${prefix}${i === 1 ? 'AVeryLongNeighbourName::method' : ''}${i}`, press: { id: `rel:${i}`, label: 'x' }, selected: i === selected }, edge: i === 0 ? '' : String(i * 7) }))
  const empty = { usedBy: 'nothing uses it', uses: 'uses nothing' }
  for (const width of WIDTHS) {
    it(`stays clean at ${width} columns, with either side empty or long`, () => {
      for (const left of [0, 1, 3, 8]) {
        for (const right of [0, 1, 4, 9]) {
          const drawn = neighbourhood({ key: 'c', label: 'DashboardService' }, side(left, 'u', 1), side(right, 'd'), width, empty)
          clean(drawn, width, `${width}: ${left} | ${right}`)
        }
      }
    })
  }
  it('fans in from the left and out to the right when wide, stacked above and below when narrow', () => {
    const wide = neighbourhood({ key: 'c', label: 'Centre' }, side(2, 'u'), side(2, 'd'), 140, empty)
    expect(wide.stacked).toBe(false)
    const lines = text(wide.rows).split('\n')
    const centre = lines.findIndex(l => l.includes('│ Centre ├'))
    expect(lines[centre]).toMatch(/[├┼┤]─►│ Centre ├─[├┤┼]/)
    const narrow = neighbourhood({ key: 'c', label: 'Centre' }, side(2, 'u'), side(2, 'd'), 50, empty)
    expect(narrow.stacked).toBe(true)
    const t = text(narrow.rows)
    expect(t.indexOf('u0')).toBeLessThan(t.indexOf('Centre'))
    expect(t.indexOf('Centre')).toBeLessThan(t.indexOf('d0'))
    expect(t).toContain('▼')
  })
  it('says what a side holds when it holds nothing', () => {
    expect(text(neighbourhood({ key: 'c', label: 'Leaf' }, [], [], 120, empty).rows)).toMatch(/nothing uses it .*│ Leaf │ +uses nothing/)
  })
})
