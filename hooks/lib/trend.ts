/**
 * The Overview's trend chart: how a figure moved over the last snapshots, as
 * columns of block glyphs several rows tall, one column per snapshot, oldest
 * left. Drawn only for a series of at least five points that moves, and only
 * when the pane has rows left over after its lists: otherwise the stat
 * tiles' sparklines say it in one line.
 *
 * Pure. The chart's rows carry `raster: 'trend'`, so the terminal draws them
 * as one `Raster` of cells in the accent; every surface can draw the same
 * glyphs as text. Each series is scaled to its own range, and its axis says
 * that range: the highest figure against the top row, the lowest against
 * the bottom one, so a small change never reads as a large one unlabelled.
 */
import { noteOf } from './cards'
import type { Block, Section } from './cards'
import { ACCENT, FAINT } from './palette'
import { cells, padEnd, padStart, spaces } from './rows'
import type { Row, Segment } from './rows'
import { moves } from './tiles'

/** One series the chart may draw: its label and its points, oldest first. */
export type Series = { label: string; values: number[] }

/** Eighths of a cell, from one eighth to a whole. */
const LEVELS = '▁▂▃▄▅▆▇█'
/** The fewest and most rows one series' chart is tall. */
export const CHART_MIN_ROWS = 3
const CHART_MAX_ROWS = 8
/** The most cells one snapshot's column takes: past it the chart stops widening. */
const COLUMN_MAX = 3
/** The indent, as the cards' lists have. */
const INDENT = 3

/** The glyph for the part of a column `eighths` tall that falls in a cell row `fromBottom` up: blank, a part, or whole. */
function glyphAt(eighths: number, fromBottom: number): string {
  const left = eighths - fromBottom * 8
  if (left <= 0) return ' '
  return left >= 8 ? '█' : LEVELS.charAt(left - 1)
}

/**
 * One series as rows `height` tall in `columns`: its label over the axis on
 * the first row, its highest figure against the top row and its lowest
 * against the bottom, then a column of glyphs per point. Null when the
 * series does not move or there is no room for five points.
 */
export function chartRows(series: Series, columns: number, height: number, key: string): Row[] | null {
  if (!moves(series.values)) return null
  const min = Math.min(...series.values)
  const max = Math.max(...series.values)
  const axis = Math.max(cells(String(min)), cells(String(max)))
  const labelWidth = cells(series.label)
  const plot = columns - INDENT - Math.max(labelWidth, axis) - 2
  // As many of the newest points as fit at one cell each; a column widens (with a gap) while all of them fit.
  const points = series.values.slice(-Math.max(0, plot))
  if (points.length < 5) return null
  const width = Math.max(1, Math.min(COLUMN_MAX, Math.floor(plot / points.length)))
  const tall = Math.max(CHART_MIN_ROWS, Math.min(CHART_MAX_ROWS, height))
  // The lowest point keeps an eighth, so every snapshot shows.
  const eighths = points.map(v => 1 + Math.round(((v - min) / (max - min)) * (tall * 8 - 1)))
  const gutter = Math.max(labelWidth, axis)
  const rows: Row[] = []
  for (let r = 0; r < tall; r++) {
    const fromBottom = tall - 1 - r
    const side = r === 0 ? String(max) : r === tall - 1 ? String(min) : ''
    const tick = r === 0 || r === tall - 1 ? '┤' : '│'
    const segments: Segment[] = [{ text: spaces(INDENT) }, { text: padStart(side, gutter), dim: true }, { text: ` ${tick}`, color: FAINT }]
    for (const e of eighths) {
      const glyph = glyphAt(e, fromBottom)
      const run = width > 1 ? width - 1 : 1
      segments.push({ text: glyph.repeat(run), color: ACCENT }, ...(width > 1 ? [{ text: ' ' }] : []))
    }
    rows.push({ key: `${key}-${r}`, raster: 'trend', segments })
  }
  // The label under the axis: what the series is.
  rows.push({ key: `${key}-label`, raster: 'trend', segments: [{ text: spaces(INDENT) }, { text: padEnd(series.label, gutter + 2), dim: true }] })
  return rows
}

/**
 * The trend chart as a block that grows late: with no rows to spare it draws
 * nothing (the tiles' sparklines stand in); each row the lists leave makes
 * every series' chart a row taller, from {@link CHART_MIN_ROWS}.
 */
export function trendBlock(series: Series[]): Block | null {
  const moving = series.filter(s => moves(s.values))
  if (moving.length === 0) return null
  const scans = Math.max(...moving.map(s => s.values.length))
  return {
    key: 'trend',
    grow: { length: CHART_MAX_ROWS - CHART_MIN_ROWS + 1, min: 0, late: true },
    make: (columns, limit): Section | null => {
      if (limit <= 0) return null
      const charts = moving.map((s, i) => chartRows(s, columns, CHART_MIN_ROWS + limit - 1, `trend-${i}`)).filter((rows): rows is Row[] => rows !== null)
      if (charts.length === 0) return null
      const body = charts.flatMap((rows, i) => (i === 0 ? rows : [{ key: `trend-gap-${i}`, segments: [{ text: ' ' }] }, ...rows]))
      return { key: 'trend', title: 'Trend', note: noteOf(`${scans} scans`), body }
    },
  }
}
