/**
 * The pane's colours, as Claude Code theme keys. A theme key follows the
 * person's theme (dark, light, daltonized, ANSI), so the pane matches the
 * rest of the screen instead of carrying a palette of its own.
 *
 * Colour carries meaning only: a boundary, a status, the selection. Every
 * other thing is neutral: headings in `text`, secondary text in `inactive`,
 * tracks, rules and empty cells in `subtle`. One accent, `suggestion`, marks
 * the selection, the active tab and the heat map.
 *
 * Kept apart from the layout so every view colours a boundary the same way.
 */
import type { Dashboard } from '../../types'

/** Headings and numbers. */
export const HEADING = 'text'
/** Secondary text: notes, column titles, labels before a value. */
export const SECONDARY = 'inactive'
/** The faintest ink: bar tracks, rules, an empty heat cell. */
export const FAINT = 'subtle'
/** The selection marker, the active tab and the heat map's hue. */
export const ACCENT = 'suggestion'

export const STATUS_COLOURS = { ok: 'success', warn: 'warning', alert: 'error' } as const
export type Tone = keyof typeof STATUS_COLOURS

/**
 * The eight colours Claude Code tells subagents apart by, themed per theme.
 * Blue first, red last: the largest boundaries get the colours least like a
 * status, so a boundary rarely reads as a verdict.
 */
export const BOUNDARY_COLOURS = [
  'blue_FOR_SUBAGENTS_ONLY',
  'purple_FOR_SUBAGENTS_ONLY',
  'cyan_FOR_SUBAGENTS_ONLY',
  'orange_FOR_SUBAGENTS_ONLY',
  'pink_FOR_SUBAGENTS_ONLY',
  'green_FOR_SUBAGENTS_ONLY',
  'yellow_FOR_SUBAGENTS_ONLY',
  'red_FOR_SUBAGENTS_ONLY',
] as const

/** Each boundary's colour by its full name: the project's largest boundaries, largest first. */
export type Hues = ReadonlyMap<string, string>
export const NO_HUES: Hues = new Map()

/**
 * The names of the project's declared boundaries: every one the dashboard
 * names as declared, and those its short list marks so (all an older
 * knossos sends).
 */
export function declaredOf(d: Pick<Dashboard, 'boundaries'>): Set<string> {
  return new Set([...(d.boundaries?.declared ?? []), ...(d.boundaries?.items ?? []).filter(b => b.source === 'explicit').map(b => b.name)])
}

/**
 * The project's boundaries in a stable order, the first eight each given a
 * colour of their own: declared boundaries first (the ones a person named
 * and counts), then inferred ones, each group largest first, ties by name.
 * Sizes come from the boundary list and the dependency matrix, whichever
 * counts more.
 */
export function huesOf(d: Pick<Dashboard, 'boundaries' | 'boundary_matrix'>): Hues {
  const sizes = new Map<string, number>()
  const add = (name: string, members: number) => sizes.set(name, Math.max(members, sizes.get(name) ?? 0))
  d.boundaries?.items.forEach(b => add(b.name, b.members))
  d.boundary_matrix?.boundaries.forEach((name, i) => add(name, d.boundary_matrix?.members[i] ?? 0))
  const declared = declaredOf(d)
  const rank = (name: string) => (declared.has(name) ? 0 : 1)
  const order = [...sizes.entries()].sort((a, b) => rank(a[0]) - rank(b[0]) || b[1] - a[1] || a[0].localeCompare(b[0])).map(([name]) => name)
  return new Map(order.slice(0, BOUNDARY_COLOURS.length).map((name, i) => [name, BOUNDARY_COLOURS[i]!]))
}

/** FNV-1a over the UTF-16 code units: stable across runs and machines. */
function hash(text: string): number {
  let h = 0x811c9dc5
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h
}

/**
 * A boundary's colour, by its full name: its place among the project's
 * largest, else one picked by its name (a boundary past the eighth, or one
 * the dashboard did not list); undefined (drawn neutral) for none.
 */
export function boundaryColour(name: string | null | undefined, hues: Hues = NO_HUES): string | undefined {
  if (name === null || name === undefined || name === '') return undefined
  return hues.get(name) ?? BOUNDARY_COLOURS[hash(name) % BOUNDARY_COLOURS.length]
}

/**
 * A boundary's name as the pane prints it. Inferred boundaries carry their
 * source and any merged siblings (`module:hooks (+typescript:hooks/tsconfig.json)`,
 * `node:@scope/scanner`); the label keeps the part a person recognises
 * (`hooks`, `scanner`). Declared names have neither and print as written.
 */
export function boundaryLabel(name: string | null | undefined): string {
  if (name === null || name === undefined) return ''
  const bare = name.replace(/\s*\(\+.*\)$/, '').replace(/^[a-z][a-z0-9-]*:/, '')
  const last = bare.slice(bare.lastIndexOf('/') + 1)
  return last === '' ? bare : last
}
