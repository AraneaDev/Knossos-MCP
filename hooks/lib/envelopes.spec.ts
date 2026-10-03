import { describe, expect, it } from 'vitest'
import { countLabel, detailLines, fileDetailLines, parseAllowRoot, parseComponentDetail, parseDashboard, parseFileDetail, parseRescan, parseTurnBrief, rescanReason } from './envelopes'

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
    expect(parseFileDetail(answer)?.status).toBe('no-binary')
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

describe('parseRescan and rescanReason', () => {
  it('reads every status the scan subcommand answers', () => {
    for (const status of ['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error', 'no-binary']) {
      expect(parseRescan(JSON.stringify({ status }))?.status).toBe(status)
    }
  })
  it('treats silence and other statuses as no answer', () => {
    expect(parseRescan('')).toBeNull()
    expect(parseRescan('{"status":"fresh"}')).toBeNull()
  })
  it('says in a few words why a rescan did not land', () => {
    expect(rescanReason(null)).toBe('knossos said nothing')
    expect(rescanReason({ status: 'not-allowed' })).toBe('not an allowed root')
    expect(rescanReason({ status: 'missing' })).toBe('the project is gone')
    expect(rescanReason({ status: 'unscanned' })).toBe('never scanned')
    expect(rescanReason({ status: 'scan-failed', reason: 'disk full' })).toBe('disk full')
    expect(rescanReason({ status: 'scan-failed' })).toBe('the scan failed')
    expect(rescanReason({ status: 'error' })).toBe('knossos could not run it')
  })
})

describe('parseAllowRoot', () => {
  it('reads a grant, and a root that was already allowed', () => {
    expect(parseAllowRoot('{"path":"/w/p","roots_file":"/d/roots.json","roots_file_source":"named","added":true}')).toEqual({
      path: '/w/p',
      added: true,
      roots_file: '/d/roots.json',
    })
    expect(parseAllowRoot('{"path":"/w/p","roots_file":"/d/roots.json","added":false}')).toEqual({ path: '/w/p', added: false, roots_file: '/d/roots.json' })
  })
  it('passes no-binary through and reads silence, a preview or anything else as nothing', () => {
    expect(parseAllowRoot('{"status":"no-binary"}')).toEqual({ status: 'no-binary' })
    expect(parseAllowRoot('')).toBeNull()
    expect(parseAllowRoot('{"path":"/w/p","added":false,"preview":true}')).toBeNull()
    expect(parseAllowRoot('[1]')).toBeNull()
    expect(parseAllowRoot('{"path":3,"added":true}')).toBeNull()
  })
})

describe('file detail', () => {
  const file = {
    path: 'src/Router.php',
    language: 'php',
    lines: 120,
    boundary: 'Http',
    dependents: { count: 2, truncated: false, boundaries: ['Core'], items: [{ path: 'src/Kernel.php', edges: 3, boundary: 'Core' }] },
    components: { count: 1, truncated: false, items: [{ name: 'Router', canonical_name: 'App\\Router', kind: 'class', line: 7, boundary: 'Http', used_by: 4 }] },
  }
  const envelope = (over: Record<string, unknown> = {}) => JSON.stringify({ status: 'ok', path: '/r/src/Router.php', project_id: 'p1', snapshot_id: 's1', file, ...over })

  it('an ok answer parses with its lists', () => expect(parseFileDetail(envelope())?.file?.dependents.items[0]?.path).toBe('src/Kernel.php'))
  it('an ok answer without its file is no data', () => expect(parseFileDetail(envelope({ file: null }))).toBeNull())
  it('an ok answer whose lists are missing is no data', () => expect(parseFileDetail(envelope({ file: { ...file, components: {} } }))).toBeNull())
  it('a component status that is no file status is no data', () => expect(parseFileDetail('{"status":"ambiguous"}')).toBeNull())
  it('not-found and unscanned parse without a file', () => {
    expect(parseFileDetail(envelope({ status: 'not-found', file: null }))?.status).toBe('not-found')
    expect(parseFileDetail(envelope({ status: 'unscanned', file: null }))?.status).toBe('unscanned')
  })
  it('says why there is no detail, by status', () => {
    const not = parseFileDetail(envelope({ status: 'not-found', file: null }))!
    expect(fileDetailLines(not, 'src/Gone.php')).toEqual(['src/Gone.php is not in the graph: never scanned, ignored, or gone before the snapshot.'])
    expect(fileDetailLines({ ...not, status: 'unscanned' }, 'a.php')).toEqual(['No Knossos data for this project. Scan it with knossos scan.'])
    expect(fileDetailLines({ ...not, status: 'error' }, 'a.php')).toEqual(['knossos could not read a.php.'])
  })
})
