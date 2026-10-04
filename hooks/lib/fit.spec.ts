import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { paneInput, paneLayout, TABS } from './layout'
import { cells, pressLabel, rowWidth } from './rows'
import type { Row } from './rows'

/**
 * Nothing the layout hands the render may draw wider than it was laid out:
 * every row within the pane, and every pressable segment's Button label (with
 * the `k: ` before a hotkey) exactly as wide as the segment's own text. A
 * Button draws its label, not the segment's text: a label longer than the
 * text it stands for wraps or widens the row on a real terminal.
 */
const LONG = 'ProjectModuleIndexWithAnUnreasonablyLongName::_is_python_script_with_a_shebang_line'
const PATH = 'src/a/rather/deeply/nested/directory/with/an/UnreasonablyLongControllerName.php'

const nodes = Array.from({ length: 14 }, (_, i) => ({ name: `${LONG}${i}`, canonical_name: `App\\Core\\${LONG}${i}`, kind: 'method', boundary: i % 2 === 0 ? 'core' : 'module:cli (+composer:app/cli)' }))

export const longDashboard: Dashboard = {
  status: 'ok',
  path: '/work/a-project-with-a-rather-long-directory-name-that-keeps-going',
  project_root: '/work/a-project-with-a-rather-long-directory-name-that-keeps-going',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'stale', age_seconds: 400_000, drift_files: 41 },
  hubs: nodes.slice(0, 8).map((n, i) => ({ ...n, in_degree: 400 - i, out_degree: 120_000 + i, cross_boundary_degree: 9_999, dependent_files: 54_321, top_dependents: [PATH] })),
  hubs_truncated: true,
  hubs_truncation_reasons: ['time_limit'],
  hotspots: [],
  dead_code_candidates: 12_345,
  dead_code_truncated: true,
  cycles: { count: 2, truncated: true, truncation_reasons: ['result_limit'], largest: [{ size: 14, members: nodes.map(n => n.name), nodes, nodes_truncated: false }, { size: 2, members: [LONG, LONG], nodes: nodes.slice(0, 2), nodes_truncated: false }] },
  trend: Array.from({ length: 8 }, (_, i) => ({ snapshot_id: `s${i}`, cycles: i % 3, max_degree: 100 + i })),
  fan_in: [{ path: PATH, dependent_files: 123_456, boundaries: ['core'], boundary: 'module:cli (+composer:app/cli)', top_dependents: [PATH] }],
  fan_in_truncated: true,
  summary: { components: 1_234_567, kinds: [{ kind: 'method', count: 1_000_000 }, { kind: 'class', count: 234_567 }], kinds_truncated: false, files: 9_999, languages: [{ language: 'php', files: 9_000 }, { language: 'typescript', files: 999 }], languages_truncated: false },
  boundaries: { items: [{ name: 'core', source: 'explicit', members: 900_000 }, { name: 'module:cli (+composer:app/cli)', source: 'inferred', members: 300_000 }], truncated: false },
  boundary_matrix: { boundaries: ['core', 'module:cli (+composer:app/cli)'], members: [900_000, 300_000], boundaries_truncated: false, cells: [[10, 123_456], [7, 3]], forbidden: [[0, 1]], edges: 123_476, truncated: false, truncation_reasons: [] },
}

const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'overview', selected: 0, showKeys: true, filter: '', filtering: false, sort: 'in' }

/** Every way a row could draw wider than laid out: past the pane, or a Button label that is not its segment's text. */
function misfits(rows: Row[], columns: number): string[] {
  const out: string[] = []
  for (const row of rows) {
    if (row.code === undefined && rowWidth(row) > columns) out.push(`${row.key}: ${rowWidth(row)} > ${columns}`)
    for (const s of row.segments) {
      if (s.press === undefined || s.hidden === true) continue
      const label = pressLabel(s)
      const drawn = `${s.press.hotkey === undefined ? '' : `${s.press.hotkey}: `}${label}`
      if (cells(drawn) !== cells(s.text)) out.push(`${row.key}: button "${drawn}" for "${s.text}"`)
      // What it shows is the name it presses, or that name cut short with an ellipsis: never another one.
      const cut = label.endsWith('…') && s.press.label.startsWith(label.slice(0, -1))
      if (label.trim() !== s.press.label.trim() && !cut) out.push(`${row.key}: button shows "${label}" for "${s.press.label}"`)
    }
  }
  return out
}

describe('every row draws as laid out', () => {
  it('keeps every tab within the pane, and every Button to its text, at every width and height', () => {
    for (const tab of TABS) {
      for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
        for (const height of [24, 40, 60]) {
          for (const selected of [0, 3, 13]) {
            const input = paneInput(longDashboard, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, { ...VIEW, tab: tab.id, selected }, 0, true)
            const { body, footer } = paneLayout(input, columns, height)
            expect(misfits([...body, ...footer], columns), `${tab.id} ${columns}x${height} @${selected}`).toEqual([])
          }
        }
      }
    }
  })

  it('names a Button by what its segment draws, less the hotkey the engine puts before it', () => {
    expect(pressLabel({ text: 'j: ↓', press: { id: 'down', label: '↓', hotkey: 'j' } })).toBe('↓')
    expect(pressLabel({ text: 'ProjectModuleIndex::modu…', press: { id: 'row:1', label: 'ProjectModuleIndex::module_declares' } })).toBe('ProjectModuleIndex::modu…')
  })
})
