/**
 * The pane's colours: one fixed palette for boundaries, three status colours,
 * and an accent for the selection. Kept apart from the layout so every view
 * colours a boundary the same way.
 */

/**
 * Eight colours a dark or light terminal tells apart, none of them the
 * status red, yellow or green, so a boundary never reads as a verdict.
 */
export const BOUNDARY_COLOURS = [
  '#7aa2f7',
  '#bb9af7',
  '#7dcfff',
  '#e0af68',
  '#73daca',
  '#ff9e64',
  '#ff79c6',
  '#a9b665',
] as const

export const STATUS_COLOURS = { ok: 'green', warn: 'yellow', alert: 'red' } as const
export type Tone = keyof typeof STATUS_COLOURS

/** The selection marker and the active tab. */
export const ACCENT = '#7dcfff'

/** FNV-1a over the UTF-16 code units: stable across runs and machines. */
function hash(text: string): number {
  let h = 0x811c9dc5
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h
}

/** A boundary's colour, by its full name; undefined (drawn dim) for none. */
export function boundaryColour(name: string | null | undefined): string | undefined {
  if (name === null || name === undefined || name === '') return undefined
  return BOUNDARY_COLOURS[hash(name) % BOUNDARY_COLOURS.length]
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
