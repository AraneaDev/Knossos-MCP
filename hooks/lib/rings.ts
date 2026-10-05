/**
 * A component's blast radius, as the detail draws it: what depends on it
 * one hop away, two hops away, and further (`knossos blast-radius`), each
 * ring with how many of its components a test reaches and the test files
 * that do.
 *
 * Pure. Drawn as concentric frames, the furthest ring outermost and the
 * component in the middle, so the eye reads outward from what changes to
 * what it reaches: each ring's frame carries its hop, its count and how
 * much of it is tested; inside the frame, before the next ring in, its
 * components (the untested first, marked `▲` in the warning colour; a
 * boundary named only where it is not the component's own) and the test
 * files that reach it. The frames grow fainter outwards. Every named
 * component is a row the marker walks and `o` opens. Below
 * {@link RINGS_MIN} columns the rings are drawn as plain lists.
 */
import type { BlastRadius, RingsState } from '../../types'
import { noteOf } from './cards'
import type { Block } from './cards'
import { ACCENT, FAINT, HEADING, NO_HUES, SECONDARY, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { absolute, baseName, button, cells, chip, dimRow, displayName, fit, grouped, plural, segmentsWidth, spaces, wrapWords } from './rows'
import type { Loc, Row, Segment } from './rows'

/** One component a ring names: what it shows, what it opens, and whether a test reaches it. */
export type RingMember = { name: string; canonical: string; boundary: string | null; tested: boolean; loc: Loc | null }

/** One ring as the detail draws it. */
export type Ring = { label: string; count: number; tested: number; members: RingMember[]; tests: { count: number; paths: string[] } }

/** The blast radius the detail draws: loading, what to say instead, or the rings. */
export type RingsInput = { loading: boolean; said: string | null; centre: { name: string; boundary: string | null } | null; rings: Ring[]; truncated: boolean }

/** The narrowest the rings are drawn as frames: each ring takes four columns of the width. */
export const RINGS_MIN = 44

/** The rows of components each ring names at most. */
const NAME_LINES = 2

/** The ring's name by its hop: the last one gathers everything further. */
const ringLabel = (hop: number): string => (hop >= 3 ? '3+ hops' : hop === 1 ? '1 hop' : `${hop} hops`)

/** The detail's view of the stored rings, for the component it shows (`name`); `root` places each on disk. */
export function ringsInput(state: RingsState | null, name: string, root: string | null): RingsInput {
  const none = { centre: null, rings: [], truncated: false }
  if (state === null || state.name !== name || (state.phase === 'loading' && state.answer === null)) return { loading: true, said: 'Reading what depends on it, hop by hop…', ...none }
  const answer: BlastRadius | null = state.answer
  if (answer === null || answer.status !== 'ok' || answer.component === null) return { loading: false, said: answer?.status === 'not-found' ? 'knossos found no component by that name to measure.' : 'knossos did not answer; the detail asks again with the next snapshot.', ...none }
  return {
    loading: state.phase === 'loading',
    said: null,
    centre: { name: displayName(answer.component), boundary: answer.component.boundary },
    rings: answer.rings.map(r => ({
      label: ringLabel(r.hop),
      count: r.count,
      tested: r.tested,
      members: r.items.map(i => ({ name: displayName(i), canonical: i.canonical_name, boundary: i.boundary, tested: i.tested, loc: root === null || i.path === null || i.path === undefined ? null : { path: absolute(root, i.path), line: i.line ?? null } })),
      tests: { count: r.tests.count, paths: r.tests.items.map(t => t.path) },
    })),
    truncated: answer.truncated,
  }
}

/** The components the rings name, nearest ring first: what the marker walks after the detail's own lists (rows the detail opens). */
export const ringsList = (input: RingsInput | null | undefined): { name: string; canonical: string; loc: Loc | null }[] => (input === null || input === undefined ? [] : input.rings.flatMap(r => r.members.map(m => ({ name: m.name, canonical: m.canonical, loc: m.loc }))))

/** A ring's frame colour: the nearest in a text tone, the further ones faint. */
const frameColour = (index: number): string => (index === 0 ? SECONDARY : FAINT)

/** How a ring stands: all tested, how many are not, or empty. */
function standing(ring: Ring): Segment[] {
  if (ring.count === 0) return [{ text: 'none', dim: true }]
  const untested = ring.count - ring.tested
  return untested === 0 ? [{ text: '✓ all tested', color: STATUS_COLOURS.ok }] : [{ text: `▲ ${grouped(untested)} untested`, color: STATUS_COLOURS.warn }]
}

/**
 * A ring's components as runs of names `width` wide, at most
 * {@link NAME_LINES} of them, then `+N more`: each name a press that opens
 * it (`rel:N`, from `offset`), the marked one tinted, an untested one
 * after a `▲`.
 */
function memberLines(ring: Ring, offset: number, width: number, selected: number, hues: Hues, home: string | null): Segment[][] {
  const lines: Segment[][] = [[]]
  let used = 0
  for (const [i, m] of ring.members.entries()) {
    const index = offset + i
    const mark: Segment[] = m.tested ? [] : [{ text: '▲', color: STATUS_COLOURS.warn }]
    // A boundary is named only where it is not the component's own: what crosses stands out.
    const own = m.boundary === home ? [] : chip(m.boundary, hues)
    const name: Segment = { ...button(`rel:${index}`, fit(m.name, Math.max(4, width - 6))), ...(index === selected ? { bg: SELECTED_BG, bold: true } : {}) }
    const piece: Segment[] = [...mark, name, ...(own.length > 0 && cells(m.name) < 24 ? [{ text: ' ' }, ...own] : [])]
    const need = segmentsWidth(piece) + (used === 0 ? 0 : 3)
    if (used > 0 && used + need > width) {
      if (lines.length === NAME_LINES) break
      lines.push([])
      used = 0
    }
    lines[lines.length - 1]!.push(...(used === 0 ? [] : [{ text: '   ' }]), ...piece)
    used += used === 0 ? segmentsWidth(piece) : need
  }
  const shown = lines.flat().filter(s => s.press !== undefined).length
  const more = ring.count - shown
  if (more > 0) {
    const text = `+${grouped(more)} more`
    const last = lines[lines.length - 1]!
    if (segmentsWidth(last) + 3 + cells(text) <= width) last.push({ text: '   ' }, { text, dim: true })
    else if (lines.length < NAME_LINES + 1) lines.push([{ text, dim: true }])
  }
  return lines.filter(l => l.length > 0)
}

/** The test files that reach a ring, by file name, as many as fit one line `width` wide, then how many more. */
function testsLine(ring: Ring, width: number): Segment[] {
  if (ring.count === 0) return []
  if (ring.tests.count === 0) return [{ text: '✗ no test file reaches it', color: STATUS_COLOURS.warn }]
  const lead = `✓ ${plural(ring.tests.count, 'test file', 'test files')}`
  const said = (shown: string[]): string => {
    const rest = ring.tests.count - shown.length
    return shown.length === 0 ? lead : `${lead}: ${shown.join(', ')}${rest > 0 ? ` +${rest}` : ''}`
  }
  let shown: string[] = []
  for (const name of ring.tests.paths.map(baseName)) {
    if (cells(said([...shown, name])) > width) break
    shown = [...shown, name]
  }
  return [{ text: fit(said(shown), width), dim: true }]
}

/**
 * The rings as nested frames in `columns`: the furthest ring outermost,
 * each frame two cells in from the one around it, its title in its top
 * edge, its components and tests before the next frame, the component
 * itself in the middle.
 */
export function ringRows(input: RingsInput, columns: number, selected: number, offset: number, hues: Hues = NO_HUES): Row[] {
  const rings = input.rings
  const outward = [...rings].reverse()
  const n = outward.length
  const offsets = rings.map((_, i) => offset + rings.slice(0, i).reduce((sum, r) => sum + r.members.length, 0))
  const rows: Row[] = []
  // A line inside `depth` frames: their left sides, the content, padding, their right sides.
  const inside = (key: string, depth: number, content: Segment[]): Row => {
    const left: Segment[] = []
    const right: Segment[] = []
    for (let d = 0; d < depth; d++) {
      const colour = frameColour(n - 1 - d)
      left.push({ text: '│ ', color: colour })
      right.unshift({ text: ' │', color: colour })
    }
    const room = columns - 4 * depth
    const used = segmentsWidth(content)
    return { key, segments: [...left, ...content, { text: spaces(room - used) }, ...right] }
  }
  outward.forEach((ring, d) => {
    const index = n - 1 - d
    const width = columns - 4 * d
    const colour = frameColour(index)
    const title: Segment[] = [{ text: ring.label, bold: true, color: index === 0 ? HEADING : SECONDARY }, { text: ` · ${grouped(ring.count)}`, color: HEADING }]
    const state = standing(ring)
    const fill = Math.max(1, width - 8 - segmentsWidth(title) - segmentsWidth(state))
    const top: Segment[] = [{ text: '╭─ ', color: colour }, ...title, { text: ` ${'─'.repeat(fill)} `, color: colour }, ...state, { text: ' ─╮', color: colour }]
    const left: Segment[] = []
    const right: Segment[] = []
    for (let o = 0; o < d; o++) {
      left.push({ text: '│ ', color: frameColour(n - 1 - o) })
      right.unshift({ text: ' │', color: frameColour(n - 1 - o) })
    }
    rows.push({ key: `ring-${index}-top`, segments: segmentsWidth(top) <= width ? [...left, ...top, ...right] : [...left, { text: `╭${'─'.repeat(Math.max(0, width - 2))}╮`, color: colour }, ...right] })
    const room = width - 4
    memberLines(ring, offsets[index]!, room, selected, hues, input.centre?.boundary ?? null).forEach((line, i) => rows.push(inside(`ring-${index}-names-${i}`, d + 1, line)))
    const tests = testsLine(ring, room)
    if (tests.length > 0) rows.push(inside(`ring-${index}-tests`, d + 1, tests))
  })
  // The component itself, in the middle of the innermost ring.
  const centre = input.centre
  if (centre !== null) {
    const room = columns - 4 * n
    const own = chip(centre.boundary, hues)
    const label: Segment[] = [{ text: '◉ ', color: ACCENT }, { text: fit(centre.name, Math.max(4, room - segmentsWidth(own) - 4)), bold: true, color: HEADING }, ...(own.length > 0 ? [{ text: ' ' }, ...own] : [])]
    const pad = Math.max(0, Math.floor((room - segmentsWidth(label)) / 2))
    rows.push(inside('ring-centre', n, [{ text: spaces(pad) }, ...label]))
  }
  for (let d = n - 1; d >= 0; d--) {
    const left: Segment[] = []
    const right: Segment[] = []
    for (let o = 0; o < d; o++) {
      left.push({ text: '│ ', color: frameColour(n - 1 - o) })
      right.unshift({ text: ' │', color: frameColour(n - 1 - o) })
    }
    rows.push({ key: `ring-${n - 1 - d}-end`, segments: [...left, { text: `╰${'─'.repeat(Math.max(0, columns - 4 * d - 2))}╯`, color: frameColour(n - 1 - d) }, ...right] })
  }
  return rows
}

/** The rings as plain lists, for a pane too narrow for frames: each ring's line, its components, its tests. */
function ringLists(input: RingsInput, columns: number, selected: number, offset: number, hues: Hues): Row[] {
  const rows: Row[] = []
  let at = offset
  input.rings.forEach((ring, index) => {
    rows.push({ key: `ring-${index}-head`, segments: [{ text: ring.label, bold: true, color: HEADING }, { text: ` · ${grouped(ring.count)}  `, dim: true }, ...standing(ring)] })
    memberLines(ring, at, Math.max(1, columns - 2), selected, hues, input.centre?.boundary ?? null).forEach((line, i) => rows.push({ key: `ring-${index}-names-${i}`, segments: [{ text: '  ' }, ...line] }))
    const tests = testsLine(ring, Math.max(1, columns - 2))
    if (tests.length > 0) rows.push({ key: `ring-${index}-tests`, segments: [{ text: '  ' }, ...tests] })
    at += ring.members.length
  })
  return rows
}

/**
 * The blast radius as a detail card: the rings (`offset` is the walk's
 * index of the first component they name), or what to say while they load
 * or when there are none.
 */
export function ringsBlock(input: RingsInput, selected: number, offset: number, hues: Hues = NO_HUES): Block {
  const total = input.rings.reduce((sum, r) => sum + r.count, 0)
  return {
    key: 'rings',
    make: columns => {
      const note = noteOf(input.said !== null ? '' : `${grouped(total)}${input.truncated ? '+' : ''} dependents, tests apart`)
      if (input.said !== null) return { key: 'rings', title: 'Blast radius', note, body: wrapWords(input.said, Math.max(1, columns - 3)).map((line, i) => dimRow(`rings-said-${i}`, `   ${line}`, columns)) }
      if (total === 0) return { key: 'rings', title: 'Blast radius', note, body: [dimRow('rings-none', '   Nothing depends on it: a change here reaches no other component.', columns)] }
      const body = columns >= RINGS_MIN ? ringRows(input, columns, selected, offset, hues) : ringLists(input, columns, selected, offset, hues)
      return { key: 'rings', title: 'Blast radius', subtitle: input.loading ? 'reading…' : 'what a change reaches, ring by ring', note, body }
    },
  }
}
