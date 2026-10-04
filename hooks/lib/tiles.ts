/**
 * The stat tiles across the top of the Overview: one figure per tile with
 * its label under it, the figure in the text colour unless it deviates
 * (cycles and diagnostics in `warning`, policy violations in `error`, drifted
 * files in the accent), how it moved since the previous snapshot beside it
 * (`▲3`), and a sparkline in the accent inside the tile when it has room and
 * the series moves over five points or more.
 *
 * Pure. Medium and wide, the tiles are cells of one framed band, a faint
 * rule between them, spread evenly across the pane; when they do not fit on
 * one line they wrap onto as few as hold them, the same number on each.
 * Narrow, they collapse to a summary line (`9,008 components  2 cycles ...`),
 * wrapped.
 */
import { FRAME } from './cards'
import type { Block, Section } from './cards'
import { ACCENT, HEADING, STATUS_COLOURS } from './palette'
import { cells, grouped, padEnd, spaces, wrapGroups } from './rows'
import type { Row, Segment, Tier } from './rows'
import { sparkline } from './sparkline'

/** How a figure deviates: drawn in that colour; plain when absent. */
export type StatTone = 'warn' | 'alert' | 'accent'

/**
 * One tile: its figure, its label, how it deviates, the series its trend is
 * drawn from, and the press its label carries (the drifted files' list).
 * `delta` is how the figure moved since the previous snapshot; `worse` says
 * the figure is a status (cycles, dead code, diagnostics), so a rise is drawn
 * in its warning colour and a fall in its success colour; a figure that is
 * not a status keeps its delta dim.
 */
export type Stat = { key: string; label: string; value: string; tone?: StatTone; trend?: number[]; press?: string; delta?: number; worse?: 'up' }

/** A trend is drawn only with this many points, and only when it moves. */
export const TREND_MIN_POINTS = 5
/** The most points a tile's sparkline draws, the newest. */
const TREND_MAX_POINTS = 20
/** The rule between two tiles: a space, the rule, a space. */
const DIVIDER = 3

/** Whether a series is worth drawing: long enough, and not flat. */
export const moves = (values: number[] | undefined): values is number[] =>
  values !== undefined && values.length >= TREND_MIN_POINTS && Math.min(...values) !== Math.max(...values)

/** A tile's figure as a segment: bold, in its tone's colour, else the text colour. */
function figure(stat: Stat): Segment {
  const color = stat.tone === undefined ? HEADING : stat.tone === 'accent' ? ACCENT : STATUS_COLOURS[stat.tone]
  return { text: stat.value, bold: true, color }
}

/** A tile's label: dim, or a pressable when it lists something. */
function label(stat: Stat, width: number): Segment {
  const text = padEnd(stat.label, width)
  return stat.press === undefined ? { text, dim: true } : { text: stat.label, press: { id: stat.press, label: stat.label } }
}

/**
 * How a figure moved since the previous snapshot: `▲3`, `▼2`, or `±0`, the
 * glyph saying which way so the colour never has to. Coloured only for a
 * status (see {@link Stat}); empty when the dashboard says nothing.
 */
export function deltaSegment(stat: Pick<Stat, 'delta' | 'worse'>): Segment | null {
  const d = stat.delta
  if (d === undefined) return null
  if (d === 0) return { text: '±0', dim: true }
  const text = `${d > 0 ? '▲' : '▼'}${grouped(Math.abs(d))}`
  if (stat.worse !== 'up') return { text, dim: true }
  return { text, color: d > 0 ? STATUS_COLOURS.warn : STATUS_COLOURS.ok }
}

/** A figure and its delta, a space apart. */
function figureWidth(stat: Stat): number {
  const delta = deltaSegment(stat)
  return cells(stat.value) + (delta === null ? 0 : 1 + cells(delta.text))
}

/** The fewest cells a tile needs: its label, or its figure with its delta. */
const need = (stat: Stat): number => Math.max(cells(stat.label), figureWidth(stat))

/**
 * How many tiles go on each line of a band `inner` wide, and how wide each
 * slot is. All on one line when every tile fits at its own width, the room
 * left shared out evenly; else as few lines as hold them with the same
 * number on each (the last may hold fewer), every slot as wide as the
 * widest tile so the lines' rules stand in columns.
 */
export function tileSlots(stats: Stat[], inner: number): { per: number; widths: number[] } {
  const n = stats.length
  const needs = stats.map(need)
  const own = needs.reduce((a, b) => a + b, 0) + (n - 1) * DIVIDER
  if (own <= inner) return { per: n, widths: share(needs, inner - own) }
  const widest = Math.max(...needs)
  for (let lines = 2; lines <= n; lines++) {
    const per = Math.ceil(n / lines)
    if (per * widest + (per - 1) * DIVIDER <= inner) return { per, widths: share(Array.from({ length: per }, () => widest), inner - per * widest - (per - 1) * DIVIDER) }
  }
  return { per: 1, widths: [Math.max(1, inner)] }
}

/** `widths` with `extra` cells shared out evenly, the first ones taking the remainder. */
function share(widths: number[], extra: number): number[] {
  const each = Math.floor(Math.max(0, extra) / widths.length)
  const rest = Math.max(0, extra) - each * widths.length
  return widths.map((w, i) => w + each + (i < rest ? 1 : 0))
}

/** A tile's figure line: the figure and its delta, then its trend against the slot's right edge when there is room for five points. */
function figureLine(stat: Stat, width: number): Segment[] {
  const delta = deltaSegment(stat)
  const lead: Segment[] = [figure(stat), ...(delta === null ? [] : [{ text: ' ' }, delta])]
  const used = figureWidth(stat)
  const room = width - used - 1
  if (!moves(stat.trend) || room < TREND_MIN_POINTS) return [...lead, { text: spaces(width - used) }]
  const glyphs = sparkline(stat.trend.slice(-Math.min(TREND_MAX_POINTS, room)))
  return [...lead, { text: spaces(width - used - cells(glyphs)) }, { text: glyphs, color: ACCENT }]
}

/** The tiles as one framed band `width` wide: a line of figures and a line of labels per line of tiles. */
function bandRows(stats: Stat[], width: number): Row[] {
  const inner = width - 4
  const { per, widths } = tileSlots(stats, inner)
  const rows: Row[] = [{ key: 'tiles-top', segments: [{ text: `╭${'─'.repeat(Math.max(0, width - 2))}╮`, color: FRAME }] }]
  for (let start = 0, line = 0; start < stats.length; start += per, line++) {
    const tiles = stats.slice(start, start + per)
    if (line > 0) rows.push(framed(`tiles-gap-${line}`, [{ text: spaces(inner) }]))
    const join = (parts: Segment[][]): Segment[] => parts.flatMap((part, i) => (i === 0 ? part : [{ text: ' │ ', color: FRAME }, ...part]))
    const fill = (used: number): Segment[] => (used < inner ? [{ text: spaces(inner - used) }] : [])
    const figures = join(tiles.map((t, i) => figureLine(t, widths[i]!)))
    const labels = join(tiles.map((t, i) => [label(t, widths[i]!), ...(t.press === undefined ? [] : [{ text: spaces(widths[i]! - cells(t.label)) }])]))
    const used = (segments: Segment[]) => segments.reduce((n, s) => n + cells(s.text), 0)
    rows.push(framed(`tiles-${line}-value`, [...figures, ...fill(used(figures))]))
    rows.push(framed(`tiles-${line}-label`, [...labels, ...fill(used(labels))]))
  }
  rows.push({ key: 'tiles-end', segments: [{ text: `╰${'─'.repeat(Math.max(0, width - 2))}╯`, color: FRAME }] })
  return rows
}

/** A band row inside its frame. */
const framed = (key: string, segments: Segment[]): Row => ({ key, segments: [{ text: '│ ', color: FRAME }, ...segments, { text: ' │', color: FRAME }] })

/** The narrow summary line: each figure, then its label, wrapped. */
function lineRows(stats: Stat[], width: number): Row[] {
  const groups = stats
    .map((s): Segment[] => {
      const delta = deltaSegment(s)
      return [figure(s), { text: ' ' }, label(s, cells(s.label)), ...(delta === null ? [] : [{ text: ' ' }, delta]), ...(moves(s.trend) ? [{ text: ` ${sparkline(s.trend.slice(-8))}`, color: ACCENT }] : [])]
    })
  return wrapGroups('tiles-line', groups, width, 3, 0)
}

/** The tiles at `width` for `tier`: the framed band, or the summary line when narrow. */
export function tileRows(stats: Stat[], width: number, tier: Tier): Row[] {
  if (stats.length === 0) return []
  return tier === 'narrow' ? lineRows(stats, width) : bandRows(stats, width)
}

/** The tiles as a block across the pane: drawn bare, since the band is its own frame. Null with no figures to show. */
export function tilesBlock(stats: Stat[], tier: Tier): Block {
  return {
    key: 'tiles',
    // The band frames itself: `inner` is the card's inner width, so the band spans the whole card.
    make: (inner): Section | null => {
      const width = tier === 'narrow' ? inner : inner + 4
      const rows = tileRows(stats, width, tier)
      return rows.length === 0 ? null : { key: 'tiles', title: '', bare: true, body: rows }
    },
  }
}
