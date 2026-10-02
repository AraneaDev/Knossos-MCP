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
function world(on: On, answers: { dashboard?: Answer[]; brief?: Answer[] } = {}) {
  const clock = mock.clock(on)
  const queues = { dashboard: answers.dashboard ?? [{ stdout: dashboard }], brief: answers.brief ?? [{ stdout: brief() }] }
  const calls: string[][] = []
  const toasts: string[] = []
  const logs: { text: string; to: string }[] = []
  const opened: string[] = []
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
    return { value: { isPlaced: true } }
  })
  on('process.run', async (_$, e) => {
    calls.push([...e.argv])
    const queue = e.argv[2] === 'dashboard' ? queues.dashboard : queues.brief
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
  return { clock, calls, briefRuns, toasts, logs, opened }
}

const START = { cwd: ROOT, surface: 'terminal', isInteractive: true } as const
const TURN = { answer: '', durationMs: 1, isAborted: false, turnId: 't', reason: 'answer' } as const

async function edit($: Engine, path: string) {
  return $.tool.call({ tool: 'Edit', file_path: path, old_string: 'a', new_string: 'b' })
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

  test('violations reach the model as a user-role note', async ($, on) => {
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
    const w = world(on, { brief: [{ stdout: brief(), hold: 0 }, { stdout: brief({ status: 'not-allowed' }) }] })
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
})
