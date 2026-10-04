/**
 * The hover cards: what a row of the most depended-on components or files
 * shows while the pointer rests on it, without opening it. Its boundary,
 * how many files depend on it, and the three that depend on it most.
 *
 * Pure. A card is drawn whole here, frame and all, on the hover card's own
 * ground ({@link CARD_BG}), every row padded to the card's width: it is
 * painted over the rows below the hovered one, so every cell it covers must
 * be its own. The render hook places it; a surface without a pointer never
 * shows it.
 */
import { FRAME } from './cards'
import { boundaryLabel, CARD_BG, HEADING, NO_HUES } from './palette'
import type { Hues } from './palette'
import { cells, chip, fit, fitStart, grouped, segmentsWidth, spaces } from './rows'
import type { Preview, Row, Segment } from './rows'

/** The widest a card is drawn, frame included, and the narrowest. */
export const CARD_MAX = 52
const CARD_MIN = 24
/** How many of the files that depend on it a card names. */
export const CARD_TOP = 3

/** What a card says about one row: its name, boundary, figures and the files that depend on it most. */
export type CardFacts = { name: string; boundary: string | null; figures: Segment[]; top: string[] }

/** A card's line, padded to `inner` cells and framed, every cell on the card's ground. */
function line(key: string, segments: Segment[], inner: number): Row {
  const fitted: Segment[] = []
  let used = 0
  for (const s of segments) {
    const room = inner - used
    if (room <= 0) break
    const text = cells(s.text) <= room ? s.text : fit(s.text, room)
    fitted.push({ ...s, text })
    used += cells(text)
  }
  const ground = (s: Segment): Segment => ({ ...s, bg: CARD_BG })
  return {
    key,
    segments: [{ text: '│ ', color: FRAME }, ...fitted, { text: spaces(inner - used) + ' ' }, { text: '│', color: FRAME }].map(ground),
  }
}

/**
 * The card for one row, keyed `key`, at most {@link CARD_MAX} cells wide
 * and no wider than `room` (the pane's width): the name in bold with its
 * boundary in its colour, the figures, then each of the files that depend
 * on it most, cut from the front so its file name stays.
 */
export function previewCard(key: string, facts: CardFacts, room: number, hues: Hues = NO_HUES): Preview {
  const label = boundaryLabel(facts.boundary, hues)
  const head: Segment[] = [{ text: facts.name, bold: true, color: HEADING }, ...(label === '' ? [] : [{ text: '  ' }, ...chip(facts.boundary, hues)])]
  const tops = facts.top.slice(0, CARD_TOP)
  const want = Math.max(segmentsWidth(head), segmentsWidth(facts.figures), ...tops.map(t => cells(t) + 2))
  const width = Math.max(Math.min(CARD_MIN, room), Math.min(CARD_MAX, room, want + 4))
  const inner = Math.max(1, width - 4)
  const ground = (s: Segment): Segment => ({ ...s, bg: CARD_BG })
  const rows: Row[] = [
    { key: `${key}-top`, segments: [{ text: `╭${'─'.repeat(Math.max(0, width - 2))}╮`, color: FRAME }].map(ground) },
    line(`${key}-name`, head, inner),
    line(`${key}-figures`, facts.figures, inner),
    ...(tops.length === 0
      ? [line(`${key}-none`, [{ text: 'no other file depends on it', dim: true }], inner)]
      : tops.map((path, i) => line(`${key}-dep-${i}`, [{ text: '← ', dim: true }, { text: fitStart(path, inner - 2) }], inner))),
    { key: `${key}-end`, segments: [{ text: `╰${'─'.repeat(Math.max(0, width - 2))}╯`, color: FRAME }].map(ground) },
  ]
  return { key, rows, width }
}

/** The figures a component's card gives: how many files depend on it, and its in- and out-degree. */
export function componentFigures(files: number | null, inDegree: number, outDegree: number): Segment[] {
  return [
    ...(files === null ? [] : [{ text: grouped(files), color: HEADING }, { text: files === 1 ? ' file depends on it' : ' files depend on it', dim: true }, { text: ' · ', dim: true }]),
    { text: 'in ', dim: true },
    { text: grouped(inDegree), color: HEADING },
    { text: ' · out ', dim: true },
    { text: grouped(outDegree), color: HEADING },
  ]
}

/** The figures a file's card gives: how many files depend on it. */
export function fileFigures(dependents: number): Segment[] {
  return [{ text: grouped(dependents), color: HEADING }, { text: dependents === 1 ? ' file depends on it' : ' files depend on it', dim: true }]
}
