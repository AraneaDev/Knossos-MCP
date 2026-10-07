import { describe, expect, it } from 'vitest'
import { printable, printableDeep } from './printable'

describe('printable', () => {
  it('replaces ESC so a path cannot clear the screen', () => expect(printable('src/\u001b[2Jx.ts')).toBe('src/�[2Jx.ts'))
  it('replaces BEL, DEL and C1 controls', () => expect(printable('a\u0007b\u007fc\u009bd')).toBe('a�b�c�d'))
  it('keeps tab, newline and non-ASCII text', () => expect(printable('a\tb\nc 漢字 é')).toBe('a\tb\nc 漢字 é'))
  it('neutralises bidi and invisible format characters', () =>
    expect(printable('a\u202eb\u2066c\u200fd\u200be\u2028f')).toBe('a�b�c�d�e�f'))
  it('keeps the joiner inside an emoji sequence', () => expect(printable('👩\u200d💻')).toBe('👩\u200d💻'))
  it('reaches strings in arrays and objects, keys included', () =>
    expect(printableDeep({ 'k\u001b': ['v\u001b', 1, null, true, { n: 'x\u009b' }] })).toEqual({ 'k�': ['v�', 1, null, true, { n: 'x�' }] }))
})
