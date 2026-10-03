/**
 * Cards, the two-column grid and lists sized to the pane's height. Pure:
 * sections in, rows out, every row exactly as wide as the card it is in.
 *
 * Every section of the pane is a card, one style per {@link Tier}:
 *
 * - medium and wide: a thin rounded frame in `subtle`, the title set into
 *   its top edge (with the section's note against the right corner), one
 *   cell of padding inside;
 * - narrow: only the top rule with the title in it, no sides and no
 *   padding, so a narrow pane loses no column to the frame.
 *
 * A diff (the engine's own element, which paints its backgrounds to the
 * edge) keeps the top and bottom edges but no sides: a side border would cut
 * through its gutter.
 *
 * Lists grow with the height the pane has: each card that holds one says how
 * long it is and the fewest rows it shows, and {@link fitBlocks} hands out
 * the rows left after the rest, one row at a time to each list in turn. A
 * list cut short shows the rows around the marker and an `n more ↓` line.
 */
import { FAINT, HEADING } from './palette'
import { cells, clip, dimRow, fit, rowWidth, segmentsWidth, spaces, tierOf } from './rows'
import type { Row, Segment, Tier } from './rows'

/**
 * One card: its title (and a dim subtitle beside it), a note against the
 * right edge, and its rows. A `bare` section is drawn without a card: its
 * rows alone (the stat tiles, which draw their own frames).
 */
export type Section = { key: string; title: string; subtitle?: string; note?: Segment[]; body: Row[]; bare?: true }

/** The frame's ink: the faintest theme key, so the cards order the pane without competing with it. */
export const FRAME = FAINT
/** The columns between two cards side by side. */
export const GRID_GAP = 2
/** The fewest cells a title is cut to so that its card's note still shows. */
const TITLE_MIN = 10
/** Lines a pane is assumed to have when its surface does not say. */
export const DEFAULT_ROWS = 40

/** The columns a card `width` wide leaves its rows at `tier`: the frame and its padding take four. */
export const cardInner = (width: number, tier: Tier): number => (tier === 'narrow' ? Math.max(1, width) : Math.max(1, width - 4))

/** The two columns of the wide grid: the left one (`split` of the width, half by default), then the right one, {@link GRID_GAP} apart. */
export function gridColumns(width: number, split = 0.5): [number, number] {
  const left = Math.floor((width - GRID_GAP) * split)
  return [left, width - GRID_GAP - left]
}

/**
 * A note as segments: what most cards say against their right edge. Its
 * figures are drawn at full contrast and the words around them dim, so a
 * note reads by its numbers.
 */
export const noteOf = (text: string): Segment[] => figures(text)

/** `text` split into its figures (`7,878`, `50+`), in the text colour, and the dim words between them. */
export function figures(text: string): Segment[] {
  if (text === '') return []
  return text
    .split(/(\d[\d,]*\+?)/)
    .filter(part => part !== '')
    .map(part => (/^\d/.test(part) ? { text: part, color: HEADING } : { text: part, dim: true }))
}

/**
 * A card's top edge: the title (and the subtitle, while it fits) set into
 * the rule, the note before the right corner. As the width shrinks the
 * subtitle goes, then the note, then the title is cut.
 */
export function topRow(section: Section, width: number, tier: Tier): Row {
  const framed = tier !== 'narrow'
  const lead = framed ? '╭─ ' : '── '
  const close = framed ? '─╮' : '──'
  const note = section.note ?? []
  const build = (subtitle: boolean, withNote: boolean, titleWidth = cells(section.title)): Segment[] | null => {
    const title: Segment[] = [{ text: fit(section.title, titleWidth).replace(/ …$/, '…'), bold: true, color: HEADING }, ...(subtitle && section.subtitle ? [{ text: ` · ${section.subtitle}`, dim: true }] : [])]
    const right: Segment[] = withNote && note.length > 0 ? [{ text: ' ' }, ...note, { text: ` ${close}`, color: FRAME }] : [{ text: close, color: FRAME }]
    const fill = width - cells(lead) - segmentsWidth(title) - 1 - segmentsWidth(right)
    if (fill < 1) return null
    return [{ text: lead, color: FRAME }, ...title, { text: ` ${'─'.repeat(fill)}`, color: FRAME }, ...right]
  }
  // The title may be cut for the note, down to a word's worth: the note is often the figure the card is about.
  const room = width - cells(lead) - 1 - (note.length > 0 ? segmentsWidth(note) + 1 + cells(close) + 2 : 0)
  const cut = room >= Math.min(TITLE_MIN, cells(section.title)) ? build(false, true, room) : null
  const segments = build(true, true) ?? build(false, true) ?? cut ?? build(false, false)
  if (segments !== null) return { key: `${section.key}-head`, segments }
  // Too narrow for the rule: the title alone, cut.
  return { key: `${section.key}-head`, segments: [{ text: fit(section.title, width), bold: true, color: HEADING }] }
}

/** One body row inside the frame: `│ `, the row padded to the inner width, ` │`. */
function framedRow(row: Row, width: number): Row {
  const inner = width - 4
  const fitted = rowWidth(row) <= inner ? row.segments : clip(row.segments, inner)
  return {
    ...row,
    segments: [{ text: '│', color: FRAME }, { text: ' ' }, ...fitted, { text: spaces(inner - segmentsWidth(fitted) + 1) }, { text: '│', color: FRAME }],
  }
}

/** A card's rows at `width`: its top edge, its rows (framed where the tier frames them) and, framed, its bottom edge. */
export function cardRows(section: Section, width: number, tier: Tier): Row[] {
  if (section.bare === true) return section.body.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
  const head = topRow(section, width, tier)
  if (tier === 'narrow') return [head, ...section.body.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))]
  const body = section.body.map(row => (row.code !== undefined ? row : framedRow(row, width)))
  return [head, ...body, { key: `${section.key}-end`, segments: [{ text: `╰${'─'.repeat(Math.max(0, width - 2))}╯`, color: FRAME }] }]
}

/** Cards stacked, each after a blank row. */
export function stackRows(sections: Section[], width: number, tier: Tier): Row[] {
  return sections.flatMap(section => [blankRow(`gap-${section.key}`), ...cardRows(section, width, tier)])
}

const blankRow = (key: string): Row => ({ key, segments: [{ text: ' ' }] })

/** Whether a row holds something a person presses, types in or follows: such a row cannot be drawn as raster cells. */
const interactive = (row: Row): boolean => row.segments.some(s => s.press !== undefined || s.field !== undefined || s.link !== undefined)

/**
 * Two columns of rows side by side: the left padded to `leftWidth`, a gap,
 * then the right; the shorter column ends in blank space. A heat map's
 * raster block stays one only while no row it shares holds a press, a field
 * or a link: those would be lost in a grid of cells, so the block is drawn
 * as text instead.
 */
export function besideRows(left: Row[], right: Row[], leftWidth: number, gap = GRID_GAP): Row[] {
  const out: Row[] = []
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const l = left[i]
    const r = right[i]
    const used = segmentsWidth(l?.segments ?? [])
    const key = `${l?.key ?? `pad-l-${i}`}|${r?.key ?? `pad-r-${i}`}`
    const head = [...(l?.segments ?? []), { text: spaces(leftWidth - used + gap) }].filter(s => s.text !== '')
    const raster = l?.raster ?? r?.raster
    out.push({ key, segments: [...head, ...(r?.segments ?? [])], split: head.length, ...(raster === undefined ? {} : { raster }) })
  }
  const textual = new Set(out.filter(row => row.raster !== undefined && interactive(row)).map(row => row.raster))
  return out.map(row => (row.raster !== undefined && textual.has(row.raster) ? { key: row.key, segments: row.segments, split: row.split } : row))
}

/** The first and one-past-last index of a list `length` long shown `limit` at a time, the marked one (`selected`, local; -1 for none) inside. */
export function windowOf(length: number, limit: number, selected = -1): { start: number; end: number } {
  const n = Math.max(1, limit)
  if (length <= n) return { start: 0, end: length }
  const start = selected < n ? 0 : Math.min(selected - n + 1, length - n)
  return { start, end: start + n }
}

/** The line under a list cut to a window: how many are above and how many more below, or nothing when all show. */
export function moreRows(key: string, window: { start: number; end: number }, length: number, width: number): Row[] {
  const parts = [...(window.start > 0 ? [`${window.start} above ↑`] : []), ...(length > window.end ? [`${length - window.end} more ↓`] : [])]
  return parts.length === 0 ? [] : [dimRow(key, `   ${parts.join(' · ')}`, width)]
}

/**
 * A card the height can size: `make` lays it out at an inner width with a
 * list `limit` rows long (ignored by a card without one); `grow` says how
 * long its list is and the fewest rows it shows. A card with nothing to show
 * makes null. A `late` card (a chart that scales up a step at a time) takes
 * only the rows the lists leave.
 */
export type Block = { key: string; grow?: { length: number; min: number; late?: true }; make: (inner: number, limit: number) => Section | null }

/** How long a list grows before the late cards (the charts) take their rows: past it, lists take the rest. */
export const LIST_SOFT = 10

/**
 * Cards stacked in `width`, each list as long as `budget` rows allow: every
 * list starts at its minimum, then each in turn takes one more row while the
 * whole still fits, up to {@link LIST_SOFT}; then the late cards grow the
 * same way, within `lateBudget` too; then the lists take what is left. A
 * short pane shows the minimums and scrolls.
 */
export function fitBlocks(blocks: Block[], width: number, tier: Tier, budget: number, lateBudget = budget): Row[] {
  const inner = cardInner(width, tier)
  const limits = blocks.map(b => (b.grow === undefined ? 0 : Math.min(b.grow.min, b.grow.length)))
  const heights = new Map<string, number>()
  const heightOf = (i: number): number => {
    const id = `${i}:${limits[i]}`
    let h = heights.get(id)
    if (h === undefined) {
      const section = blocks[i]!.make(inner, limits[i]!)
      h = section === null ? 0 : cardRows(section, width, tier).length + 1
      heights.set(id, h)
    }
    return h
  }
  const total = () => blocks.reduce((n, _, i) => n + heightOf(i), 0)
  const phases: { late: boolean; cap: number; within: number }[] = [
    { late: false, cap: LIST_SOFT, within: budget },
    { late: true, cap: Number.POSITIVE_INFINITY, within: Math.min(budget, lateBudget) },
    { late: false, cap: Number.POSITIVE_INFINITY, within: budget },
  ]
  for (const phase of phases) {
    let grew = true
    while (grew) {
      grew = false
      for (let i = 0; i < blocks.length; i++) {
        const grow = blocks[i]!.grow
        if (grow === undefined || (grow.late === true) !== phase.late || limits[i]! >= Math.min(grow.length, phase.cap)) continue
        limits[i]!++
        if (total() <= phase.within) grew = true
        else limits[i]!--
      }
    }
  }
  const sections = blocks.map((b, i) => b.make(inner, limits[i]!)).filter((s): s is Section => s !== null)
  return stackRows(sections, width, tier)
}

/**
 * A tab's cards for every tier. Wide: `top` across the pane, `left` and
 * `right` side by side under it (each column sized to the height on its
 * own), `bottom` across again; each `float` card goes under whichever
 * column leaves the two closest in height; `split` is the left column's
 * share of the width (half when absent), for a tab whose wider table is on
 * one side. Narrower: one column, in `order` when given, else top, left,
 * right, float, bottom.
 */
export type Arrangement = { top?: Block[]; left: Block[]; right?: Block[]; float?: Block[]; bottom?: Block[]; order?: Block[]; split?: number }

/** The most floating cards balanced by trying every way to place them: two to the power of this many layouts. */
const FLOAT_MAX = 4

/** How many rows a column's charts may grow past the other column's end before they stop: a chart never leaves its neighbour short. */
export const BALANCE_SLACK = 3

/**
 * Two columns sized on their own, then evened: a column taller than the
 * other by more than {@link BALANCE_SLACK} rows is laid out again with its
 * charts held to the other's height (its lists keep their rows: those are
 * data, not size).
 */
function sizedPair(left: Block[], right: Block[], widths: [number, number], tier: Tier, room: number): [Row[], Row[]] {
  let l = fitBlocks(left, widths[0], tier, room)
  let r = fitBlocks(right, widths[1], tier, room)
  if (l.length > r.length + BALANCE_SLACK) l = fitBlocks(left, widths[0], tier, room, r.length + BALANCE_SLACK)
  else if (r.length > l.length + BALANCE_SLACK) r = fitBlocks(right, widths[1], tier, room, l.length + BALANCE_SLACK)
  return [l, r]
}

/**
 * The two columns of the wide grid with the floating cards placed: of every
 * way to put each under the left or the right column, the one whose columns
 * end closest in height (the first such, so a tie keeps a card left).
 */
export function balanceColumns(left: Block[], right: Block[], float: Block[], widths: [number, number], tier: Tier, room: number): [Row[], Row[]] {
  const floating = float.slice(0, FLOAT_MAX)
  const rest = float.slice(FLOAT_MAX)
  let best: [Row[], Row[]] | null = null
  for (let mask = 0; mask < 1 << floating.length; mask++) {
    const l = [...left, ...floating.filter((_, i) => (mask & (1 << i)) === 0)]
    const r = [...right, ...floating.filter((_, i) => (mask & (1 << i)) !== 0), ...rest]
    const rows = sizedPair(l, r, widths, tier, room)
    if (best === null || Math.abs(rows[0].length - rows[1].length) < Math.abs(best[0].length - best[1].length)) best = rows
  }
  return best!
}

/** The rows of `arrangement` at `width`, its lists sized to `budget` rows. */
export function arrange(arrangement: Arrangement, width: number, budget: number): Row[] {
  const tier = tierOf(width)
  const top = arrangement.top ?? []
  const right = arrangement.right ?? []
  const float = arrangement.float ?? []
  const bottom = arrangement.bottom ?? []
  // Two columns need two cards to stand side by side: a floating card can fill either column.
  const sides = arrangement.left.length + right.length + float.length
  if (tier !== 'wide' || sides < 2 || right.length + float.length === 0 || arrangement.left.length + float.length === 0) {
    return fitBlocks(arrangement.order ?? [...top, ...arrangement.left, ...right, ...float, ...bottom], width, tier, budget)
  }
  // Cards across the pane take what they need; the columns share what is left, each on its own.
  const above = fitBlocks(top, width, tier, 0)
  const below = fitBlocks(bottom, width, tier, 0)
  const room = budget - above.length - below.length
  const widths = gridColumns(width, arrangement.split)
  const [l, r] = balanceColumns(arrangement.left, right, float, widths, tier, room)
  return [...above, ...besideRows(l, r, widths[0]), ...below]
}

/** The rows the pane's body has, from its scroll window; {@link DEFAULT_ROWS} where the surface does not say. */
export const paneHeight = (scroll: { bodyRows: number } | undefined): number => (scroll !== undefined && scroll.bodyRows > 0 ? scroll.bodyRows : DEFAULT_ROWS)
