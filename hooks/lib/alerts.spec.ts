import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { alertKeys, ALERTS_MAX, freshAlerts } from './alerts'

const ROOT = '/work/app'
const violation = (i: number) => ({ policy_id: 'core-stays-out', source: `App\\Core\\Greeter${i}::greet`, source_kind: 'method', source_boundary: 'core', target: `App\\Edge\\Caller${i}`, target_kind: 'class', target_boundary: 'edge', path: 'src/Core/Greeter.php', line: 3 })
const cycle = (...members: string[]) => ({ size: members.length, members })
const dash = (over: Partial<Dashboard> = {}): Dashboard =>
  ({
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [],
    hubs_truncated: false,
    hubs_truncation_reasons: [],
    hotspots: [],
    dead_code_candidates: 0,
    dead_code_truncated: false,
    cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B')] },
    trend: [],
    fan_in: [],
    fan_in_truncated: false,
    policy: { status: 'evaluated', total: 1, truncated: false, truncation_reasons: [], items: [violation(0)] },
    ...over,
  }) as Dashboard

describe('alerts for what a scan brought', () => {
  it('says a new cycle and a new policy violation, by their short names', () => {
    const after = dash({ snapshot_id: 's2', cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B'), cycle('App\\X\\Router', 'App\\Y\\Handler', 'App\\Z\\Store')] }, policy: { status: 'evaluated', total: 2, truncated: false, truncation_reasons: [], items: [violation(0), violation(1)] } })
    expect(freshAlerts(dash(), after).map(a => a.text)).toEqual(['knossos: a new dependency cycle of 3: Router → Handler → Store → Router', 'knossos: a new policy violation (core-stays-out): Greeter1::greet → Caller1'])
  })

  it('says nothing for the first graph of a session, another project, the same snapshot, or what was said before', () => {
    const after = dash({ snapshot_id: 's2', cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B'), cycle('App\\C', 'App\\D')] } })
    expect(freshAlerts(null, after)).toEqual([])
    expect(freshAlerts(dash({ project_id: 'p2' }), after)).toEqual([])
    expect(freshAlerts(dash({ snapshot_id: 's2' }), after)).toEqual([])
    const said = new Set(freshAlerts(dash(), after).flatMap(alertKeys))
    expect(freshAlerts(dash(), after, said)).toEqual([])
    // The same cycle listed in another order is the same cycle.
    expect(freshAlerts(dash(), dash({ snapshot_id: 's3', cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [cycle('App\\B', 'App\\A')] } }))).toEqual([])
  })

  it('says a count that grew past what the lists show, and sums up a scan that brought many', () => {
    expect(freshAlerts(dash(), dash({ snapshot_id: 's2', cycles: { count: 4, truncated: true, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B')] } })).map(a => a.text)).toEqual(['knossos: the graph now has 4 dependency cycles, 3 more than before'])
    expect(freshAlerts(dash(), dash({ snapshot_id: 's2', policy: { status: 'evaluated', total: 3, truncated: true, truncation_reasons: [], items: [violation(0)] } })).map(a => a.text)).toEqual(['knossos: 2 new policy violations, 3 in all'])
    const many = dash({ snapshot_id: 's2', policy: { status: 'evaluated', total: 9, truncated: false, truncation_reasons: [], items: Array.from({ length: 9 }, (_, i) => violation(i)) } })
    const alerts = freshAlerts(dash(), many)
    expect(alerts).toHaveLength(ALERTS_MAX)
    expect(alerts.at(-1)?.text).toBe('knossos: and 6 more new cycles or violations; the Issues and Cycles tabs list them')
    // The summed one stands for each it counts: none of them is said again.
    const said = new Set(alerts.flatMap(alertKeys))
    expect(said.size).toBe(8)
    expect(freshAlerts(dash(), many, said)).toEqual([])
  })

  it('reads policies only where both graphs were checked', () => {
    expect(freshAlerts(dash({ policy: { status: 'skipped', total: 0, truncated: false, truncation_reasons: [], items: [] } }), dash({ snapshot_id: 's2', policy: { status: 'evaluated', total: 2, truncated: false, truncation_reasons: [], items: [violation(0), violation(1)] } }))).toEqual([])
  })

  it('says no count grew against an earlier search that stopped before it counted them', () => {
    const before = dash({ cycles: { count: 0, truncated: true, truncation_reasons: ['time_limit'], largest: [] } })
    const after = dash({ snapshot_id: 's2', cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B'), cycle('App\\C', 'App\\D')] } })
    expect(freshAlerts(before, after)).toEqual([])
  })

  it('names nothing new against an earlier list that was cut: only a count that grew is said', () => {
    // Before: 12 cycles, 2 listed. After: a cycle listed that was not, 13 in all.
    const before = dash({ cycles: { count: 12, truncated: true, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B'), cycle('App\\C', 'App\\D')] } })
    const after = dash({ snapshot_id: 's2', cycles: { count: 13, truncated: true, truncation_reasons: [], largest: [cycle('App\\A', 'App\\B'), cycle('App\\E', 'App\\F')] } })
    expect(freshAlerts(before, after).map(a => a.text)).toEqual(['knossos: the graph now has 13 dependency cycles, 1 more than before'])
    // The same count: nothing to say, though a listed one changed.
    expect(freshAlerts(before, dash({ ...after, cycles: { ...after.cycles, count: 12 } }))).toEqual([])
    // Violations the same way: 5 counted, 1 listed before.
    const was = dash({ policy: { status: 'evaluated', total: 5, truncated: false, truncation_reasons: [], items: [violation(0)] } })
    const now = dash({ snapshot_id: 's2', policy: { status: 'evaluated', total: 5, truncated: false, truncation_reasons: [], items: [violation(1)] } })
    expect(freshAlerts(was, now)).toEqual([])
    expect(freshAlerts(was, { ...now, policy: { ...now.policy!, total: 6 } }).map(a => a.text)).toEqual(['knossos: 1 new policy violation, 6 in all'])
  })
})
