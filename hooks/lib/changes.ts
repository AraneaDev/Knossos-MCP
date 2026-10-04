/**
 * What this session changed: the turn briefs added up, the Changes tab that
 * lists it, and what the Overview's "This session" card sums up from it.
 *
 * Pure, like the rest of the layout. `accumulate` folds one `ok` brief into
 * the session's running total (the mod stores it after each turn's scan);
 * `fromLedger` puts in its place everything the scan ledger says changed in
 * the project since the session began, whoever changed it, while a live
 * watcher keeps that ledger; the rest reads either. Every list is capped,
 * and a cap that bit says so.
 */
import type { JsRunner, SessionChanges, SessionLedger, TouchStatus, TurnBrief } from '../../types'
import { ACCENT, boundaryLabel, HEADING, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import {
  baseName,
  blank,
  cells,
  chip,
  clip,
  dimRow,
  grouped,
  numberWidth,
  padEnd,
  padStart,
  plural,
  spaces,
  segmentsWidth,
  tableHead,
  tableRow,
  tableSpec,
  tinted,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, Tier } from './rows'
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { locIn } from './views'
import type { Openable } from './views'

/** The most files, tests and violations a session keeps; past them `truncated` is set. */
export const FILE_CAP = 500
export const TEST_CAP = 500
export const VIOLATION_CAP = 200
/** How many boundaries the Changes tab names on its summary line. */
const REACH_SHOWN = 3
/** Paths longer than this are cut from the front in a table. */
const PATH_MAX = 56
/** Where a file's change came from, as the Changes tab labels it. */
const ORIGIN_LABELS = { session: 'this session', outside: 'outside' } as const
const ORIGIN_WIDTH = Math.max(...Object.values(ORIGIN_LABELS).map(l => l.length))

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
    // How many tests reach it, as the latest brief that knew says.
    const reached = impact?.tests ?? before?.tests
    files[path] = {
      status: settle(before?.status, status),
      dependents: impact?.dependent_files ?? before?.dependents ?? 0,
      boundaries: impact?.boundaries ?? before?.boundaries ?? [],
      boundary: impact === undefined ? (before?.boundary ?? null) : (impact.boundary ?? null),
      ...(reached === undefined || status === 'deleted' ? {} : { tests: reached }),
    }
  }
  const runners = { ...(changes.js_runners ?? {}) }
  for (const test of brief.tests) {
    const before = tests[test.path]
    if (before === undefined && Object.keys(tests).length >= TEST_CAP) {
      truncated = true
      continue
    }
    tests[test.path] = Math.min(before ?? test.distance, test.distance)
    if (test.js_runner !== undefined) runners[test.path] = test.js_runner
  }
  for (const v of brief.policy.violations) {
    const key = `${v.source} → ${v.target}`
    if (!violations.has(key) && violations.size >= VIOLATION_CAP) {
      truncated = true
      continue
    }
    violations.add(key)
  }
  return { turns: changes.turns + 1, files, tests, js_runners: runners, violations: [...violations], truncated }
}

/**
 * The session's changes as the scan ledger has them: every file changed in
 * the project since the session began, whoever changed it, each labelled by
 * where its change came from, and the tests that reach them. A file is the
 * session's when its own edit tools wrote it (`edited`: project-relative
 * paths, main loop or subagent) or when a scan that changed it took in
 * changes noticed while the session's tools ran (`scans`: the snapshots
 * those scans produced, see `activity.ts`); otherwise it is outside. The
 * turn count and the violations introduced stay the turn briefs': they are
 * the model's own, and only its own edits are judged.
 */
export function fromLedger(ledger: SessionLedger, turns: SessionChanges, edited: ReadonlySet<string>, scans: ReadonlySet<string> = new Set()): SessionChanges {
  const tests: Record<string, number> = {}
  const runners: Record<string, JsRunner | null> = {}
  for (const t of ledger.tests) {
    tests[t.path] = t.distance
    if (t.js_runner !== undefined) runners[t.path] = t.js_runner
  }
  // Scans the ledger merged are named by their key: the session's own are matched by theirs.
  const own = new Set([...scans].map(scanKey))
  const ours = (id: string): boolean => scans.has(id) || own.has(scanKey(id))
  const origins: Record<string, 'session' | 'outside'> = {}
  for (const [path, file] of Object.entries(ledger.files)) origins[path] = edited.has(path) || (file.scans ?? []).some(ours) ? 'session' : 'outside'
  const timeline = (ledger.scans ?? []).map(scan => ({ snapshot: scan.snapshot_id, origin: ours(scan.snapshot_id) ? ('session' as const) : ('outside' as const) }))
  return {
    turns: turns.turns,
    files: ledger.files,
    tests,
    js_runners: runners,
    violations: turns.violations,
    truncated: ledger.files_truncated || ledger.tests_truncated,
    origins,
    timeline,
    ...(ledger.scans_truncated === true ? { timeline_truncated: true } : {}),
  }
}

/** The session's own scans as a timeline, every one the session's: what the Changes tab draws without the scan ledger. */
export const ownTimeline = (scans: readonly string[]): { snapshot: string; origin: 'session' }[] => scans.map(snapshot => ({ snapshot, origin: 'session' }))

/** How much of a snapshot id the scan ledger keeps for the scans it merged: `scan_` and 48 bits of hash (as `ScanLedgerSpan::key`). */
const SCAN_KEY = 17
/** A snapshot id as the scan ledger names a merged scan. */
export const scanKey = (id: string): string => (id.length > SCAN_KEY ? id.slice(0, SCAN_KEY) : id)

const pad2 = (n: number): string => String(n).padStart(2, '0')
/** A moment (milliseconds) as a local date and time to the minute: `2026-10-04 09:14`. */
export function stamp(ms: number): string {
  const d = new Date(ms)
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`
}

/** Said when the session began among scans the ledger keeps merged. */
export const APPROXIMATE_START = 'This session began among older scans the record keeps merged, so its start is approximate: a file changed shortly before it may be listed.'

/**
 * The session's changes from a `session-changes` answer for its start, as
 * the Changes tab shows them. When the ledger cannot reach back to that
 * start, it says so with when the session began (`began`, milliseconds;
 * null when not known), and lists every change since the oldest point the
 * ledger still holds, said with its date; with no such point (an older
 * knossos, or nothing recorded since) it falls back to the turns'
 * own (`scans` drawn as the timeline). A start among merged scans is said to
 * be approximate.
 */
export function ledgerChanges(ledger: SessionLedger, turns: SessionChanges, edited: ReadonlySet<string>, scans: readonly string[], began: number | null): SessionChanges {
  const when = began === null ? '' : ` (${stamp(began)})`
  if (!ledger.complete) {
    const reached = ledger.reached
    if (reached === undefined || reached === null || typeof reached.at !== 'number' || !Number.isFinite(reached.at)) {
      return { ...turns, fallback: `The scan record does not reach back to when this session began${when}, so only what its turns reported is listed.`, timeline: ownTimeline(scans) }
    }
    const from = stamp(reached.at * 1000)
    return {
      ...fromLedger(ledger, turns, edited, new Set(scans)),
      fallback: `The scan record does not reach back to when this session began${when}. It lists every change since ${from}, the oldest point it still holds.`,
      since: `since ${from}`,
    }
  }
  const shown = fromLedger(ledger, turns, edited, new Set(scans))
  return ledger.start_approximate === true ? { ...shown, fallback: APPROXIMATE_START } : shown
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
  // Vitest or Jest, as the project's package.json says (`JsRunners`).
  js: { test: /\.(spec|test)\.[cm]?[jt]sx?$/, invoked: /\b(vitest|jest)\b|\b(npm|yarn|pnpm)\s+(run\s+)?test(:[\w-]+)?\b/ },
  pytest: { test: /(^|\/)test_[^/]*\.py$|_test\.py$/, invoked: /\bpytest\b/ },
  go: { test: /_test\.go$/, invoked: /\bgo\s+test\b/ },
  cargo: { test: /\.rs$/, invoked: /\bcargo\s+(test|nextest)\b/ },
} as const

/** The runner a test path calls for, or null for a path no runner runs (a helper, a fixture). */
export function runnerOf(path: string): keyof typeof RUNNERS | null {
  return (Object.keys(RUNNERS) as (keyof typeof RUNNERS)[]).find(r => RUNNERS[r].test.test(path)) ?? null
}

/** The runner of each JavaScript test as the turn brief read it from package.json; null or absent when unknown. */
export type JsRunners = Readonly<Record<string, JsRunner | null>>

/** Whether a command names `test` itself: a PHPUnit or pytest test, or a script whose runner is known. */
export function namedByCommand(test: string, js: JsRunners = {}): boolean {
  const runner = runnerOf(test)
  return runner === 'phpunit' || runner === 'pytest' || (runner === 'js' && (js[test] ?? null) !== null)
}

/**
 * A command that runs `tests`, by the runner their paths call for: PHPUnit
 * for `*Test.php` (the file, or a `--filter` over the classes for several),
 * Vitest or Jest for `*.spec.*` and `*.test.*` scripts as the project's
 * package.json says (`js`), pytest for `test_*.py` and `*_test.py`, `go test`
 * per package for `*_test.go`, `cargo test` for Rust. A script whose runner is
 * unknown is left out rather than handed to a runner that may not be there.
 * Several runners are joined with `&&`; null when no path names a runner.
 * The paths are relative to the project root, so a command run from
 * elsewhere (`cd`, see `cdFor`) changes to it first.
 */
export function testCommand(tests: string[], js: JsRunners = {}, cd: string | null = null): string | null {
  const of = (runner: keyof typeof RUNNERS) => tests.filter(t => runnerOf(t) === runner)
  const php = of('phpunit')
  const vitest = of('js').filter(t => js[t] === 'vitest')
  const jest = of('js').filter(t => js[t] === 'jest')
  const pytest = of('pytest')
  const packageOf = (t: string) => (t.includes('/') ? `./${t.slice(0, t.lastIndexOf('/'))}` : '.')
  const go = [...new Set(of('go').map(packageOf))]
  const commands: string[] = []
  if (php.length === 1) commands.push(`vendor/bin/phpunit ${quote(php[0]!)}`)
  if (php.length > 1) commands.push(`vendor/bin/phpunit --filter ${quote(`(${[...new Set(php.map(t => baseName(t).slice(0, -4)))].join('|')})`)}`)
  if (vitest.length > 0) commands.push(`npx vitest run ${vitest.map(quote).join(' ')}`)
  if (jest.length > 0) commands.push(`npx jest ${jest.map(quote).join(' ')}`)
  if (pytest.length > 0) commands.push(`python -m pytest ${pytest.map(quote).join(' ')}`)
  if (go.length > 0) commands.push(`go test ${go.map(quote).join(' ')}`)
  if (of('cargo').length > 0) commands.push('cargo test')
  if (commands.length === 0) return null
  return cd === null ? commands.join(' && ') : `cd ${quote(cd)} && ${commands.join(' && ')}`
}

/**
 * Where a test command must change to before it runs: the project root, when
 * the session (where Claude's shell starts) sits elsewhere, such as below an
 * ancestor project; null when they are one, or either is unknown.
 */
export function cdFor(projectRoot: string | null, sessionRoot: string | null): string | null {
  const trim = (p: string) => (p.length > 1 ? p.replace(/\/+$/, '') : p)
  return projectRoot === null || sessionRoot === null || trim(projectRoot) === trim(sessionRoot) ? null : projectRoot
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
export function rankIn(hues: Hues): (boundary: string) => number {
  const order = [...hues.keys()]
  return b => (order.includes(b) ? order.indexOf(b) : order.length)
}

/** A changed file as the Changes tab lists it; `tests` how many test files reach it, null when that is not known. */
export type TouchedFile = { path: string; status: TouchStatus; boundary: string | null; dependents: number; loc: Loc | null; origin?: 'session' | 'outside'; tests: number | null }
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
  /** The files still there that no test reaches, as far as that is known. */
  untested: number
  truncated: boolean
  /** Whether the files are every change since the session began (from the scan ledger), each with its origin. */
  sinceStart: boolean
  /** What to say about where the files start (only the turn briefs', an older start, an approximate one), when there is something to say. */
  fallback: string | null
  /** Where the files start when it is not where the session began (`since 2026-10-04 09:14`). */
  since: string | null
  /** The session's scans, oldest first, by whose changes each took in; `earlier` counts the older ones left out. */
  timeline: { origin: 'session' | 'outside' }[]
  earlier: boolean
}

/**
 * The Changes tab's view of the session's changes; `root` places the files on
 * disk, and the command changes to it when `sessionRoot` is elsewhere.
 */
export function changesInput(changes: SessionChanges, root: string | null, hues: Hues = NO_HUES, sessionRoot: string | null = null): ChangesInput {
  const files = Object.entries(changes.files)
    .map(([path, f]) => ({
      path,
      status: f.status,
      // The file's own boundary; its dependents' are where the change reaches, not where it is.
      boundary: f.boundary ?? null,
      dependents: f.dependents,
      loc: f.status === 'deleted' ? null : locIn(root, path),
      ...(changes.origins === undefined ? {} : { origin: changes.origins[path] ?? 'outside' }),
      tests: f.status === 'deleted' || typeof f.tests !== 'number' ? null : f.tests,
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
    command: testCommand(tests.map(t => t.path), changes.js_runners, cdFor(root, sessionRoot)),
    boundaries: reached.sort((a, b) => rank(a) - rank(b) || a.localeCompare(b)),
    violations: changes.violations.length,
    untested: files.filter(f => f.tests === 0).length,
    truncated: changes.truncated,
    sinceStart: changes.origins !== undefined,
    fallback: changes.fallback ?? null,
    since: changes.since ?? null,
    timeline: (changes.timeline ?? []).map(t => ({ origin: t.origin })),
    earlier: changes.timeline_truncated === true,
  }
}

/**
 * The session's scans as one thin row: a dot per scan, oldest first, in the
 * accent for one that took in this session's own changes and dim for one
 * that took in changes made outside it, then the two counts. A timeline
 * longer than the row keeps its newest scans behind a `…`. Null with no scan.
 */
export function timelineRow(input: Pick<ChangesInput, 'timeline' | 'earlier'>, columns: number): Row | null {
  const scans = input.timeline
  if (scans.length === 0) return null
  const own = scans.filter(t => t.origin === 'session').length
  const counts: Segment[] = [
    { text: '  ' },
    { text: grouped(own), color: ACCENT },
    { text: ' this session', dim: true },
    ...(own === scans.length ? [] : [{ text: ' · ', dim: true }, { text: grouped(scans.length - own), color: HEADING }, { text: ' outside', dim: true }]),
  ]
  const lead: Segment = { text: '   scans ', dim: true }
  const room = Math.max(1, columns - cells(lead.text) - segmentsWidth(counts))
  const cut = input.earlier || scans.length > room
  const shown = scans.slice(-Math.max(1, cut ? room - 1 : room))
  const dots: Segment[] = shown.map(t => (t.origin === 'session' ? { text: TIMELINE_DOT, color: ACCENT } : { text: TIMELINE_DOT, dim: true }))
  // Runs of one origin as one segment: a long timeline is a handful of segments, not one per scan.
  const runs = dots.reduce<Segment[]>((out, d) => {
    const last = out[out.length - 1]
    if (last !== undefined && last.color === d.color && last.dim === d.dim) out[out.length - 1] = { ...last, text: last.text + d.text }
    else out.push(d)
    return out
  }, [])
  return { key: 'changes-timeline', segments: clip([lead, ...(cut ? [{ text: '…', dim: true }] : []), ...runs, ...(columns - cells(lead.text) - shown.length - (cut ? 1 : 0) >= segmentsWidth(counts) ? counts : [])], columns) }
}

/** One scan on the timeline. */
const TIMELINE_DOT = '●'

/** The files the Changes tab walks: each opens as a file's detail (who depends on it), and `e` opens it in the editor. */
export function changesList(input: ChangesInput): Openable[] {
  return input.files.map(f => ({ name: f.path, canonical: f.path, loc: f.loc, file: true, changed: true }))
}

/** What the Overview's session card reads: the touched file with the most dependents, how many tests reach the changes, and their command. */
export type LookAt = { file: TouchedFile | null; tests: number; command: string | null; plus: boolean }

/** The session card's figures from the session's changes; null before anything changed. */
export function lookAtOf(input: ChangesInput): LookAt | null {
  if (input.files.length === 0) return null
  const file = input.files.find(f => f.status !== 'deleted') ?? null
  return { file, tests: input.tests.length, command: input.command, plus: input.truncated }
}

/**
 * A line of what something reaches: `lead` (the files and their dependents,
 * or nothing), then the best ranked few boundaries in their colours and the rest counted,
 * so the line stays a line and not a rainbow. Fewer are named when the pane
 * is narrow, so the count never wraps onto a line of its own.
 */
export function reachRows(key: string, lead: string, boundaries: string[], columns: number, hues: Hues = NO_HUES): Row[] {
  const groups = (shown: number): Segment[][] => {
    const named = boundaries.slice(0, shown)
    const out: Segment[][] = lead === '' ? [] : [[{ text: lead, dim: true }]]
    if (named.length > 0) out.push([{ text: 'reaching', dim: true }], ...named.map(b => chip(b, hues)))
    if (boundaries.length > named.length) out.push([{ text: `+${boundaries.length - named.length} more`, dim: true }])
    return out
  }
  const shown = [REACH_SHOWN, 2, 1].find(n => wrapGroups(key, groups(n), columns, 1, 3).length === 1) ?? REACH_SHOWN
  return wrapGroups(key, groups(shown), columns, 1, 3)
}

/** A one-cell mark for how a file stands: `+` added, `−` deleted, nothing for changed. */
export function statusMark(status: TouchStatus): Segment {
  if (status === 'added') return { text: '+', color: STATUS_COLOURS.ok }
  return status === 'deleted' ? { text: '−', color: STATUS_COLOURS.alert } : { text: ' ' }
}

/** The fewest changed files and tests the Changes tab lists, however short the pane. */
const FILES_MIN = 5
const TESTS_MIN = 3

/**
 * The Changes tab's cards: every file this session touched, most dependents
 * first, with the boundary it sits in and, since the session began, where
 * each change came from; then the tests that reach the changes, nearest
 * first, and the command that runs them. Wide, the tests stand beside the
 * files.
 */
export function changesArrangement(input: ChangesInput, selected: number, tier: Tier, hues: Hues = NO_HUES): Arrangement {
  const files: Block = { key: 'changes', grow: { length: input.files.length, min: FILES_MIN }, make: (columns, limit) => filesSection(input, selected, columns, limit, tier, hues) }
  if (input.files.length === 0) return { left: [files] }
  const tests: Block = { key: 'tests', grow: { length: input.tests.length, min: TESTS_MIN }, make: (columns, limit) => testsSection(input, columns, limit, hues) }
  // Wide, the two side by side on the grid, equally tall.
  return { left: [files], right: [tests], rows: [[files, tests]], split: 0.5 }
}

/** The changed files' card, its list `limit` rows long around the marker. */
function filesSection(input: ChangesInput, selected: number, columns: number, limit: number, tier: Tier, hues: Hues): Section {
  const rows: Row[] = []
  const said = (key: string, text: string) => wrapWords(text, Math.max(1, columns - 3)).forEach((line, i) => rows.push(dimRow(`${key}-${i}`, `   ${line}`, columns)))
  const title = 'Changes this session'
  const timeline = timelineRow(input, columns)
  if (timeline !== null) rows.push(timeline)
  if (input.files.length === 0) {
    if (input.fallback !== null) said('changes-fallback', input.fallback)
    said(
      'changes-none',
      input.sinceStart
        ? `Nothing changed in this project ${input.since ?? 'since this session began'}.`
        : "Nothing yet. The files Claude edits show here after each turn's scan, with what depends on them and the tests that reach them.",
    )
    return { key: 'changes', title, body: rows }
  }
  const deps = input.files.reduce((n, f) => n + f.dependents, 0)
  // Every change since the session began says where it came from, in a column of its own at the end.
  const origin = input.sinceStart && columns > ORIGIN_WIDTH + 20 ? ORIGIN_WIDTH + 1 : 0
  // How many tests reach each file, when that is known: a file none reaches is marked in the warning tone.
  const tested = input.files.some(f => f.tests !== null) && columns - origin > TESTS_WIDTH + 24 ? TESTS_WIDTH + 1 : 0
  const spec = tableSpec(columns - origin - tested, input.files.map(f => f.path), input.files.map(f => boundaryLabel(f.boundary)), [numberWidth('deps', input.files.map(f => f.dependents), tier)], PATH_MAX, { tier })
  const max = Math.max(0, ...input.files.map(f => f.dependents))
  const note = `${input.sinceStart ? (input.since ?? 'since it began') : plural(input.turns, 'turn', 'turns')}${input.truncated ? ' · partial' : ''}`
  if (input.fallback !== null) said('changes-fallback', input.fallback)
  // What the changes reach: the dependents, and every boundary they are in, each in its colour.
  rows.push(...reachRows('changes-reach', `${plural(input.files.length, 'file', 'files')} → ${grouped(deps)} dependents`, input.boundaries, columns, hues))
  if (input.violations > 0) {
    rows.push({ key: 'changes-policy', segments: [{ text: '   ' }, { text: `▲ ${plural(input.violations, 'policy violation', 'policy violations')} introduced`, color: STATUS_COLOURS.alert }] })
  }
  if (input.untested > 0) rows.push({ key: 'changes-untested', segments: [{ text: '   ' }, { text: `▲ ${untestedText(input.untested)}`, color: STATUS_COLOURS.warn }] })
  const head = tableHead('changes-cols', spec, { name: 'file', boundary: 'boundary', numbers: ['deps'] })
  rows.push({
    ...head,
    segments: [...head.segments, ...(tested === 0 ? [] : [{ text: ` ${padStart('tests', TESTS_WIDTH)}`, dim: true }]), ...(origin === 0 ? [] : [{ text: ` ${padEnd('from', ORIGIN_WIDTH)}`, dim: true }])],
  })
  const window = windowOf(input.files.length, limit, selected)
  input.files.slice(window.start, window.end).forEach((f, n) => {
    const i = window.start + n
    const line = tableRow(`change-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, selected: i === selected, mark: statusMark(f.status), path: true, press: `row:${i}` }, spec, hues)
    const added: Segment[] = [...(tested === 0 ? [] : [{ text: ' ' }, testsCell(f.tests)]), ...(origin === 0 || f.origin === undefined ? [] : [{ text: ' ' }, originCell(f.origin)])]
    return rows.push(added.length === 0 ? line : { ...line, segments: [...line.segments, ...(line.tint === undefined ? added : tinted(added, line.tint))] })
  })
  rows.push(...moreRows('changes-more', window, input.files.length, columns))
  return { key: 'changes', title, note: noteOf(note), body: rows }
}

/** The cells the Changes tab's tests column takes: its title, `▲ none` and a count. */
const TESTS_WIDTH = 6
/** How many tests the per-file count names before it is a floor (as `FileTestReach::COUNTED`). */
const TESTS_COUNTED = 20

/** How many changed files no test reaches, said as a warning. */
export const untestedText = (n: number): string => `${plural(n, 'file', 'files')} no test reaches`

/** A changed file's tests cell: `▲ none` in the warning tone, the count dim (`20+` at the cap), blank when not known. */
function testsCell(tests: number | null): Segment {
  if (tests === null) return { text: spaces(TESTS_WIDTH) }
  if (tests === 0) return { text: padStart('▲ none', TESTS_WIDTH), color: STATUS_COLOURS.warn }
  return { text: padStart(tests >= TESTS_COUNTED ? `${TESTS_COUNTED}+` : String(tests), TESTS_WIDTH), dim: true }
}

/** A changed file's origin cell: this session's in the accent, outside dim. */
const originCell = (origin: 'session' | 'outside'): Segment =>
  origin === 'session' ? { text: padEnd(ORIGIN_LABELS.session, ORIGIN_WIDTH), color: ACCENT } : { text: padEnd(ORIGIN_LABELS.outside, ORIGIN_WIDTH), dim: true }

/** The tests that reach the changes, nearest first, `limit` of them, and the command that runs them all. */
function testsSection(input: ChangesInput, columns: number, limit: number, hues: Hues): Section {
  const count = `${grouped(input.tests.length)}${input.truncated ? '+' : ''}`
  const title = 'Tests that reach these changes'
  if (input.tests.length === 0) {
    return { key: 'tests', title, body: [{ key: 'tests-none', segments: [{ text: '   ' }, { text: '▲ no test reaches these changes', color: STATUS_COLOURS.warn }] }] }
  }
  const window = windowOf(input.tests.length, limit)
  const shown = input.tests.slice(0, window.end)
  const spec = { ...tableSpec(columns, shown.map(t => t.path), [], [numberWidth('hops', shown.map(t => t.distance))], PATH_MAX), bar: 0 }
  const rows: Row[] = [tableHead('tests-cols', spec, { name: 'test', boundary: '', numbers: ['hops'] })]
  shown.forEach((t, i) => rows.push(tableRow(`test-${i}`, { name: t.path, boundary: null, values: [t.distance], max: 0, path: true, link: t.loc }, spec, hues)))
  rows.push(...moreRows('tests-more', window, input.tests.length, columns))
  if (input.command !== null) {
    rows.push(blank('gap-command'))
    wrapWords(input.command, Math.max(1, columns - 5)).forEach((line, i) =>
      rows.push({ key: `command-${i}`, segments: [{ text: i === 0 ? '   $ ' : '     ', dim: true }, { text: line, color: HEADING }] }),
    )
  }
  return { key: 'tests', title, subtitle: count, note: noteOf('nearest first'), body: rows }
}
