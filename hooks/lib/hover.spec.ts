import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { CARD_MAX, CARD_TOP, componentFigures, fileFigures, previewCard } from './hover'
import { paneInput, paneRows } from './layout'
import { rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'
import type { Preview } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const HEIGHTS = [24, 40, 60] as const

const facts = { name: 'ResultEnvelope', boundary: 'core', figures: componentFigures(42, 240, 1), top: ['src/Mcp/ToolService.php', 'tests/phpunit/Query/ResultEnvelopeTest.php', 'src/Query/ArchitectureQueryService.php', 'src/Fourth.php'] }

const dash: Dashboard = {
  status: 'ok',
  path: '/work/p',
  project_root: '/work/p',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [{ name: 'ResultEnvelope', canonical_name: 'App\\ResultEnvelope', kind: 'class', boundary: 'core', in_degree: 240, out_degree: 1, cross_boundary_degree: 3, dependent_files: 42, top_dependents: facts.top.slice(0, 3) }],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [{ path: 'src/Query/ResultEnvelope.php', dependent_files: 47, boundaries: ['core'], boundary: 'core', top_dependents: ['src/A.php'] }],
  fan_in_truncated: false,
}

/** The cards the rows hang, by key. */
const cards = (rows: ReturnType<typeof paneRows>): Preview[] => rows.flatMap(r => r.segments.flatMap(s => (s.preview === undefined ? [] : [s.preview])))

describe('hover cards', () => {
  it('name the row in bold with its boundary, its figures, and the three files that depend on it most', () => {
    const card = previewCard('card-hub-0', facts, 120)
    expect(card.rows.map(rawText)).toEqual([
      `╭${'─'.repeat(card.width - 2)}╮`,
      expect.stringMatching(/^│ ResultEnvelope {2}■ core +│$/),
      expect.stringMatching(/^│ 42 files depend on it · in 240 · out 1 +│$/),
      expect.stringMatching(/^│ ← src\/Mcp\/ToolService\.php +│$/),
      expect.stringMatching(/^│ ← tests\/phpunit\/Query\/ResultEnvelopeTest\.php +│$/),
      expect.stringMatching(/^│ ← src\/Query\/ArchitectureQueryService\.php +│$/),
      `╰${'─'.repeat(card.width - 2)}╯`,
    ])
    expect(card.rows).toHaveLength(3 + CARD_TOP + 1)
    expect(card.rows[1]!.segments.find(s => s.text === 'ResultEnvelope')).toMatchObject({ bold: true, color: 'text' })
  })

  it('paint every cell they cover on their own ground, every row as wide as the card', () => {
    for (const room of [20, 30, 44, 60, 120]) {
      const card = previewCard('card', facts, room)
      expect(card.width, `${room}`).toBeLessThanOrEqual(Math.min(CARD_MAX, room))
      for (const r of card.rows) {
        expect(rowWidth(r), `${room} ${r.key}`).toBe(card.width)
        expect(r.segments.every(s => s.bg === 'userMessageBackground'), `${room} ${r.key}`).toBe(true)
      }
    }
    // A path too long for the card keeps its file name: it is cut from the front.
    expect(rawText(previewCard('card', facts, 30).rows[4]!)).toMatch(/│ ← …\/?ResultEnvelopeTest\.php +│$/)
  })

  it('say so when no other file depends on the row, and count files in the singular', () => {
    expect(previewCard('card', { ...facts, top: [] }, 80).rows.map(rawText).join('\n')).toContain('no other file depends on it')
    expect(fileFigures(1).map(s => s.text).join('')).toBe('1 file depends on it')
    expect(componentFigures(null, 3, 2).map(s => s.text).join('')).toBe('in 3 · out 2')
  })

  it('hang off every hub and file row of Hubs at every width and height, and off nothing else: the Overview lists none', () => {
    for (const columns of WIDTHS) {
      for (const height of HEIGHTS) {
        for (const tab of ['overview', 'hubs'] as const) {
          const input = paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, { inspect: null, isBandHidden: false, tab, selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }, 0, true)
          const hung = cards(paneRows(input, columns, height))
          expect(hung.map(c => c.key).sort(), `${columns}x${height} ${tab}`).toEqual(tab === 'hubs' ? ['card-files-0', 'card-hub-0'] : [])
          for (const c of hung) expect(c.width, `${columns}x${height}`).toBeLessThanOrEqual(columns)
        }
        const cycles = paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, { inspect: null, isBandHidden: false, tab: 'cycles', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }, 0, true)
        expect(cards(paneRows(cycles, columns, height))).toEqual([])
      }
    }
  })
})
