import { describe, expect, it } from 'vitest'
import { BOUNDARY_COLOURS, STATUS_COLOURS, boundaryColour, boundaryLabel } from './palette'

describe('boundaryColour', () => {
  it('is one of the palette, and the same for the same name every time', () => {
    const colour = boundaryColour('core')
    expect(BOUNDARY_COLOURS).toContain(colour)
    expect(boundaryColour('core')).toBe(colour)
  })
  it('spreads different names over the palette', () => {
    const names = ['core', 'tests', 'tooling', 'hooks', 'python-worker', 'rust-worker', 'php-worker', 'typescript-worker', 'Http', 'Domain']
    expect(new Set(names.map(boundaryColour)).size).toBeGreaterThanOrEqual(5)
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
