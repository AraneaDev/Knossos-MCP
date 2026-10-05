import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { paneInput, TABS } from './layout'
import { paneRows } from './__tests__/pane'
import { rawText } from './__tests__/plain-text'

/**
 * No colour emoji, ever: a terminal draws a code point with the emoji
 * presentation (or any pictograph a font may colour, `⚠` among them, or
 * anything followed by the emoji variation selector) as a coloured picture
 * two cells wide, which breaks the grid and the theme. Only text glyphs.
 */
const EMOJI = /\p{Emoji_Presentation}|\p{Extended_Pictographic}|\u{FE0F}/u

/** Each offending code point in `text`, with what it is. */
const offenders = (text: string): string[] =>
  [...text].flatMap((ch, i, all) => (EMOJI.test(ch) || all[i + 1] === '\u{FE0F}' ? [`${ch} U+${ch.codePointAt(0)!.toString(16).toUpperCase()}`] : []))

const HERE = new URL('.', import.meta.url).pathname
const sources = [...readdirSync(HERE).filter(f => f.endsWith('.ts') && !f.endsWith('.spec.ts')).map(f => join(HERE, f)), join(HERE, '..', 'register.tsx')]

const dash: Dashboard = {
  status: 'ok',
  path: '/work/p',
  project_root: '/work/p',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'stale', age_seconds: 4_000, drift_files: 2 },
  hubs: [{ name: 'ResultEnvelope', canonical_name: 'App\\ResultEnvelope', kind: 'class', boundary: 'core', in_degree: 240, out_degree: 1, cross_boundary_degree: 3 }],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 1,
  dead_code_truncated: false,
  cycles: {
    count: 1,
    truncated: false,
    truncation_reasons: [],
    largest: [{ size: 2, members: ['a', 'b'], nodes: [{ name: 'a', canonical_name: 'A::a', kind: 'method', boundary: 'core' }, { name: 'b', canonical_name: 'B::b', kind: 'method', boundary: 'tests' }], nodes_truncated: false }],
  },
  trend: [],
  fan_in: [],
  fan_in_truncated: false,
}

describe('text glyphs only', () => {
  it('finds no emoji anywhere in the mod or its library, comments and strings alike', () => {
    for (const file of sources) expect(offenders(readFileSync(file, 'utf8')), file).toEqual([])
  })
  it('draws no emoji on any tab at any width', () => {
    for (const tab of TABS) {
      for (const columns of [40, 100, 200]) {
        const input = paneInput(dash, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, { inspect: null, isBandHidden: false, tab: tab.id, selected: 0, showKeys: true, filter: '', filtering: false, sort: 'in' }, 0, true)
        expect(offenders(paneRows(input, columns).map(rawText).join('\n')), `${tab.id} ${columns}`).toEqual([])
      }
    }
  })
  it('catches what it is meant to', () => {
    // The selector is flagged and so is the glyph it turns into an emoji.
    expect(offenders('⚠ ▶ ✖ 🙂 ✓\u{FE0F}')).toHaveLength(6)
    expect(offenders('► ◄ ▲ ▼ ✓ ✗ ● ◆ ↻ ╫ !')).toEqual([])
  })
})

describe("the mod's sources", () => {
  // Prettier's rule, kept by hand: `format:check` does not read TypeScript.
  it('hold no two blank lines in a row', () => {
    for (const file of sources) {
      const lines = readFileSync(file, 'utf8').split('\n')
      const doubled = lines.flatMap((line, i) => (i > 0 && line.trim() === '' && lines[i - 1]!.trim() === '' && i < lines.length - 1 ? [i + 1] : []))
      expect(doubled, file).toEqual([])
    }
  })
})
