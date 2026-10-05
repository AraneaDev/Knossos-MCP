import { describe, expect, it } from 'vitest'
import type { Dashboard, DiffState, Inspected, KnossosView, SessionDiff } from '../../types'
import { DIFF_TEXT_MAX, diffSection, diffView, folded, HUNK_LINES, hunkSource, HUNKS_SHOWN, LINE_MAX, parseHunks } from './diff'
import type { DiffView } from './diff'
import { parseSessionDiff, parseSessionRev } from './envelopes'
import { diffRows, fileDetailRows } from './__tests__/tabs'
import { plainText } from './__tests__/plain-text'
import { paneInput, paneLayout } from './layout'
import { rowWidth } from './rows'
import type { Row } from './rows'
import type { DetailInput } from './views'

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

  it('keeps the hunks it draws within one total, as large as session-diff answers, and counts the rest', () => {
    // As much as session-diff sends: 200,000 bytes, 14 hunks of 70 full lines.
    const line = (h: number, i: number) => `+${`h${h} line ${i} `.padEnd(LINE_MAX - 1, 'x')}`
    const full = Array.from({ length: 14 }, (_, h) => `@@ -${h * 100 + 1},0 +${h * 100 + 1},70 @@\n${Array.from({ length: 70 }, (_, i) => line(h, i)).join('\n')}`).join('\n')
    const rows = diffRows(viewOf(answer({ diff: `${full}\n` })), 200)
    const drawn = rows.filter(r => r.code !== undefined)
    expect(drawn.reduce((sum, r) => sum + (r.code?.source.length ?? 0), 0)).toBeLessThanOrEqual(DIFF_TEXT_MAX)
    expect(drawn.length).toBeGreaterThan(0)
    expect(text(rows.find(r => r.key === 'diff-hunks-more')!).trim()).toBe(`${14 - drawn.length} more changes further down the file`)
  })

  it('weighs the hunks it draws as the engine does, serialized, so quotes and backslashes count twice', () => {
    // A change to escaped JSON: every character a quote or a backslash, so its serialized form is twice its length.
    const line = (h: number, i: number) => `+${`"h${h}\\${i}"`.padEnd(LINE_MAX - 1, '"')}`
    const full = Array.from({ length: 14 }, (_, h) => `@@ -${h * 100 + 1},0 +${h * 100 + 1},70 @@\n${Array.from({ length: 70 }, (_, i) => line(h, i)).join('\n')}`).join('\n')
    const drawn = diffRows(viewOf(answer({ diff: `${full}\n` })), 200).filter(r => r.code !== undefined)
    expect(drawn.length).toBeGreaterThan(0)
    expect(drawn.reduce((sum, r) => sum + JSON.stringify(r.code?.source ?? '').length, 0)).toBeLessThanOrEqual(DIFF_TEXT_MAX)
  })

  it('stands below the dependents in the file detail, and under the message of a file no longer in the graph', () => {
    const file = {
      path: 'src/Router.php',
      language: 'php',
      lines: 10,
      boundary: null,
      loc: null,
      dependents: { count: 0, truncated: false, boundaries: [], items: [] },
      uses: { count: 0, truncated: false, items: [] },
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

describe('a change drawn in a panel', () => {
  it('is text rows in the added and removed colours, no diff element, folded shorter, within the width', () => {
    const view = { phase: 'diff' as const, path: 'a.ts', added: 30, removed: 1, renamed: null, truncated: false, hunks: Array.from({ length: 6 }, (_, h) => ({ oldStart: 10 * h + 1, newStart: 10 * h + 1, heading: 'function f()', lines: [' keep', '-gone', ...Array.from({ length: 20 }, (_, i) => `+added line ${i} with\ta tab`)] })) }
    const section = diffSection(view, 40, true)
    expect(section.body.some(r => r.code !== undefined)).toBe(false)
    for (const r of section.body) expect(r.segments.reduce((n, s) => n + [...s.text].length, 0)).toBeLessThanOrEqual(40)
    expect(section.body.find(r => r.key === 'diff-hunk-0-1')?.segments[0]).toMatchObject({ text: '− gone', color: 'diffRemovedWord' })
    expect(section.body.find(r => r.key === 'diff-hunk-0-2')?.segments[0]?.color).toBe('diffAddedWord')
    expect(section.body.find(r => r.key === 'diff-hunk-0-head')?.segments[0]?.text).toBe('@@ −1 +1 @@ function f()')
    expect(section.body.map(r => r.key)).toContain('diff-more-0')
    expect(section.body.map(r => r.key)).toContain('diff-hunks-more')
  })
})

describe('a change opened further', () => {
  /** `n` hunks of `lines` added lines each, every line `width` characters. */
  const hunks = (n: number, lines: number, width = 20): string =>
    `${Array.from({ length: n }, (_, h) => `@@ -${h * 100 + 1},0 +${h * 100 + 1},${lines} @@\n${Array.from({ length: lines }, (_, i) => `+${`h${h} line ${i} `.padEnd(width - 1, 'x')}`).join('\n')}`).join('\n')}\n`
  const opened = (diff: string, open: number[], from = 0): DiffView => diffView(SHOWN, done(answer({ diff })), { status: 'ok', rev: REV }, { name: SHOWN.name, rev: REV, snapshot: 's1', open, from })!
  const press = (rows: Row[], key: string): string | undefined => rows.find(r => r.key === key)?.segments.find(s => s.press !== undefined)?.press?.id

  it('"N more lines" is a press that opens that hunk, and the opened hunk shows every line', () => {
    const diff = long(HUNK_LINES + 7)
    expect(press(diffRows(viewOf(answer({ diff })), 80), 'diff-more-0')).toBe('diff-more:0')
    const rows = diffRows(opened(diff, [0]), 80)
    expect(rows.find(r => r.key === 'diff-hunk-0')?.code?.source.split('\n')).toHaveLength(HUNK_LINES + 7 + 1)
    expect(rows.find(r => r.key === 'diff-more-0')).toBeUndefined()
  })

  it('"N more changes" is a press that shows the next hunks, and the hunks before them are a press back', () => {
    const diff = hunks(HUNKS_SHOWN + 5, 2)
    expect(press(diffRows(viewOf(answer({ diff })), 80), 'diff-hunks-more')).toBe(`diff-from:${HUNKS_SHOWN}`)
    const next = diffRows(opened(diff, [], HUNKS_SHOWN), 80)
    expect(next.filter(r => r.code !== undefined).map(r => r.key)).toEqual(Array.from({ length: 5 }, (_, i) => `diff-hunk-${HUNKS_SHOWN + i}`))
    expect(next.find(r => r.key === 'diff-hunks-more')).toBeUndefined()
    expect(text(next.find(r => r.key === 'diff-hunks-before')!).trim()).toBe(`${HUNKS_SHOWN} earlier changes`)
    expect(press(next, 'diff-hunks-before')).toBe('diff-from:0')
  })

  it('opens a hunk only as far as the element and DIFF_TEXT_MAX take, and past them says to open the file', () => {
    // As much as session-diff sends: 14 hunks of 70 lines, each as long as a hunk keeps.
    const diff = hunks(14, 70, LINE_MAX)
    for (const open of [[0], [0, 1, 2], Array.from({ length: 14 }, (_, i) => i)]) {
      const rows = diffRows(opened(diff, open), 200)
      const drawn = rows.filter(r => r.code !== undefined)
      expect(drawn.reduce((sum, r) => sum + JSON.stringify(r.code?.source ?? '').length, 0)).toBeLessThanOrEqual(DIFF_TEXT_MAX)
      // The first hunk is cut by the element's own limit: the rest is the file's to show.
      expect(text(rows.find(r => r.key === 'diff-more-0')!)).toMatch(/^\s*\d+ more lines: too long to draw here, e opens the file$/)
      expect(press(rows, 'diff-more-0')).toBeUndefined()
      expect(press(rows, 'diff-hunks-more')).toBe(`diff-from:${drawn.length}`)
    }
  })

  it('pages back as far as one page holds', () => {
    const diff = hunks(14, 70, LINE_MAX)
    const first = diffRows(opened(diff, []), 200).filter(r => r.code !== undefined).length
    const second = diffRows(opened(diff, [], first), 200)
    expect(press(second, 'diff-hunks-before')).toBe('diff-from:0')
  })

  it('forgets what was opened for another file, another commit or another snapshot', () => {
    const diff = long(HUNK_LINES + 7)
    for (const other of [{ name: 'src/Other.php' }, { rev: 'f'.repeat(40) }, { snapshot: 's2' }]) {
      const view = diffView(SHOWN, done(answer({ diff })), { status: 'ok', rev: REV }, { name: SHOWN.name, rev: REV, snapshot: 's1', open: [0], from: 0, ...other })!
      expect(press(diffRows(view, 80), 'diff-more-0'), JSON.stringify(other)).toBe('diff-more:0')
    }
  })

  it('in a panel ends in a press that opens the full diff, and draws no press that opens a hunk', () => {
    const section = diffSection(viewOf(answer({ diff: hunks(6, 20) })), 50, true)
    expect(press(section.body, 'diff-full')).toBe('diff-open')
    expect(text(section.body.find(r => r.key === 'diff-full')!).trim()).toBe('open the full diff')
    expect(section.body.filter(r => r.key !== 'diff-full').flatMap(r => r.segments).filter(s => s.press !== undefined)).toEqual([])
  })

  it('never keys a row as a press is keyed', () => {
    const diff = hunks(HUNKS_SHOWN + 5, HUNK_LINES + 3)
    for (const rows of [diffRows(viewOf(answer({ diff })), 80), diffRows(opened(diff, [1], 2), 80), diffSection(viewOf(answer({ diff })), 50, true).body]) {
      const keys = new Set(rows.map(r => r.key))
      for (const s of rows.flatMap(r => r.segments)) if (s.press !== undefined) expect(keys.has(s.press.id), s.press.id).toBe(false)
    }
  })
})

describe('the full detail of a long change', () => {
  const dashboard = {
    status: 'ok',
    path: '/work/app',
    project_root: '/work/app',
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [],
    hubs_truncated: false,
    hubs_truncation_reasons: [],
    hotspots: [],
    dead_code_candidates: 0,
    dead_code_truncated: false,
    cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
    trend: [],
    fan_in: [],
    fan_in_truncated: false,
  } as unknown as Dashboard
  const view: KnossosView = { inspect: SHOWN, isBandHidden: false, tab: 'changes', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }
  /** The rows a row takes on screen: a diff element one per line of its source (it wraps nothing), any other one. */
  const height = (rows: Row[]): number => rows.reduce((n, r) => n + (r.code === undefined ? 1 : r.code.source.split('\n').length), 0)

  for (const columns of [60, 100, 140, 200]) {
    it(`counts every line of its diff elements, so the pane scrolls to the last and the bar stays pinned (${columns} columns)`, () => {
      const shown: DetailInput = { label: SHOWN.label, loading: false, messages: ['Not in the graph.'], component: null, file: null, diff: viewOf(answer({ diff: long(HUNK_LINES * 3) })) }
      const input = paneInput(dashboard, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view, 0, true, shown)
      for (const rows of [30, 50, 80]) {
        const laid = paneLayout(input, columns, rows)
        const total = height(laid.body) + laid.footer.length
        // Taller than the window: pinned, with no blank fill under it; else exactly the window.
        if (laid.pinned) expect(laid.body.some(r => r.key.startsWith('fill-')), `${rows}`).toBe(false)
        else expect(total, `${rows}`).toBe(rows)
        expect(laid.pinned, `${rows}`).toBe(height(laid.body) + laid.footer.length > rows)
        // No card is stretched with blank rows once the change alone overfills the window.
        if (laid.pinned) expect(laid.body.filter(r => r.key.startsWith('stretch')), `${rows}`).toEqual([])
      }
    })
  }
})
