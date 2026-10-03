import { describe, expect, it } from 'vitest'
import type { DiffState, Inspected, SessionDiff } from '../../types'
import { diffView, folded, HUNK_LINES, hunkSource, HUNKS_SHOWN, LINE_MAX, parseHunks } from './diff'
import type { DiffView } from './diff'
import { parseSessionDiff, parseSessionRev } from './envelopes'
import { diffRows, fileDetailRows } from './__tests__/tabs'
import { plainText } from './__tests__/plain-text'
import { rowWidth } from './rows'
import type { Row } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200]
const REV = '0123456789abcdef0123456789abcdef01234567'
const SHOWN: Inspected = { name: 'src/Router.php', label: 'src/Router.php', file: true, changed: true }
const text = (row: Row): string => plainText(row)
const answer = (over: Partial<SessionDiff> = {}): SessionDiff => ({
  status: 'ok',
  file: 'src/Router.php',
  kind: 'changed',
  from: null,
  to: null,
  binary: false,
  diff: '@@ -3,3 +3,4 @@ final class Router\n {\n-    // old\n+    // new\n+    // more\n }\n',
  lines: 6,
  truncated: false,
  ...over,
})
const done = (diff: SessionDiff | null): DiffState => ({ name: SHOWN.name, rev: REV, snapshot: 's1', phase: 'done', diff })
const viewOf = (diff: SessionDiff | null): DiffView => diffView(SHOWN, done(diff), { status: 'ok', rev: REV })!

/** A hunk of `n` added lines from line 1. */
const long = (n: number): string => `@@ -0,0 +1,${n} @@\n${Array.from({ length: n }, (_, i) => `+line ${i + 1}`).join('\n')}\n`

describe('the hunks of a diff', () => {
  it('reads each hunk with where it starts and its lines, and passes by what is not a hunk line', () => {
    const hunks = parseHunks('@@ -3,3 +3,4 @@ final class Router\n {\n-a\n+b\n\\ No newline at end of file\n@@ -10 +11 @@\n-x\n+y\n')
    expect(hunks).toEqual([
      { oldStart: 3, newStart: 3, heading: 'final class Router', lines: [' {', '-a', '+b'] },
      { oldStart: 10, newStart: 11, heading: '', lines: ['-x', '+y'] },
    ])
  })

  it('keeps the element to the characters it takes: a carriage return goes, other control characters show as a mark, long lines are cut', () => {
    const [hunk] = parseHunks(`@@ -1 +1 @@\n-a\r\n+b\u0007c\td\n+${'x'.repeat(LINE_MAX * 2)}\n`)
    expect(hunk!.lines[0]).toBe('-a')
    expect(hunk!.lines[1]).toBe('+b�c\td')
    expect([...hunk!.lines[2]!]).toHaveLength(LINE_MAX)
    expect(hunk!.lines[2]!.endsWith('…')).toBe(true)
  })

  it('folds a long hunk and gives what is shown a header that counts it, so it still parses', () => {
    const [hunk] = parseHunks(long(HUNK_LINES + 25))
    const { shown, more } = folded(hunk!)
    expect(shown).toHaveLength(HUNK_LINES)
    expect(more).toBe(25)
    expect(hunkSource(hunk!, shown).split('\n')[0]).toBe(`@@ -0,0 +1,${HUNK_LINES} @@`)
    // A mixed hunk folded before its removals: the old side is empty and starts the line before.
    const [mixed] = parseHunks('@@ -5,2 +5,3 @@\n+a\n+b\n-c\n-d\n+e\n')
    expect(hunkSource(mixed!, mixed!.lines.slice(0, 2))).toBe('@@ -4,0 +5,2 @@\n+a\n+b')
  })

  it('never hands the element more than it takes', () => {
    // Two code units a character: forty full lines are more than the element's ten thousand.
    const [hunk] = parseHunks(`@@ -0,0 +1,${HUNK_LINES} @@\n${Array.from({ length: HUNK_LINES }, () => `+${'😀'.repeat(LINE_MAX)}`).join('\n')}\n`)
    const source = hunkSource(hunk!, folded(hunk!).shown)
    expect(source.length).toBeLessThanOrEqual(10_000)
    const lines = source.split('\n')
    expect(lines.length - 1).toBeLessThan(HUNK_LINES)
    expect(lines[0]).toBe(`@@ -0,0 +1,${lines.length - 1} @@`)
  })
})

describe("the detail's view of the change", () => {
  it('is there only for a file opened from the session changes', () => {
    expect(diffView({ ...SHOWN, changed: undefined }, done(answer()), { status: 'ok', rev: REV })).toBeNull()
    expect(diffView({ name: 'App\\Router', label: 'Router' }, done(answer()), { status: 'ok', rev: REV })).toBeNull()
  })

  it("says why there is none: no commit recorded, no git, still reading, nothing answered", () => {
    expect(diffView(SHOWN, null, null)).toMatchObject({ phase: 'message', text: expect.stringContaining('not recorded') })
    expect(diffView(SHOWN, null, { status: 'no-git' })).toMatchObject({ phase: 'message', text: expect.stringContaining('not in a git repository') })
    expect(diffView(SHOWN, null, { status: 'ok', rev: REV })).toEqual({ phase: 'loading' })
    expect(diffView(SHOWN, { ...done(answer()), name: 'src/Other.php' }, { status: 'ok', rev: REV })).toEqual({ phase: 'loading' })
    expect(viewOf(null)).toMatchObject({ phase: 'message', text: expect.stringContaining('said nothing') })
    expect(viewOf({ status: 'unknown-rev' })).toMatchObject({ text: expect.stringContaining('no longer in the repository') })
    expect(viewOf(answer({ binary: true, diff: '' }))).toMatchObject({ text: expect.stringContaining('binary') })
    expect(viewOf(answer({ unreadable: true, diff: '' }))).toMatchObject({ text: expect.stringContaining('could not show') })
    expect(viewOf(answer({ kind: 'unchanged', diff: '' }))).toMatchObject({ text: 'Back as it was when the session began.' })
    expect(viewOf(answer({ kind: 'absent', diff: '' }))).toMatchObject({ text: expect.stringContaining('no record') })
  })

  it('counts the lines added and removed, and names the other side of a rename', () => {
    expect(viewOf(answer())).toMatchObject({ phase: 'diff', added: 2, removed: 1, renamed: null })
    expect(viewOf(answer({ kind: 'renamed', from: 'src/Old.php', to: 'src/Router.php', diff: '' }))).toMatchObject({ phase: 'diff', renamed: 'renamed from src/Old.php', hunks: [] })
    expect(viewOf(answer({ kind: 'renamed', from: 'src/Router.php', to: 'src/New.php', diff: '' }))).toMatchObject({ renamed: 'renamed to src/New.php' })
  })
})

describe('the change as rows', () => {
  const many = Array.from({ length: HUNKS_SHOWN + 3 }, (_, i) => `@@ -${i * 10 + 1},1 +${i * 10 + 1},1 @@\n-a${i}\n+b${i}\n`).join('')

  for (const columns of WIDTHS) {
    it(`fits ${columns} columns: every text row within them, each hunk one diff element`, () => {
      for (const view of [viewOf(answer()), viewOf(answer({ diff: long(HUNK_LINES + 7), truncated: true })), viewOf(answer({ diff: many })), viewOf(null), { phase: 'loading' } as const]) {
        const rows = diffRows(view, columns)
        for (const r of rows) expect(rowWidth(r), `${columns}: ${text(r)}`).toBeLessThanOrEqual(columns)
        expect(text(rows.find(r => r.key === 'diff-head')!)).toMatch(/^Changed since the session began|^Changed since/)
      }
      const plain = diffRows(viewOf(answer()), columns)
      expect(plain.filter(r => r.code !== undefined)).toEqual([{ key: 'diff-hunk-0', segments: [], code: { source: expect.stringMatching(/^@@ -3,3 \+3,4 @@/), path: 'src/Router.php' } }])
      expect(text(plain.find(r => r.key === 'diff-head')!)).toMatch(/\+2 −1$/)
    })
  }

  it('folds a long hunk with how many lines it leaves out, and says when the diff was cut', () => {
    const rows = diffRows(viewOf(answer({ diff: long(HUNK_LINES + 7), truncated: true })), 60)
    expect(text(rows.find(r => r.key === 'diff-more-0')!).trim()).toBe('7 more lines')
    expect(text(rows.find(r => r.key === 'diff-cut')!)).toContain('cut here')
  })

  it('shows the first hunks and counts the rest', () => {
    const rows = diffRows(viewOf(answer({ diff: many })), 90)
    expect(rows.filter(r => r.code !== undefined)).toHaveLength(HUNKS_SHOWN)
    expect(text(rows.find(r => r.key === 'diff-hunks-more')!).trim()).toBe('3 more changes further down the file')
  })

  it('stands below the dependents in the file detail, and under the message of a file no longer in the graph', () => {
    const file = {
      path: 'src/Router.php',
      language: 'php',
      lines: 10,
      boundary: null,
      loc: null,
      dependents: { count: 0, truncated: false, boundaries: [], items: [] },
      components: { count: 0, truncated: false, items: [] },
    }
    const keys = fileDetailRows({ label: 'src/Router.php', loading: false, messages: null, component: null, file, diff: viewOf(answer()) }, 60).map(r => r.key)
    expect(keys.indexOf('diff-head')).toBeGreaterThan(keys.indexOf('deps-head'))
    expect(keys.indexOf('diff-head')).toBeLessThan(keys.indexOf('comps-head'))
    const gone = fileDetailRows({ label: 'src/Router.php', loading: false, messages: ['Not in the graph.'], component: null, file: null, diff: viewOf(answer({ kind: 'deleted' })) }, 60)
    expect(gone.map(r => r.key)).toContain('diff-hunk-0')
  })
})

describe('the envelopes', () => {
  it('reads a diff, and nothing that is not one', () => {
    expect(parseSessionDiff(JSON.stringify(answer()))?.kind).toBe('changed')
    expect(parseSessionDiff(JSON.stringify({ status: 'ok' }))).toBeNull()
    expect(parseSessionDiff('')).toBeNull()
    expect(parseSessionDiff('{"status":"no-binary"}')?.status).toBe('no-binary')
  })

  it("reads the session's commit, a hex id or no git, and nothing else", () => {
    expect(parseSessionRev(JSON.stringify({ status: 'ok', rev: REV }))).toEqual({ status: 'ok', rev: REV })
    expect(parseSessionRev(JSON.stringify({ status: 'no-git', rev: null }))).toEqual({ status: 'no-git' })
    expect(parseSessionRev(JSON.stringify({ status: 'ok', rev: 'HEAD; rm -rf /' }))).toBeNull()
    expect(parseSessionRev('{"status":"no-binary"}')).toBeNull()
    expect(parseSessionRev('')).toBeNull()
  })
})
