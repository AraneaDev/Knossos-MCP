import { describe, expect, it } from 'vitest'
import { sparkline } from './sparkline'

describe('sparkline', () => {
  it('scales to eight levels', () => expect(sparkline([0, 7])).toBe('▁█'))
  it('a flat series is flat', () => expect(sparkline([3, 3, 3])).toBe('▄▄▄'))
  it('empty is empty', () => expect(sparkline([])).toBe(''))
})
