import { describe, expect, it } from 'vitest'
import { cells, pathParts, rowHeight, rowsHeight, tableRow, tableSpec } from './rows'

const PATH = 'src/Query/Drift/GitDriftOracle.php'

describe('a path in two tones', () => {
  it('keeps the whole path when it fits, split at its last slash', () => {
    expect(pathParts(PATH, 60)).toEqual({ dir: 'src/Query/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts('README.md', 20)).toEqual({ dir: '', base: 'README.md' })
  })

  it('cuts the middle of the directory, by whole folders first, never the file name', () => {
    expect(pathParts(PATH, 30)).toEqual({ dir: 'src/…/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 27)).toEqual({ dir: '…/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 22)).toEqual({ dir: 'sr…/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 20)).toEqual({ dir: '…/', base: 'GitDriftOracle.php' })
    // Only a file name wider than the width is cut, at its end.
    expect(pathParts(PATH, 12)).toEqual({ dir: '', base: 'GitDriftOra…' })
  })

  it('never draws wider than the width it has', () => {
    for (let width = 1; width <= 40; width++) {
      const { dir, base } = pathParts(PATH, width)
      expect(cells(dir) + cells(base), `${width}`).toBeLessThanOrEqual(width)
      if (width >= cells('GitDriftOracle.php') + 2) expect(base).toBe('GitDriftOracle.php')
    }
  })

  it('draws the directory dim and the file name in the text tone in a table, padded to the column', () => {
    const spec = tableSpec(40, [PATH], [], [3])
    const row = tableRow('r', { name: PATH, boundary: null, values: [7], max: 7, path: true, press: 'row:0' }, spec)
    const dir = row.segments.find(s => s.text.endsWith('/'))
    const base = row.segments.find(s => s.press !== undefined)
    expect(dir?.dim).toBe(true)
    expect(base?.text).toBe('GitDriftOracle.php')
    expect(base?.dim).toBeUndefined()
    expect(base?.press).toEqual({ id: 'row:0', label: PATH })
  })
})

describe('the rows a row takes on screen', () => {
  it('is one for a row of text, and one per changed or context line for a diff element, its @@ header drawn as no row', () => {
    expect(rowHeight({ key: 'a', segments: [{ text: 'x' }] })).toBe(1)
    // As the engine drew it in a live session: a hunk of seven lines took seven rows, the header none.
    const hunk = { key: 'h', segments: [], code: { source: '@@ -5,6 +5,7 @@\n a\n b\n c\n+d\n e\n f\n g', path: 'x.ts' } }
    expect(rowHeight(hunk)).toBe(7)
    expect(rowsHeight([hunk, { key: 'b', segments: [{ text: 'y' }] }])).toBe(8)
  })
})
