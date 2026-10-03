import { describe, expect, it } from 'vitest'
import { editNote, fanInIndex, violationNote } from './notes'

describe('notes', () => {
  it('edit note names dependents, boundaries and the next step', () =>
    expect(editNote({ path: 'src/Router.php', dependent_files: 41, boundaries: ['Http', 'Core', 'Cli'] })).toBe(
      'knossos: src/Router.php has 41 dependent files across 3 boundaries (Http, Core, Cli); run test_impact before finishing.',
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
