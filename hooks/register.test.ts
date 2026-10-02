import { describe, expect, mock, test } from 'claude-code/testing'
import type { Engine } from 'claude-code/testing'
import type { On, RenderPropsOf } from 'claude-code'

const ROOT = '/repo'

type Answer = { stdout: string; hold?: number }

const dashboard = JSON.stringify({
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [{ path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'] }],
  fan_in_truncated: false,
})

const brief = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    scanned_at: 0,
    scan_ms: 5,
    reason: null,
    roots_file: null,
    changed_files: ['src/Router.php'],
    added_files: [],
    deleted_files: [],
    impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'] } },
    tests: [],
    policy: { status: 'evaluated', total: 0, violations: [] },
    ...over,
  })

const BAND_PROPS: RenderPropsOf['AbovePrompt'] = {
  hasSurvey: false,
  isWorking: false,
  maxRows: 10,
  bodyColumns: 100,
  scroll: { offset: 0, bodyRows: 10 },
  view: {},
}

/**
 * Stands in for the engine beneath the mod: the session root, the wrapper's
 * answers (a queue per subcommand, the last answer repeating), and every
 * engine call the mod makes, recorded.
 */
function world(on: On, answers: { dashboard?: Answer[]; brief?: Answer[]; detail?: Answer[] } = {}) {
  const clock = mock.clock(on)
  const queues = {
    dashboard: answers.dashboard ?? [{ stdout: dashboard }],
    brief: answers.brief ?? [{ stdout: brief() }],
    detail: answers.detail ?? [{ stdout: '' }],
  }
  const calls: string[][] = []
  const toasts: string[] = []
  const logs: { text: string; to: string }[] = []
  const opened: string[] = []
  const closed: string[] = []
  /** The panes the engine holds open, as `ui.panes` lists them. */
  const panes = new Set<string>()
  on('session.start', (_$, e) => ({ cwd: e.cwd }))
  on('session.root', () => ({ value: ROOT }))
  on('turn.complete', (_$, e) => ({ text: e.answer }))
  on('command.register', (_$, e) => ({ value: { command: e.name } }))
  on('ui.toast', (_$, e) => {
    toasts.push(e.text)
    return { value: undefined }
  })
  on('ui.log', (_$, e) => {
    logs.push({ text: e.text, to: e.to })
    return { value: undefined }
  })
  on('ui.open', (_$, e) => {
    opened.push(e.id)
    panes.add(e.id)
    return { value: { isPlaced: true } }
  })
  on('ui.close', (_$, e) => {
    closed.push(e.id)
    panes.delete(e.id)
    return { value: undefined }
  })
  on('ui.panes', () => ({
    value: [...panes].map(id => ({ id, title: 'Knossos', isShown: true, isFocused: false, isPlaced: true })),
  }))
  on('process.run', async (_$, e) => {
    calls.push([...e.argv])
    const sub = e.argv[2]
    const queue = sub === 'dashboard' ? queues.dashboard : sub === 'component-detail' ? queues.detail : queues.brief
    const answer = (queue.length > 1 ? queue.shift() : queue[0]) ?? { stdout: '' }
    if (answer.hold !== undefined) await clock.sleep(answer.hold)
    return {
      value: { exitCode: 0, stdout: answer.stdout, stderr: '', isStdoutTruncated: false, isStderrTruncated: false },
    }
  })
  // The engine's own band: an empty row, which no hook of the mod's draws.
  on('ui.render', () => ({ type: 'Box', props: { key: 'engine' }, children: [] }))
  on('tool.call', (_$, e) => ({ result: {} as never, text: `ran ${e.tool}` }))
  const briefRuns = () => calls.filter(c => c[2] === 'turn-brief')
  const detailRuns = () => calls.filter(c => c[2] === 'component-detail')
  return { clock, calls, briefRuns, detailRuns, toasts, logs, opened, closed }
}

const START = { cwd: ROOT, surface: 'terminal', isInteractive: true } as const
const TURN = { answer: '', durationMs: 1, isAborted: false, turnId: 't', reason: 'answer' } as const

async function edit($: Engine, path: string) {
  return $.tool.call({ tool: 'Edit', file_path: path, old_string: 'a', new_string: 'b' })
}

/** A dashboard with something on the pane: one hub, one hotspot, a cycle and two snapshots of trend. */
const paneDashboard = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    ...(JSON.parse(dashboard) as object),
    hubs: [{ name: 'Router', kind: 'class', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 }],
    hotspots: [{ name: 'Kernel', kind: 'class', score: 7.25 }],
    dead_code_candidates: 4,
    cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [{ size: 2, members: ['A', 'B'] }] },
    trend: [
      { snapshot_id: 's0', cycles: 1, max_degree: 10 },
      { snapshot_id: 's1', cycles: 3, max_degree: 12 },
    ],
    ...over,
  })

/** What `component-detail --json` prints for a component that exists. */
const detailOf = (name: string) =>
  JSON.stringify({
    status: 'ok',
    path: ROOT,
    name,
    project_id: 'p1',
    snapshot_id: 's1',
    component: {
      name: `App\\${name}`,
      kind: 'class',
      path: `src/${name}.php`,
      line: 3,
      boundaries: [],
      used_by: { count: 1, truncated: false, names: ['Kernel'] },
      uses: { count: 0, truncated: false, names: [] },
    },
    candidates: [],
  })

const PANE_PROPS = {
  title: 'Knossos',
  isFocused: false,
  bodyColumns: 60,
  placement: 'dock',
  scroll: { offset: 0, bodyRows: 30 },
  view: {},
} as RenderPropsOf['Pane']

async function mountPane($: Engine, surface: 'terminal' | 'desktop' = 'terminal') {
  return $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: PANE_PROPS })
}

/** The person typing `/knossos <args>`. */
async function slash($: Engine, args: string) {
  return $.command.run({
    command: 'knossos',
    args,
    origin: { kind: 'composer' },
    presentation: { isFullscreen: true, columns: 120 },
  })
}

async function bandText($: Engine, surface: 'terminal' | 'desktop' = 'terminal') {
  const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'AbovePrompt', props: BAND_PROPS })
  const found = await ui.find({ key: 'band' })
  await ui.unmount()
  return found?.text
}

describe('knossos mod', () => {
  test('an edit to a high fan-in file adds a note for the model', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const ran = await edit($, `${ROOT}/src/Router.php`)
    expect(ran.context?.join('\n')).toContain('src/Router.php has 41 dependent files')
    expect(w.toasts).toHaveLength(1)
  })

  test('an edit to a quiet file adds nothing', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const ran = await edit($, `${ROOT}/src/Quiet.php`)
    expect(ran.context ?? []).toHaveLength(0)
    expect(w.toasts).toHaveLength(0)
  })

  test('an edit outside the project adds nothing and leaves the turn clean', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const ran = await edit($, '/etc/x')
    expect(ran.context ?? []).toHaveLength(0)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
  })

  test('the fan-in threshold comes from the options', { options: { fanInThreshold: 50 } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const ran = await edit($, `${ROOT}/src/Router.php`)
    expect(ran.context ?? []).toHaveLength(0)
    expect(w.calls[0]).toContain('--fan-in-threshold=50')
  })

  test('a turn without edits starts no scan', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
  })

  test('a Bash-only turn starts no scan', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await $.tool.call({ tool: 'Bash', command: 'touch src/x.php' })
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
  })

  test("a subagent's turn leaves its edits to the main turn's scan", async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Sub.php`)
    await $.turn.complete({ ...TURN, agentId: 'a1' })
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect(w.briefRuns()[0]).toContain('--files=src/Sub.php')
  })

  test('the scan runs after the turn, not inside it', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    expect(w.briefRuns()).toHaveLength(0)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect(w.briefRuns()[0]?.slice(0, 4)).toEqual(['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'turn-brief', ROOT])
  })

  test('a dirty turn scans once after the turn, a burst scans twice at most', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief(), hold: 1000 }, { stdout: brief() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/A.php`)
    await edit($, `${ROOT}/src/B.php`)
    await edit($, `${ROOT}/src/C.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/D.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/E.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    await w.clock.advance(1000)
    const runs = w.briefRuns()
    expect(runs).toHaveLength(2)
    expect(runs[0]?.filter(a => a.startsWith('--files='))).toEqual([
      '--files=src/A.php',
      '--files=src/B.php',
      '--files=src/C.php',
    ])
    expect(runs[1]?.filter(a => a.startsWith('--files='))).toEqual(['--files=src/D.php', '--files=src/E.php'])
  })

  test('the --files option is passed once per path, spaces intact', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/a, b.php`)
    await edit($, `${ROOT}/a, b.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[0]?.filter(a => a.startsWith('--files='))).toEqual(['--files=a, b.php'])
  })

  test('an edit before the first dashboard still triggers a turn brief', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: dashboard, hold: 5000 }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/New.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect(w.briefRuns()[0]).toContain(`--files=${ROOT}/src/New.php`)
  })

  test('an undelivered violation note leaves a debug trace', async ($, on) => {
    const violation = {
      policy_id: 'no-http-in-domain',
      source: 'src/Domain/A.php',
      target: 'src/Http/B.php',
      source_boundaries: [],
      target_boundaries: [],
    }
    const w = world(on, { brief: [{ stdout: brief({ policy: { status: 'evaluated', total: 1, violations: [violation] } }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Domain/A.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    // Delivery of the user row itself is checked in the live-session task.
    // The 2.1.287 kit runs no hook for a plugin's own $.session.append (not
    // the test's, not another plugin's at any tier): the call always rejects
    // with "no implementation". The mod's fallback for an undelivered note is
    // the observable trace: one debug line carrying the note it tried to append.
    const lines = w.logs.filter(l => l.text.includes('this turn introduced'))
    expect(lines).toHaveLength(1)
    expect(lines[0]?.to).toBe('debug')
    expect(lines[0]?.text).toContain('knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:')
    expect(lines[0]?.text).toContain('- no-http-in-domain: src/Domain/A.php → src/Http/B.php')
  })

  test('violations stay off the model when policies are not enforced', { options: { enforcePolicies: false } }, async ($, on) => {
    const violation = { policy_id: 'p', source: 'a', target: 'b', source_boundaries: [], target_boundaries: [] }
    const w = world(on, { brief: [{ stdout: brief({ policy: { status: 'evaluated', total: 1, violations: [violation] } }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/A.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.logs.filter(l => l.text.includes('this turn introduced'))).toHaveLength(0)
    expect(w.briefRuns()[0]).toContain('--no-policies')
  })

  test('an ok brief is shown and refreshes the dashboard', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const before = w.calls.filter(c => c[2] === 'dashboard').length
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBe(before + 1)
    expect(await bandText($)).toContain('1 file → 41 dependents')
  })

  test('a failed scan keeps the old figures and says so', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: '' }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const text = await bandText($)
    expect(text).toContain('scan failed')
    expect(text).toContain('41 dependents')
  })

  test('an error envelope keeps the old figures and says so', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: brief({ status: 'error', reason: 'boom' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    const text = await bandText($)
    expect(text).toContain('scan failed')
    expect(text).toContain('41 dependents')
  })

  test('a first scan-failed brief shows its reason', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief({ status: 'scan-failed', reason: 'parse error' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await bandText($)).toContain('scan failed: parse error')
  })

  test('a later scan-failed brief keeps the last good figures', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: brief({ status: 'scan-failed', reason: 'parse error' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    const text = await bandText($)
    expect(text).toContain('scan failed, figures from')
    expect(text).toContain('41 dependents')
  })

  test('a not-allowed brief shows the allow-root hint', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: brief({ status: 'not-allowed' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    expect(await bandText($)).toContain('knossos allow-root')
  })

  test('an unscanned brief replaces the figures and draws nothing', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: brief({ status: 'unscanned' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    expect(await bandText($)).toBeUndefined()
  })

  test('a missing brief replaces the figures and draws nothing', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief() }, { stdout: brief({ status: 'missing' }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    // The kit's $ has no state noun: the band is the observable. Stored, the
    // missing brief replaces the ok figures, so nothing is drawn; were it
    // dropped with the job failed, the band would read "scan failed" with them.
    expect(await bandText($)).toBeUndefined()
  })

  test("the band's age keeps up with the clock", async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'AbovePrompt', props: BAND_PROPS })
    expect((await ui.find({ key: 'band' }))?.text).toContain('as of 0s ago')
    await w.clock.advance(5_000)
    expect((await ui.find({ key: 'band' }))?.text).toContain('as of 5s ago')
    await w.clock.advance(61_000)
    expect((await ui.find({ key: 'band' }))?.text).toContain('as of 1m ago')
    await ui.unmount()
  })

  test('a threshold that is not a positive number falls back to 20', { options: { fanInThreshold: 0 } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls[0]).toContain('--fan-in-threshold=20')
    const ran = await edit($, `${ROOT}/src/Router.php`)
    expect(ran.context?.join('\n')).toContain('src/Router.php has 41 dependent files')
  })

  test('the band draws on terminal and desktop', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'AbovePrompt', props: BAND_PROPS })
      expect(await ui.find({ key: 'band' })).toBeDefined()
      await ui.press({ key: 'details' })
      await ui.unmount()
    }
    expect(w.opened).toEqual(['knossos', 'knossos'])
  })

  test('the band can be hidden', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'AbovePrompt', props: BAND_PROPS })
    await ui.press({ key: 'hide' })
    expect(await ui.find({ key: 'band' })).toBeUndefined()
    await ui.unmount()
  })

  test('the band yields to a survey', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const props = { ...BAND_PROPS, hasSurvey: true }
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'AbovePrompt', props })
    expect(await ui.find({ key: 'band' })).toBeUndefined()
    await ui.unmount()
  })

  test('a missing binary disables the mod quietly', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: '' }] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls.filter(c => c[2] === 'dashboard')).toHaveLength(2)
    const ran = await edit($, `${ROOT}/src/Router.php`)
    expect(ran.context ?? []).toHaveLength(0)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
    expect(w.toasts).toHaveLength(0)
    expect(w.logs).toHaveLength(1)
    expect(w.logs[0]?.text).toContain('no data from the knossos binary')
  })

  test('the pane opens on start when asked', { options: { openPaneOnStart: true } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    expect(w.opened).toEqual(['knossos'])
  })

  test('a disabled mod does nothing', { options: { enabled: false } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.calls).toHaveLength(0)
  })

  test('/knossos opens the pane and a second /knossos closes it', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    const before = w.calls.filter(c => c[2] === 'dashboard').length
    expect((await slash($, '')).text).toBe('Knossos pane opened.')
    // Opening reads the dashboard afresh; closing reads nothing.
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBe(before + 1)
    expect((await slash($, '  ')).text).toBe('Knossos pane closed.')
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBe(before + 1)
    expect(w.opened).toEqual(['knossos'])
    expect(w.closed).toEqual(['knossos'])
  })

  test('/knossos with anything else says how to use it', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    for (const args of ['foo', 'inspect', 'inspect   ']) {
      expect((await slash($, args)).text).toContain('Usage: /knossos')
    }
    expect(w.opened).toEqual([])
  })

  test('the pane lists hubs, hotspots, cycles and a trend on both surfaces', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'hubs' }))?.text).toContain('Router (class) in 41')
      expect((await ui.find({ key: 'hotspots' }))?.text).toContain('Kernel (class) 7.3')
      expect((await ui.find({ key: 'cycles' }))?.text).toContain('Cycles: 1')
      expect((await ui.find({ key: 'cycles' }))?.text).toContain('2: A → B')
      expect((await ui.find({ key: 'dead-code' }))?.text).toContain('Dead-code candidates: 4')
      expect(await ui.find({ key: 'trend' })).toBeDefined()
      await ui.unmount()
    }
  })

  test('truncated counts read as lower bounds', async ($, on) => {
    const cycles = { count: 50, truncated: true, truncation_reasons: ['cycle_limit'], largest: [] }
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ cycles, dead_code_candidates: 100, dead_code_truncated: true }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'cycles' }))?.text).toContain('Cycles: 50+')
    expect((await ui.find({ key: 'dead-code' }))?.text).toContain('Dead-code candidates: 100+')
    await ui.unmount()
  })

  test('the trend rows are labelled per snapshot and end on the newest value', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    const trend = (await ui.find({ key: 'trend' }))?.text
    expect(trend).toContain('cycles per snapshot')
    expect(trend).toContain('▁█ 3')
    expect(trend).toContain('max degree per snapshot')
    expect(trend).toContain('▁█ 12')
    await ui.unmount()
  })

  test('the pane without data says how to get some', async ($, on) => {
    const unscanned = JSON.stringify({ ...(JSON.parse(dashboard) as object), status: 'unscanned' })
    const w = world(on, { dashboard: [{ stdout: unscanned }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'empty' }))?.text).toContain('knossos scan')
    expect(await ui.find({ key: 'hubs' })).toBeUndefined()
    await ui.unmount()
  })

  test('pressing a hub shows its detail and back returns', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    const detail = (await ui.find({ key: 'detail' }))?.text
    expect(detail).toContain('class App\\Router')
    expect(detail).toContain('used by 1: Kernel')
    expect(w.detailRuns()).toEqual([
      ['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'component-detail', ROOT, 'Router'],
    ])
    await ui.press({ key: 'back' })
    expect(await ui.find({ key: 'detail' })).toBeUndefined()
    expect(await ui.find({ key: 'hubs' })).toBeDefined()
    await ui.unmount()
  })

  test('pressing a hotspot shows its detail', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hot-0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Kernel')
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['Kernel'])
    await ui.unmount()
  })

  test('a press shows the loading line before knossos answers', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router'), hold: 1000 }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    // Drawn from state alone: no process has run inside the render.
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Inspecting Router…')
    await w.clock.settle()
    expect(w.detailRuns()).toHaveLength(1)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Inspecting Router…')
    await w.clock.advance(1000)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Router')
    await ui.unmount()
  })

  test('two presses during the wait run one lookup', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router'), hold: 1000 }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    await w.clock.advance(1000)
    expect(w.detailRuns()).toHaveLength(1)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Router')
    await ui.unmount()
  })

  test('a late answer for a component left behind never shows', async ($, on) => {
    const w = world(on, {
      dashboard: [{ stdout: paneDashboard() }],
      detail: [{ stdout: detailOf('Router'), hold: 1000 }, { stdout: detailOf('Kernel'), hold: 5000 }],
    })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'hot-0' })
    await w.clock.settle()
    // Router's answer arrives while Kernel is on screen.
    await w.clock.advance(1000)
    const text = (await ui.find({ key: 'detail' }))?.text
    expect(text).toContain('Inspecting Kernel…')
    expect(text).not.toContain('App\\Router')
    await w.clock.advance(4000)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Kernel')
    await ui.unmount()
  })

  test('a component is looked up once per snapshot', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Router')
    expect(w.detailRuns()).toHaveLength(1)
    await ui.unmount()
  })

  test('a new snapshot looks the component up again', async ($, on) => {
    const w = world(on, {
      dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ snapshot_id: 's2' }) }],
      detail: [{ stdout: detailOf('Router') }],
    })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Router')
    await w.clock.settle()
    expect(w.detailRuns()).toHaveLength(1)
    // A scan after an edit refreshes the dashboard onto snapshot s2.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.detailRuns()).toHaveLength(2)
    const ui = await mountPane($)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('class App\\Router')
    await ui.unmount()
  })

  test('an unknown component shows what knossos said', async ($, on) => {
    const none = JSON.stringify({ ...(JSON.parse(detailOf('Nope')) as object), status: 'not-found', component: null })
    const w = world(on, { detail: [{ stdout: none }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Nope')
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('No component matched "Nope".')
    await ui.unmount()
  })

  test('a silent lookup says there is nothing to show, and a later press asks again', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('No details for Router')
    await ui.press({ key: 'back' })
    await ui.press({ key: 'hub-0' })
    await w.clock.settle()
    expect(w.detailRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test('/knossos inspect opens the pane on that component', async ($, on) => {
    const w = world(on, { detail: [{ stdout: detailOf('My Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    expect((await slash($, 'inspect  My Router ')).text).toBe('Knossos pane opened.')
    expect(w.opened).toEqual(['knossos'])
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('My Router')
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['My Router'])
    await ui.unmount()
  })

  test('a bare /knossos opens the pane on the overview', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Router')
    await w.clock.settle()
    await slash($, '')
    await slash($, '')
    const ui = await mountPane($)
    expect(await ui.find({ key: 'detail' })).toBeUndefined()
    expect(await ui.find({ key: 'hubs' })).toBeDefined()
    await ui.unmount()
  })
})
