import { describe, expect, it } from 'vitest'
import { editNote, fanInIndex, freshViolations, readNote, ruleText, testsNote, violationKey, violationNote } from './notes'

describe('notes', () => {
  it('edit note names dependents, boundaries and the next step', () =>
    expect(editNote({ path: 'src/Router.php', dependent_files: 41, boundaries: ['Http', 'Core', 'Cli'] })).toBe(
      'knossos: src/Router.php has 41 dependent files across 3 boundaries; run test_impact before finishing.',
    ))
  it('edit note without boundaries', () =>
    expect(editNote({ path: 'a.php', dependent_files: 20, boundaries: [] })).toBe(
      'knossos: a.php has 20 dependent files; run test_impact before finishing.',
    ))
  it('no violation note when none', () => expect(violationNote({ policy: { status: 'evaluated', total: 0, violations: [] } } as never)).toBeNull())
  it('violation note lists each and asks for a fix', () => {
    const note = violationNote({ policy: { status: 'evaluated', total: 1, violations: [
      { policy_id: 'domain-isolation', source: 'App\\Domain\\Order', target: 'App\\Infra\\Db', source_boundaries: [], target_boundaries: [] },
    ] } } as never)
    expect(note).toBe(
      'knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:\n' +
      '- domain-isolation: App\\Domain\\Order → App\\Infra\\Db',
    )
  })
  it('says when the check was truncated and where the full one is', () => {
    const note = violationNote({ policy: { status: 'evaluated', total: 1, truncated: true, violations: [
      { policy_id: 'p', source: 'A', target: 'B', source_boundaries: [], target_boundaries: [] },
    ] } } as never)
    expect(note?.split('\n')[0]).toBe(
      'knossos: this turn introduced 1 boundary-policy violation (check was truncated; run check_architecture). Fix it before finishing:',
    )
  })
  it('says a truncated check found nothing it can vouch for', () =>
    expect(violationNote({ policy: { status: 'evaluated', total: 0, truncated: true, violations: [] } } as never)).toBe(
      'knossos: the boundary-policy check was truncated; run check_architecture to see violations in the files this turn edited.',
    ))
  it('says how many violations were left out of the list', () => {
    const note = violationNote({ policy: { status: 'evaluated', total: 3, violations: [
      { policy_id: 'p', source: 'A', target: 'B', source_boundaries: [], target_boundaries: [] },
    ] } } as never)
    expect(note).toContain('- …and 2 more (run review_diff)')
  })
  it('indexes the fan-in map by path', () =>
    expect(fanInIndex({ fan_in: [{ path: 'a.php', dependent_files: 3, boundaries: [] }] } as never).get('a.php')?.dependent_files).toBe(3))
  it('indexes nothing without a dashboard', () => expect(fanInIndex(null).size).toBe(0))
})

const RULES = [
  { id: 'workers-out', from: 'php-worker', deny: ['core'], allow: [], edge_kinds: [] },
  { id: 'core-alone', from: 'core', deny: ['php-worker', 'tests'], allow: [], edge_kinds: [] },
  { id: 'edge-only', from: 'edge', deny: [], allow: ['core', '@unassigned'], edge_kinds: ['calls'] },
]
const POLICY = { rules: RULES, files: { 'src/A.php': ['core'], 'src/B.php': ['core'], 'src/Edge/C.php': ['edge'] }, files_truncated: false }
const DECLARED = new Set(['core', 'edge', 'php-worker', 'tests'])
const hub = (path: string, n: number, boundary: string | null = 'core') => ({ path, dependent_files: n, boundaries: ['tests'], boundary })

describe('ruleText', () => {
  it('says what a boundary may not depend on, or may depend on alone', () => {
    expect(ruleText(RULES[1]!)).toBe('core may not depend on php-worker, tests')
    expect(ruleText(RULES[2]!)).toBe('edge may depend only on itself, core, unassigned code (calls)')
  })
})

describe('readNote', () => {
  it('gives a hub its dependents, its boundary and the rules that bind it', () =>
    expect(readNote('src/A.php', hub('src/A.php', 269), 20, POLICY, new Set(), DECLARED)).toEqual({
      text: 'knossos: src/A.php (core) has 269 dependent files. Policy: core may not depend on php-worker, tests.',
      ruled: ['core'],
    }))
  it('states a boundary rule once: a later file there gets its count alone', () =>
    expect(readNote('src/A.php', hub('src/A.php', 269), 20, POLICY, new Set(['core']), DECLARED)?.text).toBe('knossos: src/A.php (core) has 269 dependent files.'))
  it('gives a quiet file in a policed boundary its boundary and rules', () =>
    expect(readNote('src/Edge/C.php', undefined, 20, POLICY, new Set(), DECLARED)?.text).toBe(
      'knossos: src/Edge/C.php is in edge. Policy: edge may depend only on itself, core, unassigned code (calls).',
    ))
  it('says nothing for a quiet file whose rules were stated, or a quiet file no rule binds', () => {
    expect(readNote('src/B.php', hub('src/B.php', 3), 20, POLICY, new Set(['core']), DECLARED)).toBeNull()
    expect(readNote('docs/x.md', undefined, 20, POLICY, new Set(), DECLARED)).toBeNull()
    expect(readNote('src/A.php', undefined, 20, undefined, new Set(), DECLARED)).toBeNull()
  })
  it('names only a declared boundary: an inferred or missing one is left out', () => {
    expect(readNote('bin/router.php', hub('bin/router.php', 347, 'composer:app (+node:app)'), 20, POLICY, new Set(), DECLARED)?.text).toBe(
      'knossos: bin/router.php has 347 dependent files.',
    )
    expect(readNote('bin/router.php', hub('bin/router.php', 347, null), 20, POLICY, new Set(), DECLARED)?.text).toBe('knossos: bin/router.php has 347 dependent files.')
  })
  it('honours the threshold', () => expect(readNote('x.php', hub('x.php', 19, null), 20, POLICY, new Set(), DECLARED)).toBeNull())

  // Past the file list's cap: a boundary placed by its path prefix still binds every file under it.
  const CAPPED = {
    rules: RULES,
    boundaries: {
      core: { rules: ['core-alone'], path_prefixes: ['src/'], listed: false },
      edge: { rules: ['edge-only'], path_prefixes: [], listed: true },
    },
    files: { 'lib/Edge/A.php': ['edge'] },
    files_truncated: true,
  }
  it('binds a file through its boundary\'s path prefix, wherever the capped list stopped', () =>
    expect(readNote('src/Zz/Late.php', undefined, 20, CAPPED, new Set(), DECLARED)?.text).toBe(
      'knossos: src/Zz/Late.php is in core. Policy: core may not depend on php-worker, tests. Other rules may bind this file; run check_architecture.',
    ))
  it('says rules may bind a file the capped list never reached, never nothing', () => {
    expect(readNote('lib/Zz/Late.php', undefined, 20, CAPPED, new Set(), DECLARED)?.text).toBe('knossos: lib/Zz/Late.php: rules may bind this file; run check_architecture.')
    expect(readNote('lib/Zz/Late.php', hub('lib/Zz/Late.php', 30, null), 20, CAPPED, new Set(), DECLARED)?.text).toBe(
      'knossos: lib/Zz/Late.php has 30 dependent files. Rules may bind this file; run check_architecture.',
    )
    // An older knossos lists every bound file, so its cap leaves any unlisted file unsure.
    expect(readNote('x.php', undefined, 20, { ...POLICY, files_truncated: true }, new Set(), DECLARED)?.text).toBe('knossos: x.php: rules may bind this file; run check_architecture.')
  })
  it('stays quiet about a file the list would have named: no cap, or every boundary placed by prefix', () => {
    expect(readNote('lib/Zz/Late.php', undefined, 20, { ...CAPPED, files_truncated: false }, new Set(), DECLARED)).toBeNull()
    const prefixed = { ...CAPPED, boundaries: { core: CAPPED.boundaries.core } }
    expect(readNote('lib/Zz/Late.php', undefined, 20, prefixed, new Set(), DECLARED)).toBeNull()
    expect(readNote('src/Zz/Late.php', undefined, 20, prefixed, new Set(), DECLARED)?.text).toBe('knossos: src/Zz/Late.php is in core. Policy: core may not depend on php-worker, tests.')
  })
})

describe('testsNote', () => {
  const tests = [
    { path: 'tests/Http/RouterTest.php', distance: 1 },
    { path: 'tests/Support/Helper.php', distance: 1 },
    { path: 'hooks/lib/changes.spec.ts', distance: 2, js_runner: 'vitest' as const },
  ]
  it('names the runnable tests nearest first and the command that runs them', () =>
    expect(testsNote(tests, [], new Set())).toEqual({
      text: "knossos: 2 tests reach this turn's changes. Run: vendor/bin/phpunit tests/Http/RouterTest.php && npx vitest run hooks/lib/changes.spec.ts",
      tests: ['tests/Http/RouterTest.php', 'hooks/lib/changes.spec.ts'],
    }))
  it('leaves out tests the turn already ran and tests named before', () => {
    expect(testsNote(tests, ['vendor/bin/phpunit --filter RouterTest'], new Set())?.text).toBe(
      "knossos: 1 test reaches this turn's changes. Run: npx vitest run hooks/lib/changes.spec.ts",
    )
    expect(testsNote(tests, [], new Set(['hooks/lib/changes.spec.ts']))?.tests).toEqual(['tests/Http/RouterTest.php'])
    expect(testsNote(tests, ['vendor/bin/phpunit', 'npm run test:mod'], new Set())).toBeNull()
  })
  it('runs a Jest project with Jest, and lists a script whose runner is unknown without a command for it', () => {
    expect(testsNote([{ path: 'src/a.test.js', distance: 1, js_runner: 'jest' }], [], new Set())?.text).toBe("knossos: 1 test reaches this turn's changes. Run: npx jest src/a.test.js")
    expect(testsNote([{ path: 'src/a.test.js', distance: 1, js_runner: null }], [], new Set())?.text).toBe("knossos: 1 test reaches this turn's changes: src/a.test.js.")
    expect(testsNote([{ path: 'src/a.test.js', distance: 1 }, { path: 'tests/ATest.php', distance: 2 }], [], new Set())?.text).toBe(
      "knossos: 2 tests reach this turn's changes: src/a.test.js. Run: vendor/bin/phpunit tests/ATest.php",
    )
  })
  it('names no test twice: one the command names is not listed, one run by package is, five at most', () => {
    const many = Array.from({ length: 7 }, (_, i) => ({ path: `tests/T${i}Test.php`, distance: i }))
    expect(testsNote(many, [], new Set())?.text).toBe(
      "knossos: 7 tests reach this turn's changes. Run: vendor/bin/phpunit --filter '(T0Test|T1Test|T2Test|T3Test|T4Test|T5Test|T6Test)'",
    )
    const go = Array.from({ length: 7 }, (_, i) => ({ path: `pkg/p${i}/x_test.go`, distance: 1 }))
    expect(testsNote(go, [], new Set())?.text).toBe(
      "knossos: 7 tests reach this turn's changes: pkg/p0/x_test.go, pkg/p1/x_test.go, pkg/p2/x_test.go, pkg/p3/x_test.go, pkg/p4/x_test.go and 2 more. Run: go test ./pkg/p0 ./pkg/p1 ./pkg/p2 ./pkg/p3 ./pkg/p4 ./pkg/p5 ./pkg/p6",
    )
  })
  it('says nothing when no test reaches the changes', () => expect(testsNote([], [], new Set())).toBeNull())
})

describe('freshViolations', () => {
  const v = (source: string) => ({ policy_id: 'p', source, target: 'T', source_boundaries: [], target_boundaries: [] })
  it('drops violations already reported, and the count with them', () => {
    const brief = { policy: { status: 'evaluated', total: 3, truncated: false, violations: [v('A'), v('B')] } } as never
    const fresh = freshViolations(brief, new Set(['p: A → T']))
    expect(fresh.policy.violations.map(x => x.source)).toEqual(['B'])
    expect(fresh.policy.total).toBe(2)
    expect(violationKey(v('A'))).toBe('p: A → T')
  })
})
