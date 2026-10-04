import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, SessionChanges, SessionLedger, TurnBrief } from '../../types'
import { accumulate, cdFor, changesInput, changesList, FILE_CAP, fromLedger, lookAtOf, NO_CHANGES, ownTimeline, testCommand, testsRan, timelineRow } from './changes'
import { changesRows, lookAtRows } from './__tests__/tabs'
import { editTarget, paneInput, paneRows } from './layout'
import { findRow, plainText } from './__tests__/plain-text'
import { fileHref, linkMarkdown, locOf, rowWidth } from './rows'
import type { Row } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const ROOT = '/work/app'

const brief = (over: Partial<TurnBrief> = {}): TurnBrief => ({
  status: 'ok',
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  scanned_at: 0,
  scan_ms: 5,
  reason: null,
  roots_file: null,
  refused_root: null,
  path: ROOT,
  changed_files: ['src/Router.php'],
  added_files: [],
  deleted_files: [],
  impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http', 'Core'], boundary: 'Http' } },
  tests: [{ path: 'tests/Http/RouterTest.php', distance: 2 }],
  policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
  ...over,
})

const textOf = (rows: Row[]) => rows.map(plainText).join('\n')
const row = findRow

/** Two turns: the router edited twice, a kernel added, a helper deleted. */
function session(): SessionChanges {
  const first = accumulate(NO_CHANGES, brief())
  return accumulate(
    first,
    brief({
      changed_files: ['src/Router.php'],
      added_files: ['src/Core/Kernel.php'],
      deleted_files: ['src/Helper.php'],
      impact: {
        'src/Router.php': { path: 'src/Router.php', dependent_files: 42, boundaries: ['Http'], boundary: 'Http' },
        'src/Core/Kernel.php': { path: 'src/Core/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'Core' },
      },
      tests: [
        { path: 'tests/Http/RouterTest.php', distance: 1 },
        { path: 'tests/Core/KernelTest.php', distance: 3 },
      ],
      policy: {
        status: 'evaluated',
        total: 1,
        truncated: false,
        violations: [{ policy_id: 'p', source: 'App\\Core\\Kernel', target: 'App\\Http\\Router', source_boundaries: [], target_boundaries: [] }],
      },
    }),
  )
}

describe('accumulate', () => {
  it('adds up the files, keeps each at its latest dependents, and each test at its nearest distance', () => {
    const s = session()
    expect(s.turns).toBe(2)
    expect(s.files).toEqual({
      'src/Router.php': { status: 'changed', dependents: 42, boundaries: ['Http'], boundary: 'Http' },
      'src/Core/Kernel.php': { status: 'added', dependents: 3, boundaries: ['Core'], boundary: 'Core' },
      'src/Helper.php': { status: 'deleted', dependents: 0, boundaries: [], boundary: null },
    })
    expect(s.tests).toEqual({ 'tests/Http/RouterTest.php': 1, 'tests/Core/KernelTest.php': 3 })
    expect(s.violations).toEqual(['App\\Core\\Kernel → App\\Http\\Router'])
    expect(s.truncated).toBe(false)
  })
  it('keeps an added file added while it is edited, and a deleted one deleted until it is written again', () => {
    const added = accumulate(NO_CHANGES, brief({ changed_files: [], added_files: ['a.php'], impact: {} }))
    expect(accumulate(added, brief({ changed_files: ['a.php'], impact: {} })).files['a.php']?.status).toBe('added')
    const deleted = accumulate(added, brief({ changed_files: [], deleted_files: ['a.php'], impact: {} }))
    expect(deleted.files['a.php']?.status).toBe('deleted')
    expect(accumulate(deleted, brief({ changed_files: [], added_files: ['a.php'], impact: {} })).files['a.php']?.status).toBe('changed')
  })
  it('counts a violation once however many turns report it', () => {
    const s = session()
    const again = accumulate(s, brief({ policy: { status: 'evaluated', total: 1, truncated: false, violations: [{ policy_id: 'p', source: 'App\\Core\\Kernel', target: 'App\\Http\\Router', source_boundaries: [], target_boundaries: [] }] } }))
    expect(again.violations).toHaveLength(1)
  })
  it("keeps each script's runner from its brief, so the Changes tab runs a Jest project with Jest", () => {
    const jest = accumulate(NO_CHANGES, brief({ tests: [{ path: 'src/a.test.js', distance: 1, js_runner: 'jest' }, { path: 'src/b.test.js', distance: 2, js_runner: null }] }))
    expect(jest.js_runners).toEqual({ 'src/a.test.js': 'jest', 'src/b.test.js': null })
    expect(changesInput(jest, ROOT).command).toBe('npx jest src/a.test.js')
    // A later brief that no longer names the runner keeps the one it had.
    expect(accumulate(jest, brief({ tests: [{ path: 'src/a.test.js', distance: 1 }] })).js_runners?.['src/a.test.js']).toBe('jest')
  })
  it('ignores a brief that is not ok', () => {
    expect(accumulate(NO_CHANGES, brief({ status: 'scan-failed' }))).toBe(NO_CHANGES)
  })
  it('stops growing at its cap and says so', () => {
    const many = Array.from({ length: FILE_CAP + 3 }, (_, i) => `src/F${i}.php`)
    const s = accumulate(NO_CHANGES, brief({ changed_files: many, impact: {} }))
    expect(Object.keys(s.files)).toHaveLength(FILE_CAP)
    expect(s.truncated).toBe(true)
    expect(lookAtOf(changesInput(s, ROOT))?.plus).toBe(true)
  })
})

describe('testCommand', () => {
  it('runs one PHPUnit test by its file and several by a filter over their classes', () => {
    expect(testCommand(['tests/Http/RouterTest.php'])).toBe('vendor/bin/phpunit tests/Http/RouterTest.php')
    expect(testCommand(['tests/Http/RouterTest.php', 'tests/Core/KernelTest.php'])).toBe("vendor/bin/phpunit --filter '(RouterTest|KernelTest)'")
  })
  it('leaves out a PHP file that is no test class, such as a helper', () => {
    expect(testCommand(['tests/Support/Assertions.php'])).toBeNull()
  })
  it('picks the runner from each path and joins several', () => {
    expect(testCommand(['hooks/lib/band.spec.ts', 'web/app.test.tsx'], { 'hooks/lib/band.spec.ts': 'vitest', 'web/app.test.tsx': 'vitest' })).toBe(
      'npx vitest run hooks/lib/band.spec.ts web/app.test.tsx',
    )
    expect(testCommand(['tests/test_scan.py', 'pkg/walk_test.py'])).toBe('python -m pytest tests/test_scan.py pkg/walk_test.py')
    expect(testCommand(['internal/scan/walk_test.go', 'internal/scan/read_test.go', 'main_test.go'])).toBe('go test ./internal/scan .')
    expect(testCommand(['workers/rust/tests/scan.rs'])).toBe('cargo test')
    expect(testCommand(['tests/ATest.php', 'a.spec.ts'], { 'a.spec.ts': 'vitest' })).toBe('vendor/bin/phpunit tests/ATest.php && npx vitest run a.spec.ts')
  })
  it("runs a script with the runner the project's package.json names, and leaves out one whose runner is unknown", () => {
    expect(testCommand(['src/a.test.js', 'web/b.spec.ts'], { 'src/a.test.js': 'jest', 'web/b.spec.ts': 'vitest' })).toBe('npx vitest run web/b.spec.ts && npx jest src/a.test.js')
    expect(testCommand(['src/a.test.js'])).toBeNull()
    expect(testCommand(['src/a.test.js', 'tests/ATest.php'], { 'src/a.test.js': null })).toBe('vendor/bin/phpunit tests/ATest.php')
  })
  it('changes to the project root first when the session sits elsewhere', () => {
    expect(cdFor('/work/app', '/work/app/packages/web')).toBe('/work/app')
    expect(cdFor('/work/app', '/work/app/')).toBeNull()
    expect(cdFor('/work/app', null)).toBeNull()
    expect(cdFor(null, '/work')).toBeNull()
    expect(testCommand(['tests/ATest.php'], {}, "/work/my app")).toBe("cd '/work/my app' && vendor/bin/phpunit tests/ATest.php")
    expect(changesInput(session(), ROOT, undefined, `${ROOT}/packages/web`).command).toBe(`cd ${ROOT} && vendor/bin/phpunit --filter '(RouterTest|KernelTest)'`)
    expect(changesInput(session(), ROOT, undefined, ROOT).command).toBe("vendor/bin/phpunit --filter '(RouterTest|KernelTest)'")
  })
  it('quotes a path a shell would split', () => {
    expect(testCommand(["tests/it's here/a.spec.ts"], { "tests/it's here/a.spec.ts": 'vitest' })).toBe(`npx vitest run 'tests/it'\\''s here/a.spec.ts'`)
  })
  it('is null when no path names a runner', () => {
    expect(testCommand([])).toBeNull()
    expect(testCommand(['docs/readme.md'])).toBeNull()
  })
})

describe('own boundary', () => {
  it("labels each file with the boundary it sits in, never its dependents'", () => {
    const c = changesInput(
      accumulate(
        NO_CHANGES,
        brief({
          changed_files: ['bin/router.php', 'src/Core/Kernel.php'],
          impact: {
            'bin/router.php': { path: 'bin/router.php', dependent_files: 9, boundaries: ['tests', 'Core'], boundary: null },
            'src/Core/Kernel.php': { path: 'src/Core/Kernel.php', dependent_files: 3, boundaries: ['tests'], boundary: 'Core' },
          },
        }),
      ),
      ROOT,
      new Map([['tests', 'blue'], ['Core', 'purple']]),
    )
    expect(c.files.map(f => [f.path, f.boundary])).toEqual([
      ['bin/router.php', null],
      ['src/Core/Kernel.php', 'Core'],
    ])
    // Where the changes reach is still the dependents' boundaries.
    expect(c.boundaries).toEqual(['tests', 'Core'])
    const rows = changesRows(c, 0, 90)
    expect(plainText(row(rows, 'change-0')!)).not.toContain('tests')
    expect(plainText(row(rows, 'change-1')!)).toMatch(/src\/Core\/Kernel\.php +Core/)
  })
  it('reads an older knossos without the field as unassigned', () => {
    const old = accumulate(NO_CHANGES, brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 4, boundaries: ['Http'] } } }))
    expect(changesInput(old, ROOT).files[0]?.boundary).toBeNull()
  })
})

describe('changesInput and changesList', () => {
  it('lists the most depended on first, places files on disk except deleted ones, and the nearest tests first', () => {
    const c = changesInput(session(), ROOT)
    expect(c.files.map(f => [f.path, f.status, f.dependents])).toEqual([
      ['src/Router.php', 'changed', 42],
      ['src/Core/Kernel.php', 'added', 3],
      ['src/Helper.php', 'deleted', 0],
    ])
    expect(c.files[0]?.loc).toEqual({ path: '/work/app/src/Router.php', line: null })
    expect(c.files[2]?.loc).toBeNull()
    expect(c.tests.map(t => t.path)).toEqual(['tests/Http/RouterTest.php', 'tests/Core/KernelTest.php'])
    expect(c.boundaries).toEqual(['Core', 'Http'])
    expect(c.violations).toBe(1)
    expect(changesList(c).map(o => [o.canonical, o.file])).toEqual([
      ['src/Router.php', true],
      ['src/Core/Kernel.php', true],
      ['src/Helper.php', true],
    ])
  })
})

describe('changesRows', () => {
  it('says what will show before anything changed', () => {
    expect(textOf(changesRows(changesInput(NO_CHANGES, ROOT), 0, 60))).toMatch(/Changes this session\n {3}Nothing yet. The files Claude edits show here/)
  })
  it('draws files with their marks, each pressable to its detail, the tests, and the command', () => {
    const rows = changesRows(changesInput(session(), ROOT), 1, 90)
    const text = textOf(rows)
    expect(text).toMatch(/Changes this session +2 turns/)
    expect(text).toContain('3 files → 45 dependents reaching Core Http')
    expect(text).toContain('▲ 1 policy violation introduced')
    expect(plainText(row(rows, 'change-1')!)).toMatch(/^›\+ src\/Core\/Kernel\.php +Core +━/)
    expect(plainText(row(rows, 'change-2')!)).toMatch(/^ − src\/Helper\.php/)
    // A file opens as its detail, by the same index the marker walks; `e` opens it in the editor.
    expect(row(rows, 'change-0')!.segments.find(s => s.press)?.press).toEqual({ id: 'row:0', label: 'src/Router.php' })
    expect(row(rows, 'change-2')!.segments.find(s => s.press)?.press?.id).toBe('row:2')
    expect(text).toMatch(/Tests that reach these changes · 2 +nearest first/)
    expect(text).toContain("$ vendor/bin/phpunit --filter '(RouterTest|KernelTest)'")
  })
  it('names fewer boundaries on a narrow pane rather than leave the count on a line of its own', () => {
    const many = { ...changesInput(session(), ROOT), boundaries: ['tests', 'core', 'php-worker', 'tooling', 'hooks', 'types'] }
    for (const columns of [56, 60, 90]) {
      const reach = changesRows(many, 0, columns).filter(r => r.key.startsWith('changes-reach'))
      expect(reach, `${columns}`).toHaveLength(1)
      expect(plainText(reach[0]!), `${columns}`).toMatch(/\+\d more$/)
    }
    expect(plainText(changesRows(many, 0, 90).find(r => r.key.startsWith('changes-reach'))!)).toContain('reaching tests core php-worker +3 more')
  })
  it('warns when no test reaches the changes', () => {
    const none = accumulate(NO_CHANGES, brief({ tests: [] }))
    expect(textOf(changesRows(changesInput(none, ROOT), 0, 60))).toContain('▲ no test reaches these changes')
  })
  it('never draws wider than the columns', () => {
    const long = accumulate(
      session(),
      brief({
        changed_files: ['src/a/rather/deeply/nested/directory/with/a/VeryLongControllerName.php'],
        impact: { 'src/a/rather/deeply/nested/directory/with/a/VeryLongControllerName.php': { path: 'x', dependent_files: 12_345, boundaries: ['module:hooks (+typescript:hooks/tsconfig.json)'] } },
        tests: [{ path: 'tests/a/rather/deeply/nested/directory/with/a/VeryLongControllerNameTest.php', distance: 1 }],
      }),
    )
    for (const columns of WIDTHS) {
      for (const r of changesRows(changesInput(long, ROOT), 0, columns)) expect(rowWidth(r), `${columns} ${r.key}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
    }
  })
})

describe('look at now', () => {
  it('draws a file in no boundary without a gap where the boundary would be', () => {
    const none = accumulate(NO_CHANGES, brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: null } } }))
    expect(plainText(row(lookAtRows(lookAtOf(changesInput(none, ROOT))!, 60), 'look-file')!)).toBe('   Router.php · 41 dependents')
  })
  it('points at the riskiest file still there, and counts the tests that reach the changes', () => {
    const look = lookAtOf(changesInput(session(), ROOT))!
    expect(look.file?.path).toBe('src/Router.php')
    expect(look.tests).toBe(2)
    // Scoped in its header: these counts are the session's, never the last turn's.
    const rows = lookAtRows(look, 60, undefined, 0)
    expect(plainText(row(rows, 'look-head')!)).toMatch(/^Look at now +this session$/)
    // The first row the Overview's marker walks: pressed, it opens as the file's detail.
    expect(plainText(row(rows, 'look-file')!)).toBe('›  Router.php Http · 42 dependents')
    expect(row(rows, 'look-file')!.segments.find(s => s.press)?.press?.id).toBe('row:0')
    expect(plainText(row(rows, 'look-tests')!)).toBe('   t: copy test command   2 tests reach these changes')
    // Narrow, the count goes onto its own line rather than off the edge.
    const narrow = lookAtRows(look, 40).filter(r => r.key.startsWith('look-tests'))
    expect(narrow.map(r => plainText(r).trim())).toEqual(['t: copy test command', '2 tests reach these changes'])
    expect(lookAtOf(changesInput(NO_CHANGES, ROOT))).toBeNull()
  })
  it('sits first on the Overview, marked, where e opens its file, at every width', () => {
    const d = { ...dash(), project_root: ROOT } as Dashboard
    const v: KnossosView = { inspect: null, isBandHidden: false, tab: 'overview', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }
    const pane = paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, session())
    expect(editTarget(pane)).toEqual({ path: '/work/app/src/Router.php', line: null })
    for (const columns of WIDTHS) {
      const rows = paneRows(pane, columns)
      const at = (key: string) => rows.findIndex(r => r.key.split('|').includes(key))
      expect(at('look-head')).toBeGreaterThanOrEqual(0)
      // Under the stat tiles (the band, or its line when narrow), before the most depended on.
      expect(at(columns < 80 ? 'tiles-line' : 'tiles-top')).toBeLessThan(at('look-head'))
      expect(at('look-head')).toBeLessThanOrEqual(at('top-head'))
      for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
    }
    // The tab carries how many files were touched.
    expect(plainText(paneRows(pane, 90).find(r => r.key === 'tabs')!)).toContain(' Changes³ ')
  })
})

describe('file links', () => {
  it('round-trips a place through its file: URL, spaces and all', () => {
    const loc = { path: '/work/my app/src/A#1.php', line: 12 }
    expect(fileHref(loc)).toBe('file:///work/my%20app/src/A%231.php#L12')
    expect(locOf(fileHref(loc))).toEqual(loc)
    expect(locOf(fileHref({ path: '/a/b.ts', line: null }))).toEqual({ path: '/a/b.ts', line: null })
    expect(locOf('https://example.com/a')).toBeNull()
    expect(locOf('file://relative/x')).toBeNull()
  })
  it('escapes what markdown would read in the label', () => {
    expect(linkMarkdown('my_file*.php:3', { path: '/a/my_file*.php', line: 3 })).toBe('[my\\_file\\*\\.php:3](file:///a/my_file%2A.php#L3)')
  })
  it('encodes every character that would end or break a link target, an unbalanced ) among them', () => {
    const loc = { path: "/work/a)b/(c)/it's!.php", line: 4 }
    expect(fileHref(loc)).toBe('file:///work/a%29b/%28c%29/it%27s%21.php#L4')
    expect(locOf(fileHref(loc))).toEqual(loc)
    const target = /\]\((.*)\)$/.exec(linkMarkdown('x', loc))?.[1] ?? ''
    expect(target).not.toMatch(/[()\s<>]/)
  })
})

function dash(): Dashboard {
  return {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [{ name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 }],
    hubs_truncated: false,
    hubs_truncation_reasons: [],
    hotspots: [],
    dead_code_candidates: 0,
    dead_code_truncated: false,
    cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
    trend: [],
    fan_in: [],
    fan_in_truncated: false,
  }
}

describe('testsRan', () => {
  it('counts a test as run by a command that names it, its directory, or no test at all', () => {
    expect(testsRan('tests/Http/RouterTest.php', ['vendor/bin/phpunit --filter RouterTest'])).toBe(true)
    expect(testsRan('tests/Http/RouterTest.php', ['vendor/bin/phpunit tests/Http'])).toBe(true)
    expect(testsRan('tests/Http/RouterTest.php', ['COMPOSER_ALLOW_SUPERUSER=1 vendor/bin/phpunit'])).toBe(true)
    expect(testsRan('tests/Http/RouterTest.php', ['composer test'])).toBe(true)
    expect(testsRan('tests/Http/RouterTest.php', ['vendor/bin/phpunit --filter KernelTest'])).toBe(false)
    expect(testsRan('tests/Http/RouterTest.php', ['vendor/bin/phpunit tests/Cli'])).toBe(false)
    expect(testsRan('tests/Http/RouterTest.php', ['npx vitest run'])).toBe(false)
    expect(testsRan('hooks/lib/changes.spec.ts', ['npm run test:mod'])).toBe(true)
    expect(testsRan('hooks/lib/changes.spec.ts', ['npx vitest run hooks/lib/changes.spec.ts'])).toBe(true)
    expect(testsRan('hooks/lib/changes.spec.ts', ['npx vitest run hooks/lib/layout.spec.ts'])).toBe(false)
    expect(testsRan('tests/test_x.py', ['python -m pytest -k other'])).toBe(false)
    expect(testsRan('pkg/a/x_test.go', ['go test ./...'])).toBe(true)
    expect(testsRan('src/lib.rs', ['cargo test'])).toBe(true)
    expect(testsRan('docs/readme.md', ['vendor/bin/phpunit'])).toBe(false)
  })
})

describe('the changes since the session began, from the scan ledger', () => {
  const ledger: SessionLedger = {
    status: 'ok',
    since: 's0',
    snapshot_id: 's9',
    complete: true,
    files: {
      'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'Http' },
      'src/Config/app.php': { status: 'added', dependents: 0, boundaries: [], boundary: null },
      'src/Old.php': { status: 'deleted', dependents: 0, boundaries: [], boundary: null },
    },
    files_truncated: false,
    tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }],
    tests_truncated: false,
  }
  const changes = fromLedger(ledger, session(), new Set(['src/Router.php']))

  it("labels each file by where its change came from, and keeps the turns' own violations", () => {
    expect(changes.origins).toEqual({ 'src/Router.php': 'session', 'src/Config/app.php': 'outside', 'src/Old.php': 'outside' })
    expect(changes.violations).toEqual(session().violations)
    expect(changes.tests).toEqual({ 'tests/Http/RouterTest.php': 1 })
    expect(fromLedger({ ...ledger, files_truncated: true }, NO_CHANGES, new Set()).truncated).toBe(true)
  })

  it("calls a file the session's when any scan that changed it took in the session's work, whatever wrote it", () => {
    const scanned: SessionLedger = {
      ...ledger,
      files: {
        'src/Router.php': { ...ledger.files['src/Router.php']!, scans: ['s1', 's4'] },
        'src/Config/app.php': { ...ledger.files['src/Config/app.php']!, scans: ['s2'] },
        'src/Old.php': { ...ledger.files['src/Old.php']! },
      },
    }
    expect(fromLedger(scanned, NO_CHANGES, new Set(), new Set(['s4'])).origins).toEqual({ 'src/Router.php': 'session', 'src/Config/app.php': 'outside', 'src/Old.php': 'outside' })
    // An older knossos names no scans: only the edit tools decide.
    expect(fromLedger(scanned, NO_CHANGES, new Set(['src/Old.php']), new Set(['s2'])).origins).toEqual({ 'src/Router.php': 'outside', 'src/Config/app.php': 'session', 'src/Old.php': 'session' })
  })

  it('draws the origin of every file at every width, within the width', () => {
    for (const columns of WIDTHS) {
      const rows = changesRows(changesInput(changes, ROOT), 0, columns)
      for (const r of rows) expect(rowWidth(r), `${columns}: ${plainText(r)}`).toBeLessThanOrEqual(columns)
      const text = textOf(rows)
      // The narrowest top edge has no room for the note.
      if (columns >= 60) expect(text).toContain('since it began')
      for (const key of ['change-0', 'change-1', 'change-2']) expect(plainText(row(rows, key)!)).toMatch(/(this session|outside) *$/)
      expect(plainText(row(rows, 'change-0')!)).toMatch(/this session *$/)
    }
  })

  it('says why only the turns are listed when it falls back, and what an empty ledger means', () => {
    const fallback = changesRows(changesInput({ ...session(), fallback: 'No live watcher: only what this session\'s turns reported.' }, ROOT), 0, 60)
    expect(textOf(fallback)).toContain('No live watcher')
    expect(textOf(fallback)).toContain('2 turns')
    const empty = changesRows(changesInput(fromLedger({ ...ledger, files: {}, tests: [] }, NO_CHANGES, new Set()), ROOT), 0, 60)
    expect(textOf(empty)).toContain('Nothing changed in this project since this session began.')
  })
})

describe("the session's scans as a timeline", () => {
  const timeline = (origins: string) => [...origins].map(o => ({ origin: o === 's' ? ('session' as const) : ('outside' as const) }))

  it('draws a dot a scan, oldest first, this session in the accent and outside dim, then the counts', () => {
    const r = timelineRow({ timeline: timeline('ssoss'), earlier: false }, 60)!
    expect(plainText(r)).toBe('   scans ●●●●●  4 this session · 1 outside')
    const dots = r.segments.filter(s => s.text.includes('●'))
    expect(dots.map(s => [s.text, s.color ?? (s.dim ? 'dim' : '')])).toEqual([
      ['●●', 'suggestion'],
      ['●', 'dim'],
      ['●●', 'suggestion'],
    ])
    // Only the session's own: no outside count.
    expect(plainText(timelineRow({ timeline: timeline('sss'), earlier: false }, 60)!)).toBe('   scans ●●●  3 this session')
    expect(timelineRow({ timeline: [], earlier: false }, 60)).toBeNull()
  })

  it('keeps its newest scans behind a … when it is longer than the row, or older ones were left out', () => {
    for (const columns of WIDTHS) {
      const r = timelineRow({ timeline: timeline('s'.repeat(120)), earlier: false }, columns)!
      expect(rowWidth(r), `${columns}`).toBeLessThanOrEqual(columns)
      if (columns < 160) expect(plainText(r), `${columns}`).toContain('…●')
      else expect(plainText(r), `${columns}`).toContain(`scans ${'●'.repeat(120)}  120 this session`)
    }
    expect(plainText(timelineRow({ timeline: timeline('so'), earlier: true }, 80)!)).toBe('   scans …●●  1 this session · 1 outside')
  })

  it('stands above the file list, from the ledger with each scan by whose changes it took in, else from the session\'s own', () => {
    const ledger: SessionLedger = {
      status: 'ok',
      since: 's0',
      snapshot_id: 's3',
      complete: true,
      files: { 'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'Http', scans: ['s1'] } },
      files_truncated: false,
      tests: [],
      tests_truncated: false,
      scans: [
        { snapshot_id: 's1', at: 10, files: 1 },
        { snapshot_id: 's2', at: 20, files: 3 },
        { snapshot_id: 's3', at: 30, files: 1 },
      ],
      scans_truncated: true,
    }
    const changes = fromLedger(ledger, NO_CHANGES, new Set(), new Set(['s1', 's3']))
    expect(changes.timeline).toEqual([
      { snapshot: 's1', origin: 'session' },
      { snapshot: 's2', origin: 'outside' },
      { snapshot: 's3', origin: 'session' },
    ])
    expect(changes.timeline_truncated).toBe(true)
    const rows = changesRows(changesInput(changes, ROOT), 0, 100)
    const at = (key: string) => rows.findIndex(r => r.key.split('|').includes(key))
    expect(at('changes-timeline')).toBeGreaterThan(at('changes-head'))
    expect(at('changes-timeline')).toBeLessThan(at('change-0'))
    expect(ownTimeline(['a', 'b'])).toEqual([
      { snapshot: 'a', origin: 'session' },
      { snapshot: 'b', origin: 'session' },
    ])
    // An older knossos names no scans: no timeline.
    expect(fromLedger({ ...ledger, scans: undefined }, NO_CHANGES, new Set()).timeline).toEqual([])
  })
})
