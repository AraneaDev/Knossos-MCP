import { DEFAULT_ROWS, paneLayout } from '../layout'
import type { PaneInput, Row } from '../layout'

/**
 * Every row of the pane for `input`, none wider than `columns`: the header,
 * the tab (or the detail) laid out for the width's tier, its lists as long as
 * `height` rows allow, then the footer bar, as the render hook draws them.
 */
export function paneRows(input: PaneInput, columns: number, height: number = DEFAULT_ROWS): Row[] {
  const { body, footer } = paneLayout(input, columns, height)
  return [...body, ...footer]
}
