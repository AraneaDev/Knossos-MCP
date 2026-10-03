/**
 * What this session changed: the turn briefs added up, the Changes tab that
 * lists it, and the Overview's "Look at now" that points at the riskiest part.
 *
 * Pure, like the rest of the layout. `accumulate` folds one `ok` brief into
 * the session's running total (the mod stores it after each turn's scan);
 * the rest reads that total. Every list is capped, and a cap that bit says so.
 */
import type { SessionChanges, TouchStatus, TurnBrief } from '../../types'
import { boundaryLabel, HEADING, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import {
  baseName,
  blank,
  boundaryStyle,
  button,
  clip,
  dimRow,
  grouped,
  numberWidth,
  plural,
  sectionRow,
  sectionWidth,
  specWidth,
  tableHead,
  tableRow,
  tableSpec,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment } from './rows'
import { locIn } from './views'
import type { Openable } from './views'

/** The most files, tests and violations a session keeps; past them `truncated` is set. */
export const FILE_CAP = 500
export const TEST_CAP = 500
export const VIOLATION_CAP = 200
/** How many tests the Changes tab lists before `+N more`. */
const TESTS_SHOWN = 8
/** How many boundaries the Changes tab names on its summary line. */
const REACH_SHOWN = 3
/** Paths longer than this are cut from the front in a table. */
const PATH_MAX = 56

export const NO_CHANGES: SessionChanges = { turns: 0, files: {}, tests: {}, violations: [], truncated: false }

/**
 * How a file stands after a turn that reports it as `now`: a file added this
 * session stays added while it is edited, a deleted one is deleted, and one
 * written again after its deletion is changed.
 */
function settle(before: TouchStatus | undefined, now: TouchStatus): TouchStatus {
  if (now === 'deleted') return 'deleted'
  if (before === 'added' || (before === undefined && now === 'added')) return 'added'
  return 'changed'
}

/**
 * The session's changes with one more `ok` brief folded in: its files (with
 * their dependents and boundaries as this brief's graph has them), the tests
 * that reach them at their nearest distance, and the violations it introduced.
 */
export function accumulate(changes: SessionChanges, brief: TurnBrief): SessionChanges {
  if (brief.status !== 'ok') return changes
  const files = { ...changes.files }
  const tests = { ...changes.tests }
  const violations = new Set(changes.violations)
  let truncated = changes.truncated
  const reported: [string, TouchStatus][] = [
    ...brief.changed_files.map((f): [string, TouchStatus] => [f, 'changed']),
    ...brief.added_files.map((f): [string, TouchStatus] => [f, 'added']),
    ...brief.deleted_files.map((f): [string, TouchStatus] => [f, 'deleted']),
  ]
  for (const [path, status] of reported) {
    const before = files[path]
    if (before === undefined && Object.keys(files).length >= FILE_CAP) {
      truncated = true
      continue
    }
    const impact = brief.impact[path]
    files[path] = {
      status: settle(before?.status, status),
      dependents: impact?.dependent_files ?? before?.dependents ?? 0,
      boundaries: impact?.boundaries ?? before?.boundaries ?? [],
      boundary: impact === undefined ? (before?.boundary ?? null) : (impact.boundary ?? null),
    }
  }
  for (const test of brief.tests) {
    const before = tests[test.path]
    if (before === undefined && Object.keys(tests).length >= TEST_CAP) {
      truncated = true
      continue
    }
    tests[test.path] = Math.min(before ?? test.distance, test.distance)
  }
  for (const v of brief.policy.violations) {
    const key = `${v.source} → ${v.target}`
    if (!violations.has(key) && violations.size >= VIOLATION_CAP) {
      truncated = true
      continue
    }
    violations.add(key)
  }
  return { turns: changes.turns + 1, files, tests, violations: [...violations], truncated }
}

/** Single-quoted for a POSIX shell when it holds anything a shell would read. */
const quote = (word: string): string => (/^[\w./@%+=:,-]+$/.test(word) ? word : `'${word.replace(/'/g, `'\\''`)}'`)

/**
 * The test runners a path can call for, each with how its tests are told
 * apart and what a shell command that invokes it looks like. The command
 * that runs the tests and the check whether a command already ran one read
 * the same table, so they never disagree.
 */
const RUNNERS = {
  phpunit: { test: /Test\.php$/, invoked: /\b(phpunit|paratest|pest)\b|\bcomposer\s+(run(-script)?\s+)?test\b/ },
  vitest: { test: /\.(spec|test)\.[cm]?[jt]sx?$/, invoked: /\b(vitest|jest)\b|\b(npm|yarn|pnpm)\s+(run\s+)?test(:[\w-]+)?\b/ },
  pytest: { test: /(^|\/)test_[^/]*\.py$|_test\.py$/, invoked: /\bpytest\b/ },
  go: { test: /_test\.go$/, invoked: /\bgo\s+test\b/ },
  cargo: { test: /\.rs$/, invoked: /\bcargo\s+(test|nextest)\b/ },
} as const

/** The runner a test path calls for, or null for a path no runner runs (a helper, a fixture). */
export function runnerOf(path: string): keyof typeof RUNNERS | null {
  return (Object.keys(RUNNERS) as (keyof typeof RUNNERS)[]).find(r => RUNNERS[r].test.test(path)) ?? null
}

/**
 * A command that runs `tests`, by the runner their paths call for: PHPUnit
 * for `*Test.php` (the file, or a `--filter` over the classes for several),
 * Vitest for `*.spec.*` and `*.test.*` scripts, pytest for `test_*.py` and
 * `*_test.py`, `go test` per package for `*_test.go`, `cargo test` for Rust.
 * Several runners are joined with `&&`; null when no path names a runner.
 */
export function testCommand(tests: string[]): string | null {
  const of = (runner: keyof typeof RUNNERS) => tests.filter(t => runnerOf(t) === runner)
  const php = of('phpunit')
  const vitest = of('vitest')
  const pytest = of('pytest')
  const packageOf = (t: string) => (t.includes('/') ? `./${t.slice(0, t.lastIndexOf('/'))}` : '.')
  const go = [...new Set(of('go').map(packageOf))]
  const commands: string[] = []
  if (php.length === 1) commands.push(`vendor/bin/phpunit ${quote(php[0]!)}`)
  if (php.length > 1) commands.push(`vendor/bin/phpunit --filter ${quote(`(${[...new Set(php.map(t => baseName(t).slice(0, -4)))].join('|')})`)}`)
  if (vitest.length > 0) commands.push(`npx vitest run ${vitest.map(quote).join(' ')}`)
  if (pytest.length > 0) commands.push(`python -m pytest ${pytest.map(quote).join(' ')}`)
  if (go.length > 0) commands.push(`go test ${go.map(quote).join(' ')}`)
  if (of('cargo').length > 0) commands.push('cargo test')
  return commands.length === 0 ? null : commands.join(' && ')
}

/** Flags by which a runner is told to run some tests only. */
const NARROWING = /^(--filter|--testsuite|--group|--exclude-group|-k|-t|--testNamePattern|--run|--package|-p)(=|$)/

/**
 * Whether one of `commands` (shell commands, as a turn ran them) ran the test
 * at `path`: one that invokes its runner and names the test (its file stem,
 * as a filter does), names a directory or package the test sits in, or
 * names no test at all (the runner's whole suite).
 */
export function testsRan(path: string, commands: string[]): boolean {
  const runner = runnerOf(path)
  if (runner === null) return false
  const name = baseName(path)
  const stem = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : name
  return commands.some(command => {
    const invoked = RUNNERS[runner].invoked.exec(command)
    if (invoked === null) return false
    if (command.includes(stem)) return true
    const words = command.slice(invoked.index + invoked[0].length).split(/\s+/).filter(w => w !== '' && w !== 'run')
    const places = words.filter(w => !w.startsWith('-') && (w.includes('/') || w === '.' || runnerOf(w) !== null))
    if (places.some(w => {
      const dir = w.replace(/^\.\//, '').replace(/\.\.\.$/, '').replace(/\/+$/, '')
      return dir === '' || dir === '.' || path.startsWith(`${dir}/`) || path === dir
    })) return true
    return places.length === 0 && !words.some(w => NARROWING.test(w))
  })
}

/** A boundary's place in `hues` (the project's colour order), past the end for one it does not hold. */
function rankIn(hues: Hues): (boundary: string) => number {
  const order = [...hues.keys()]
  return b => (order.includes(b) ? order.indexOf(b) : order.length)
}

export type TouchedFile = { path: string; status: TouchStatus; boundary: string | null; dependents: number; loc: Loc | null }
export type ChangesInput = {
  turns: number
  /** Most dependents first. */
  files: TouchedFile[]
  /** Nearest first. */
  tests: { path: string; distance: number; loc: Loc | null }[]
  command: string | null
  /** Every boundary the changes' dependents are in, best ranked first. */
  boundaries: string[]
  violations: number
  truncated: boolean
}

/** The Changes tab's view of the session's changes; `root` places the files on disk. */
export function changesInput(changes: SessionChanges, root: string | null, hues: Hues = NO_HUES): ChangesInput {
  const files = Object.entries(changes.files)
    .map(([path, f]) => ({
      path,
      status: f.status,
      // The file's own boundary; its dependents' are where the change reaches, not where it is.
      boundary: f.boundary ?? null,
      dependents: f.dependents,
      loc: f.status === 'deleted' ? null : locIn(root, path),
    }))
    .sort((a, b) => b.dependents - a.dependents || a.path.localeCompare(b.path))
  const tests = Object.entries(changes.tests)
    .map(([path, distance]) => ({ path, distance, loc: locIn(root, path) }))
    .sort((a, b) => a.distance - b.distance || a.path.localeCompare(b.path))
  const reached = [...new Set(Object.values(changes.files).flatMap(f => f.boundaries))]
  const rank = rankIn(hues)
  return {
    turns: changes.turns,
    files,
    tests,
    command: testCommand(tests.map(t => t.path)),
    boundaries: reached.sort((a, b) => rank(a) - rank(b) || a.localeCompare(b)),
    violations: changes.violations.length,
    truncated: changes.truncated,
  }
}

/** The files the Changes tab walks: each opens in the editor, not as a component. */
export function changesList(input: ChangesInput): Openable[] {
  return input.files.map(f => ({ name: f.path, canonical: f.path, loc: f.loc, file: true }))
}

/** What the Overview points at: the touched file with the most dependents, and how many tests reach the changes. */
export type LookAt = { file: TouchedFile | null; tests: number; command: string | null; plus: boolean }

/** "Look at now" from the session's changes; null before anything changed. */
export function lookAtOf(input: ChangesInput): LookAt | null {
  if (input.files.length === 0) return null
  const file = input.files.find(f => f.status !== 'deleted') ?? null
  return { file, tests: input.tests.length, command: input.command, plus: input.truncated }
}

/** Count of `n` with a `+` when a cap made it a floor. */
const floor = (n: number, plus: boolean) => `${grouped(n)}${plus ? '+' : ''}`

/**
 * The Overview's "Look at now": the riskiest file this session touched, a
 * key (`e`) away from the editor, and the tests that reach the changes, a
 * key (`t`) away from a copied command; a warning when no test reaches them.
 */
export function lookAtRows(look: LookAt, columns: number, hues: Hues = NO_HUES): Row[] {
  const rows: Row[] = [sectionRow('look-head', 'Look at now', '', columns)]
  if (look.file !== null) {
    const f = look.file
    const segments: Segment[] = [
      { text: '   ' },
      button('edit', baseName(f.path), 'e'),
      ...(f.boundary === null ? [] : [{ text: ' ' }, { text: boundaryLabel(f.boundary), ...boundaryStyle(f.boundary, hues) }]),
      { text: ` · ${plural(f.dependents, 'dependent', 'dependents')}`, dim: true },
    ]
    rows.push({ key: 'look-file', segments: clip(segments.filter(s => s.text !== ''), columns) })
  }
  const tests: Segment[] =
    look.tests === 0
      ? [{ text: '   ' }, { text: "▲ no test reaches this session's changes", color: STATUS_COLOURS.warn }]
      : [
          { text: '   ' },
          ...(look.command === null ? [] : [button('tests', 'copy test command', 't'), { text: ' · ', dim: true }]),
          { text: `${floor(look.tests, look.plus)} ${look.tests === 1 && !look.plus ? 'test reaches' : 'tests reach'} the changes`, dim: true },
        ]
  rows.push({ key: 'look-tests', segments: clip(tests, columns) })
  return rows
}

/** A one-cell mark for how a file stands: `+` added, `−` deleted, nothing for changed. */
function statusMark(status: TouchStatus): Segment {
  if (status === 'added') return { text: '+', color: STATUS_COLOURS.ok }
  return status === 'deleted' ? { text: '−', color: STATUS_COLOURS.alert } : { text: ' ' }
}

/**
 * The Changes tab: every file this session touched, most dependents first,
 * with the boundary it sits in; the tests that reach the changes,
 * nearest first; and the command that runs them, which `c` copies.
 */
export function changesRows(input: ChangesInput, selected: number, columns: number, hues: Hues = NO_HUES): Row[] {
  const rows: Row[] = [blank('gap-changes')]
  if (input.files.length === 0) {
    rows.push(sectionRow('changes-head', 'Changes this session', '', columns))
    wrapWords("Nothing yet. The files Claude edits show here after each turn's scan, with what depends on them and the tests that reach them.", Math.max(1, columns - 3)).forEach(
      (line, i) => rows.push(dimRow(`changes-none-${i}`, `   ${line}`, columns)),
    )
    return rows
  }
  const deps = input.files.reduce((n, f) => n + f.dependents, 0)
  const spec = tableSpec(columns, input.files.map(f => f.path), input.files.map(f => boundaryLabel(f.boundary)), [numberWidth('deps', input.files.map(f => f.dependents))], PATH_MAX)
  const max = Math.max(0, ...input.files.map(f => f.dependents))
  const note = `${plural(input.turns, 'turn', 'turns')}${input.truncated ? ' · partial' : ''}`
  rows.push(sectionRow('changes-head', 'Changes this session', note, sectionWidth(specWidth(spec), 'Changes this session', note, columns)))
  // What the changes reach: the dependents, and every boundary they are in, each in its colour.
  const reach: Segment[][] = [[{ text: `${plural(input.files.length, 'file', 'files')} → ${grouped(deps)} dependents`, dim: true }]]
  // The best ranked few in colour; the rest counted, so the line stays a line and not a rainbow.
  const named = input.boundaries.slice(0, REACH_SHOWN)
  if (named.length > 0) reach.push([{ text: 'reaching', dim: true }], ...named.map(b => [{ text: boundaryLabel(b), ...boundaryStyle(b, hues) }]))
  if (input.boundaries.length > named.length) reach.push([{ text: `+${input.boundaries.length - named.length} more`, dim: true }])
  rows.push(...wrapGroups('changes-reach', reach, columns, 1, 3))
  if (input.violations > 0) {
    rows.push({ key: 'changes-policy', segments: [{ text: '   ' }, { text: `▲ ${plural(input.violations, 'policy violation', 'policy violations')} introduced`, color: STATUS_COLOURS.alert }] })
  }
  rows.push(tableHead('changes-cols', spec, { name: 'file', boundary: 'boundary', numbers: ['deps'] }))
  input.files.forEach((f, i) =>
    rows.push(
      tableRow(`change-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, selected: i === selected, mark: statusMark(f.status), cutStart: true, link: f.loc }, spec, hues),
    ),
  )
  rows.push(blank('gap-tests'), ...testRows(input, columns, hues))
  return rows
}

/** The tests that reach the changes, nearest first, and the command that runs them. */
function testRows(input: ChangesInput, columns: number, hues: Hues): Row[] {
  const count = `${grouped(input.tests.length)}${input.truncated ? '+' : ''}`
  if (input.tests.length === 0) {
    return [
      sectionRow('tests-head', 'Tests that reach them', '', columns),
      { key: 'tests-none', segments: [{ text: '   ' }, { text: '▲ none: no test reaches these changes', color: STATUS_COLOURS.warn }] },
    ]
  }
  const shown = input.tests.slice(0, TESTS_SHOWN)
  const spec = { ...tableSpec(columns, shown.map(t => t.path), [], [numberWidth('hops', shown.map(t => t.distance))], PATH_MAX), bar: 0 }
  const title = `Tests that reach them ${count}`
  const rows: Row[] = [sectionRow('tests-head', title, 'hops', sectionWidth(specWidth(spec), title, 'hops', columns))]
  shown.forEach((t, i) => rows.push(tableRow(`test-${i}`, { name: t.path, boundary: null, values: [t.distance], max: 0, cutStart: true, link: t.loc }, spec, hues)))
  if (input.tests.length > shown.length) rows.push(dimRow('tests-more', `   +${input.tests.length - shown.length} more`, columns))
  if (input.command !== null) {
    rows.push(blank('gap-command'))
    wrapWords(input.command, Math.max(1, columns - 5)).forEach((line, i) =>
      rows.push({ key: `command-${i}`, segments: [{ text: i === 0 ? '   $ ' : '     ', dim: true }, { text: line, color: HEADING }] }),
    )
  }
  return rows
}
