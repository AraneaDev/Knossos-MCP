import { describe, expect, it } from 'vitest'
import { ACCENT, BOUNDARY_COLOURS, FAINT, HEADING, SECONDARY, STATUS_COLOURS, boundaryColour, boundaryLabel, huesOf } from './palette'

describe('boundaryColour', () => {
  it('is one of the palette, and the same for the same name every time', () => {
    const colour = boundaryColour('core')
    expect(BOUNDARY_COLOURS).toContain(colour)
    expect(boundaryColour('core')).toBe(colour)
  })
  it('spreads different names over the palette', () => {
    const names = ['core', 'tests', 'tooling', 'hooks', 'python-worker', 'rust-worker', 'php-worker', 'typescript-worker', 'Http', 'Domain']
    expect(new Set(names.map(n => boundaryColour(n))).size).toBeGreaterThanOrEqual(5)
  })
  it('leaves an unassigned component uncoloured', () => {
    expect(boundaryColour(null)).toBeUndefined()
    expect(boundaryColour(undefined)).toBeUndefined()
    expect(boundaryColour('')).toBeUndefined()
  })
  it('never hands a boundary a status colour', () => {
    for (const status of Object.values(STATUS_COLOURS)) expect(BOUNDARY_COLOURS).not.toContain(status)
  })
})

describe('theme keys', () => {
  it('draws every colour as a Claude Code theme key, never a raw colour', () => {
    const keys = [ACCENT, HEADING, SECONDARY, FAINT, ...Object.values(STATUS_COLOURS), ...BOUNDARY_COLOURS]
    for (const key of keys) expect(key).toMatch(/^[a-zA-Z_]+$/)
    expect(STATUS_COLOURS).toEqual({ ok: 'success', warn: 'warning', alert: 'error' })
    expect(BOUNDARY_COLOURS.every(k => k.endsWith('_FOR_SUBAGENTS_ONLY'))).toBe(true)
  })
})

describe('huesOf', () => {
  const d = {
    boundaries: {
      items: [
        { name: 'namespace:App', source: 'inferred', members: 900 },
        { name: 'tests', source: 'explicit', members: 400 },
        { name: 'core', source: 'explicit', members: 600 },
      ],
      truncated: false,
    },
    boundary_matrix: { boundaries: ['core', 'tests', 'module:hooks'], members: [600, 400, 50], boundaries_truncated: false, cells: [], forbidden: [], edges: 0, truncated: false, truncation_reasons: [] },
  }
  it('colours declared boundaries first, each group largest first, in the palette order', () => {
    const hues = huesOf(d)
    expect([...hues.keys()]).toEqual(['core', 'tests', 'namespace:App', 'module:hooks'])
    expect([...hues.values()]).toEqual(BOUNDARY_COLOURS.slice(0, 4))
    expect(boundaryColour('core', hues)).toBe(BOUNDARY_COLOURS[0])
  })
  it('is the same whatever order the dashboard lists them in', () => {
    const shuffled = { ...d, boundaries: { ...d.boundaries, items: [...d.boundaries.items].reverse() } }
    expect([...huesOf(shuffled).entries()]).toEqual([...huesOf(d).entries()])
  })
  it('gives at most eight colours; the rest are picked by name', () => {
    const many = { boundaries: { items: Array.from({ length: 11 }, (_, i) => ({ name: `b${i}`, source: 'explicit', members: 100 - i })), truncated: false } }
    const hues = huesOf(many)
    expect(hues.size).toBe(8)
    expect(new Set(hues.values()).size).toBe(8)
    expect(BOUNDARY_COLOURS).toContain(boundaryColour('b10', hues))
  })
})

describe('boundaryLabel', () => {
  it('prints a declared name as written', () => expect(boundaryLabel('python-worker')).toBe('python-worker'))
  it('drops an inferred source prefix and merged siblings', () => {
    expect(boundaryLabel('module:hooks (+typescript:hooks/tsconfig.json)')).toBe('hooks')
    expect(boundaryLabel('namespace:Knossos')).toBe('Knossos')
  })
  it('keeps the last part of a scoped package', () => {
    expect(boundaryLabel('node:@knossos-mcp/typescript-scanner')).toBe('typescript-scanner')
    expect(boundaryLabel('composer:vendor/package (+node:other)')).toBe('package')
  })
  it('is empty for none', () => {
    expect(boundaryLabel(null)).toBe('')
    expect(boundaryLabel(undefined)).toBe('')
  })
})
