import { describe, expect, it } from 'vitest'
import { componentDetail, countLabel, parseDashboard, parseTurnBrief } from './envelopes'

describe('envelopes', () => {
  it('empty stdout is no data', () => expect(parseTurnBrief('')).toBeNull())
  it('garbage is no data', () => expect(parseTurnBrief('PHP Warning: x')).toBeNull())
  it('an object without a known status is no data', () => expect(parseDashboard('{"x":1}')).toBeNull())
  it('a valid brief parses', () => expect(parseTurnBrief('{"status":"unscanned","path":"/r","changed_files":[],"added_files":[],"deleted_files":[],"impact":{},"tests":[],"policy":{"status":"not_evaluated","total":0,"violations":[]},"project_root":null,"scanned_at":null,"roots_file":null}')?.status).toBe('unscanned'))
  it('a valid dashboard parses', () => expect(parseDashboard('{"status":"ok","hubs":[],"hotspots":[],"trend":[],"fan_in":[],"cycles":{"count":0},"freshness":{}}')?.status).toBe('ok'))
  it('an ok brief missing its fields is no data', () => expect(parseTurnBrief('{"status":"ok"}')).toBeNull())
  it('an ok dashboard missing its fields is no data', () => expect(parseDashboard('{"status":"ok"}')).toBeNull())
  it('a dashboard status is not a brief status', () => expect(parseDashboard('{"status":"missing"}')).toBeNull())
  it('a non-object is no data', () => expect(parseTurnBrief('[1]')).toBeNull())
  it('a bare null is no data', () => expect(parseTurnBrief('null')).toBeNull())
})

describe('countLabel', () => {
  it('marks a truncated count', () => expect(countLabel(50, true)).toBe('50+'))
  it('leaves an exact count alone', () => expect(countLabel(50, false)).toBe('50'))
})

describe('componentDetail', () => {
  const edge = (name: string) => ({ kind: 'calls', component: { canonical_name: `App\\${name}`, display_name: name } })
  const found = (over: Record<string, unknown> = {}) =>
    JSON.stringify({
      summary: 'Inspected App\\Router.',
      data: {
        component: {
          kind: 'class',
          canonical_name: 'App\\Router',
          boundaries: [{ id: 'b1', name: 'Http', source: 'inferred' }],
          incoming: [edge('Kernel'), edge('Kernel'), edge('Console')],
          outgoing: [edge('Route')],
          ...over,
        },
        limits: { truncation_reasons: [] },
      },
      evidence: [{ path: 'src/Router.php', start_line: 12 }],
    })

  it('silence is no detail', () => expect(componentDetail('')).toBeNull())
  it('an envelope without a summary is no detail', () => expect(componentDetail('{"data":{}}')).toBeNull())
  it('an unmatched component is its summary alone', () =>
    expect(componentDetail('{"summary":"No component matched \\"X\\".","data":{"component":null}}')).toEqual([
      'No component matched "X".',
    ]))
  it('a found component lists where it is and who it touches', () =>
    expect(componentDetail(found())).toEqual([
      'Inspected App\\Router.',
      'class · src/Router.php:12',
      'boundaries: Http',
      'used by 2: Kernel, Console',
      'uses 1: Route',
    ]))
  it('a cut relationship list says so', () =>
    expect(
      componentDetail(
        JSON.stringify({
          summary: 's',
          data: { component: { kind: 'class', incoming: [edge('A')], outgoing: [] }, limits: { truncation_reasons: ['incoming_relationship_limit'] } },
          evidence: [],
        }),
      ),
    ).toEqual(['s', 'class', 'used by 1+: A', 'uses 0']))
  it('a long list ends in an ellipsis', () =>
    expect(componentDetail(found({ incoming: ['A', 'B', 'C', 'D', 'E', 'F'].map(edge) }))).toContain('used by 6: A, B, C, D, E …'))
})
