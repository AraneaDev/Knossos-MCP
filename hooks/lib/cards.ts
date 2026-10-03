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

/** One card: its title (and a dim subtitle beside it), a note against the right edge, and its rows. */
export type Section = { key: string; title: string; subtitle?: string; note?: Segment[]; body: Row[] }

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

/** The two columns of the wide grid: the left one, then the right one, {@link GRID_GAP} apart. */
export function gridColumns(width: number): [number, number] {
  const left = Math.floor((width - GRID_GAP) / 2)
  return [left, width - GRID_GAP - left]
}

/** A dim note segment: what most cards say against their right edge. */
export const noteOf = (text: string): Segment[] => (text === '' ? [] : [{ text, dim: true }])

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
 * makes null.
 */
export type Block = { key: string; grow?: { length: number; min: number }; make: (inner: number, limit: number) => Section | null }

/**
 * Cards stacked in `width`, each list as long as `budget` rows allow: every
 * list starts at its minimum, then each in turn takes one more row while the
 * whole still fits. A short pane shows the minimums and scrolls.
 */
export function fitBlocks(blocks: Block[], width: number, tier: Tier, budget: number): Row[] {
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
  let grew = true
  while (grew) {
    grew = false
    for (let i = 0; i < blocks.length; i++) {
      const grow = blocks[i]!.grow
      if (grow === undefined || limits[i]! >= grow.length) continue
      limits[i]!++
      if (total() <= budget) grew = true
      else limits[i]!--
    }
  }
  const sections = blocks.map((b, i) => b.make(inner, limits[i]!)).filter((s): s is Section => s !== null)
  return stackRows(sections, width, tier)
}

/**
 * A tab's cards for every tier. Wide: `top` across the pane, `left` and
 * `right` side by side under it (each column sized to the height on its
 * own), `bottom` across again. Narrower: one column, in `order` when given,
 * else top, left, right, bottom.
 */
export type Arrangement = { top?: Block[]; left: Block[]; right?: Block[]; bottom?: Block[]; order?: Block[] }

/** The rows of `arrangement` at `width`, its lists sized to `budget` rows. */
export function arrange(arrangement: Arrangement, width: number, budget: number): Row[] {
  const tier = tierOf(width)
  const top = arrangement.top ?? []
  const right = arrangement.right ?? []
  const bottom = arrangement.bottom ?? []
  if (tier !== 'wide' || right.length === 0 || arrangement.left.length === 0) {
    return fitBlocks(arrangement.order ?? [...top, ...arrangement.left, ...right, ...bottom], width, tier, budget)
  }
  // Cards across the pane take what they need; the columns share what is left, each on its own.
  const above = fitBlocks(top, width, tier, 0)
  const below = fitBlocks(bottom, width, tier, 0)
  const room = budget - above.length - below.length
  const [lw, rw] = gridColumns(width)
  return [...above, ...besideRows(fitBlocks(arrangement.left, lw, tier, room), fitBlocks(right, rw, tier, room), lw), ...below]
}

/** The rows the pane's body has, from its scroll window; {@link DEFAULT_ROWS} where the surface does not say. */
export const paneHeight = (scroll: { bodyRows: number } | undefined): number => (scroll !== undefined && scroll.bodyRows > 0 ? scroll.bodyRows : DEFAULT_ROWS)
