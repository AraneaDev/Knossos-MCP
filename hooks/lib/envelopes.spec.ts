import { describe, expect, it } from 'vitest'
import { countLabel, detailLines, parseComponentDetail, parseDashboard, parseTurnBrief } from './envelopes'

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
  it("the wrapper's no-binary answer parses on every envelope", () => {
    const answer = '{"status":"no-binary"}'
    expect(parseTurnBrief(answer)?.status).toBe('no-binary')
    expect(parseDashboard(answer)?.status).toBe('no-binary')
    expect(parseComponentDetail(answer)?.status).toBe('no-binary')
  })
  it('a bare null is no data', () => expect(parseTurnBrief('null')).toBeNull())
})

describe('countLabel', () => {
  it('marks a truncated count', () => expect(countLabel(50, true)).toBe('50+'))
  it('leaves an exact count alone', () => expect(countLabel(50, false)).toBe('50'))
})

describe('component detail', () => {
  const related = (names: string[], truncated = false) => ({ count: names.length, truncated, names: names.slice(0, 5) })
  const envelope = (over: Record<string, unknown> = {}) => ({
    status: 'ok',
    path: '/r',
    name: 'Router',
    project_id: 'p1',
    snapshot_id: 's1',
    component: {
      name: 'App\\Router',
      kind: 'class',
      path: 'src/Router.php',
      line: 12,
      boundaries: ['Http'],
      used_by: related(['Kernel', 'Console']),
      uses: related(['Route']),
    },
    candidates: [],
    ...over,
  })
  const parsed = (over: Record<string, unknown> = {}) => parseComponentDetail(JSON.stringify(envelope(over)))

  it('silence is no detail', () => expect(parseComponentDetail('')).toBeNull())
  it('an unknown status is no detail', () => expect(parseComponentDetail('{"status":"missing"}')).toBeNull())
  it('an ok detail without its component is no detail', () => expect(parsed({ component: null })).toBeNull())
  it('an ok detail without candidates is no detail', () => expect(parsed({ candidates: null })).toBeNull())
  it('an error envelope parses', () => expect(parseComponentDetail('{"status":"error"}')?.status).toBe('error'))

  it('a found component lists where it is and who it touches', () => {
    const d = parsed()
    expect(d === null ? null : detailLines(d, 'Router')).toEqual([
      'class App\\Router',
      'at src/Router.php:12',
      'boundaries: Http',
      'used by 2: Kernel, Console',
      'uses 1: Route',
    ])
  })
  it('a cut list reads as a floor and a long one ends in an ellipsis', () => {
    const many = { count: 6, truncated: true, names: ['A', 'B', 'C', 'D', 'E'] }
    const component = { ...envelope().component, path: null, line: null, boundaries: [], used_by: many, uses: related([]) }
    const d = parsed({ component })
    expect(d === null ? null : detailLines(d, 'Router')).toEqual(['class App\\Router', 'used by 6+: A, B, C, D, E …', 'uses 0'])
  })
  it('an unmatched name says so', () => {
    const d = parsed({ status: 'not-found', component: null })
    expect(d === null ? null : detailLines(d, 'Nope')).toEqual(['No component matched "Nope".'])
  })
  it('an ambiguous name lists the candidates', () => {
    const d = parsed({ status: 'ambiguous', component: null, candidates: ['App\\One\\Dup', 'App\\Two\\Dup'] })
    expect(d === null ? null : detailLines(d, 'Dup')).toEqual(['"Dup" names more than one component: App\\One\\Dup, App\\Two\\Dup'])
  })
  it('an unscanned project says how to get data', () =>
    expect(detailLines({ status: 'unscanned' } as never, 'Router')).toEqual([
      'No Knossos data for this project. Scan it with knossos scan.',
    ]))
  it('an error says knossos could not read it', () =>
    expect(detailLines({ status: 'error' } as never, 'Router')).toEqual(['knossos could not read Router.']))
})
