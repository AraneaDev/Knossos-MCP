import { GLYPHS } from '../cards'
import type { Row } from '../rows'

/** A card title's kind glyph and the space after it: what a spec reading the title's words leaves out. */
const GLYPH = new RegExp(`^[${[...new Set(Object.values(GLYPHS))].join('')}] `, 'u')

/** The row as the terminal shows it, colours aside, frame and all. */
export const rawText = (row: Row): string => row.segments.map(s => s.text).join('')

/**
 * The row as the terminal shows it, colours aside, with a card's frame taken
 * off: a body row loses its `│ ` and ` │` (and the padding before it), and a
 * card's top edge reads as its title, then its note, the rule's dashes as
 * spaces, without the glyph that marks the card's kind. What a spec compares
 * when it asks what a row says.
 */
export const plainText = (row: Row): string => {
  const text = rawText(row)
  const top = /^(╭─|──) (.*?)( ─╮| ──|─╮|──)$/.exec(text)
  if (top !== null) return top[2]!.replace(GLYPH, '').replace(/ ─+( |$)/, (run, after: string) => ' '.repeat(run.length - after.length) + after).trimEnd()
  if (text.startsWith('│ ') && text.endsWith('│')) return text.slice(2, -1).trimEnd()
  // A marked row is tinted to the card's edge: the spaces that carry the tint say nothing.
  return row.tint === undefined ? text : text.trimEnd()
}

/**
 * The row keyed `key`. In the wide grid a row holds a card's row on each
 * side, keyed `left|right`: the half that is `key`'s is returned, its own
 * segments alone, as a narrower pane would draw it.
 */
export const findRow = (rows: Row[], key: string): Row | undefined => {
  const exact = rows.find(r => r.key === key)
  if (exact !== undefined) return exact
  const merged = rows.find(r => r.key.split('|').includes(key))
  if (merged === undefined || merged.split === undefined) return merged
  const left = merged.key.split('|')[0] === key
  const segments = left ? merged.segments.slice(0, merged.split).filter((s, i, all) => i < all.length - 1 || s.text.trim() !== '') : merged.segments.slice(merged.split)
  return { key, segments }
}
