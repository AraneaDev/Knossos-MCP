import type { Row } from '../rows'

/** The row as the terminal shows it, colours aside: what a spec compares. */
export const plainText = (row: Row): string => row.segments.map(s => s.text).join('')
