import type { Canvas } from '../diagram'

/** A connector cell's directions, as the canvas keeps them in a cell's mask. */
const UP = 1
const DOWN = 2
const LEFT = 4
const RIGHT = 8

type Cell = Canvas['grid'][number][number]

const at = (c: Canvas, x: number, y: number): Cell | undefined => c.grid[y]?.[x]

/** Whether a label or mark set into a line passes the line on toward `dir`: it does where the line was drawn through it. */
const throughs = (cell: Cell, dir: number): boolean => (cell.mask & dir) !== 0

/**
 * Every connector end on `c` that leads nowhere, as `x,y`: a side a
 * connector opens to whose neighbour does not open back (or is not an
 * arrowhead entered from there, or a label or mark set into the line), and
 * every arrowhead not entered by a connector or not pointing at a box. The
 * specs hold every diagram to none.
 */
export function joinErrors(c: Canvas): string[] {
  const errors: string[] = []
  const opensTo = (x: number, y: number, dir: number): boolean => {
    const cell = at(c, x, y)
    if (cell === undefined) return false
    if (cell.kind === 'line' || cell.kind === 'mark' || cell.kind === 'edge') return (cell.mask & dir) !== 0 || ((cell.kind === 'edge' || cell.kind === 'mark') && throughs(cell, dir))
    if (cell.kind === 'arrow') return cell.mask === dir
    return false
  }
  const step: Record<number, [number, number, number]> = { [UP]: [0, -1, DOWN], [DOWN]: [0, 1, UP], [LEFT]: [-1, 0, RIGHT], [RIGHT]: [1, 0, LEFT] }
  c.grid.forEach((row, y) =>
    row.forEach((cell, x) => {
      if (cell.kind === 'line' || cell.kind === 'mark' || cell.kind === 'edge') {
        for (const dir of [UP, DOWN, LEFT, RIGHT]) {
          if ((cell.mask & dir) === 0) continue
          const [dx, dy, back] = step[dir]!
          if (!opensTo(x + dx, y + dy, back)) errors.push(`${x},${y}`)
        }
      }
      if (cell.kind === 'arrow' && cell.way !== undefined) {
        const [dx, dy, back] = step[cell.mask]!
        if (!opensTo(x + dx, y + dy, back)) errors.push(`${x},${y}`)
        // The head points at a box's border: a connector cell running across its way.
        const [px, py] = { right: [1, 0], left: [-1, 0], up: [0, -1], down: [0, 1] }[cell.way] as [number, number]
        const target = at(c, x + px, y + py)
        if (target === undefined || target.kind !== 'line') errors.push(`${x},${y}`)
      }
    }),
  )
  return errors
}
