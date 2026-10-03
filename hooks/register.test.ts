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
  hubs_truncated: false,
  hubs_truncation_reasons: [],
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
    refused_root: null,
    changed_files: ['src/Router.php'],
    added_files: [],
    deleted_files: [],
    impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http' } },
    tests: [],
    policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
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
function world(
  on: On,
  answers: {
    dashboard?: Answer[]
    brief?: Answer[]
    detail?: Answer[]
    file?: Answer[]
    scan?: Answer[]
    allow?: Answer[]
    editor?: 'opens' | 'missing'
    refuseRegister?: () => boolean | Promise<boolean>
  } = {},
  disk: { root?: string; links?: Record<string, string>; gone?: string[]; garbled?: string[] } = {},
) {
  const clock = mock.clock(on)
  const links = disk.links ?? {}
  const gone = new Set(disk.gone ?? [])
  const queues = {
    dashboard: answers.dashboard ?? [{ stdout: dashboard }],
    brief: answers.brief ?? [{ stdout: brief() }],
    detail: answers.detail ?? [{ stdout: '' }],
    file: answers.file ?? [{ stdout: '' }],
    scan: answers.scan ?? [{ stdout: '{"status":"ok"}' }],
    allow: answers.allow ?? [{ stdout: '{"path":"/repo","roots_file":"/data/roots.json","added":true}' }],
  }
  /** Every prompt submitted, and every copy with the surface it was for. */
  const prompts: string[] = []
  const copies: { text: string; surface: string | undefined }[] = []
  const calls: string[][] = []
  const toasts: string[] = []
  const logs: { text: string; to: string }[] = []
  const invalidations: string[] = []
  const opened: string[] = []
  const closed: string[] = []
  /** The panes the engine holds open, as `ui.panes` lists them. */
  const panes = new Set<string>()
  on('session.start', (_$, e) => ({ cwd: e.cwd }))
  on('session.root', () => ({ value: disk.root ?? ROOT }))
  // Every path exists except those gone; a path under a link lands under its target; a garbled one answers nonsense.
  on('fs.stat', (_$, e) => {
    if (gone.has(e.path)) throw Object.assign(new Error(`ENOENT: ${e.path}`), { code: 'ENOENT' })
    if (disk.garbled?.includes(e.path) === true) return { value: { kind: 'file', size: 1, mtimeMs: 0, isLink: false, realPath: 42 as never } }
    const link = Object.keys(links).find(l => e.path === l || e.path.startsWith(`${l}/`))
    const realPath = link === undefined ? e.path : `${links[link]}${e.path.slice(link.length)}`
    return { value: { kind: 'file', size: 1, mtimeMs: 0, isLink: link !== undefined, ...(e.resolve ? { realPath } : {}) } }
  })
  on('turn.complete', (_$, e) => ({ text: e.answer }))
  /** Commands registered; `refuseRegister` refuses one as the engine does while no session is bound. */
  const registered: string[] = []
  on('command.register', async (_$, e) => {
    if ((await answers.refuseRegister?.()) === true) throw new Error('$.command.register is not available in this mode: no session is bound in this process')
    registered.push(e.name)
    return { value: { command: e.name } }
  })
  on('ui.toast', (_$, e) => {
    toasts.push(e.text)
    return { value: undefined }
  })
  on('ui.log', (_$, e) => {
    logs.push({ text: e.text, to: e.to })
    return { value: undefined }
  })
  // Counted, then passed on: the redraw itself still happens.
  on('ui.invalidate', (_$, e, next) => {
    invalidations.push(e.event)
    return next(e)
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
  on('prompt.submit', (_$, e) => {
    prompts.push(e.text)
    return { text: e.text }
  })
  on('ui.copy', (_$, e) => {
    copies.push({ text: e.text, surface: e.surface })
    return { value: { isCopied: true } }
  })
  /** Where the focus ring landed, by element (or `key` for a `$.ui.focus` call). */
  const focuses: string[] = []
  // The focus ring lands where it was asked to.
  on('ui.focus', (_$, e) => {
    const element = e.element ?? (e as { key?: unknown }).key
    if (typeof element === 'string') focuses.push(element)
    return {}
  })
  on('ui.panes', () => ({
    value: [...panes].map(id => ({ id, title: 'Knossos', isShown: true, isFocused: false, isPlaced: true })),
  }))
  on('process.run', async (_$, e) => {
    calls.push([...e.argv])
    // The editor's command: there and opening the file, or not installed.
    if (e.argv[0] === 'code') {
      if (answers.editor === 'missing') throw Object.assign(new Error('spawn code ENOENT'), { code: 'ENOENT' })
      return { value: { exitCode: 0, stdout: '', stderr: '', isStdoutTruncated: false, isStderrTruncated: false } }
    }
    const sub = e.argv[2]
    const queue =
      sub === 'dashboard'
        ? queues.dashboard
        : sub === 'component-detail'
          ? queues.detail
          : sub === 'file-detail'
            ? queues.file
            : sub === 'scan'
              ? queues.scan
              : sub === 'allow-root'
                ? queues.allow
                : queues.brief
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
  const fileRuns = () => calls.filter(c => c[2] === 'file-detail')
  const scanRuns = () => calls.filter(c => c[2] === 'scan')
  const dashboardRuns = () => calls.filter(c => c[2] === 'dashboard')
  const allowRuns = () => calls.filter(c => c[2] === 'allow-root')
  const editorRuns = () => calls.filter(c => c[0] === 'code')
  return { registered, clock, calls, briefRuns, detailRuns, fileRuns, scanRuns, dashboardRuns, allowRuns, editorRuns, toasts, logs, opened, closed, invalidations, prompts, copies, focuses }
}

const START = { cwd: ROOT, surface: 'terminal', isInteractive: true } as const
const TURN = { answer: '', durationMs: 1, isAborted: false, turnId: 't', reason: 'answer' } as const

async function edit($: Engine, path: string) {
  return $.tool.call({ tool: 'Edit', file_path: path, old_string: 'a', new_string: 'b' })
}

async function readFile($: Engine, path: string) {
  return $.tool.call({ tool: 'Read', file_path: path })
}

async function bash($: Engine, command: string) {
  return $.tool.call({ tool: 'Bash', command })
}

/** A dashboard whose project declares policies: `core` binds two files, and Router is a hub in it. */
const policedDashboard = () =>
  JSON.stringify({
    ...(JSON.parse(dashboard) as object),
    fan_in: [
      { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core' },
      { path: 'bin/router.php', dependent_files: 30, boundaries: ['core'], boundary: null },
    ],
    boundaries: { items: [{ name: 'core', source: 'explicit', members: 40 }], truncated: false },
    policy: {
      status: 'evaluated',
      total: 0,
      truncated: false,
      truncation_reasons: [],
      items: [],
      rules: [{ id: 'core-alone', from: 'core', deny: ['workers', 'tests'], allow: [], edge_kinds: [] }],
      files: { 'src/Router.php': ['core'], 'src/Quiet.php': ['core'] },
      files_truncated: false,
    },
  })

/** The turn-end notes the mod tried to hand the model (the kit refuses the append, so they land in the debug log). */
const turnNotes = (w: { logs: { text: string; to: string }[] }) => w.logs.filter(l => l.to === 'debug' && l.text.includes('did not reach the model'))

/** A dashboard with something on the pane: one hub, one hotspot, a cycle and two snapshots of trend. */
const paneDashboard = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    ...(JSON.parse(dashboard) as object),
    hubs: [{ name: 'Router', canonical_name: 'App\\Router', kind: 'class', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 }],
    hotspots: [{ name: 'Kernel', canonical_name: 'App\\Kernel', kind: 'class', score: 7.25 }],
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

/** A dashboard from a knossos that reports issues, cycle members and the header's counts. */
const issuesDashboard = (over: Record<string, unknown> = {}) =>
  paneDashboard({
    summary: {
      components: 1234,
      kinds: [{ kind: 'class', count: 1234 }],
      kinds_truncated: false,
      files: 80,
      languages: [{ language: 'php', files: 70 }, { language: 'typescript', files: 10 }],
      languages_truncated: false,
    },
    boundaries: { items: [{ name: 'Http', source: 'explicit', members: 40 }, { name: 'Core', source: 'explicit', members: 30 }], truncated: false },
    diagnostics: {
      total: 1,
      errors: 1,
      warnings: 0,
      infos: 0,
      items: [{ severity: 'error', code: 'PHP001', message: 'Syntax error, unexpected end of file', path: 'src/Broken.php', line: 9 }],
    },
    largest_files: [{ path: 'src/Http/Router.php', language: 'php', lines: 900 }],
    policy: {
      status: 'evaluated',
      total: 2,
      truncated: false,
      truncation_reasons: [],
      items: [
        {
          policy_id: 'core-stays-pure',
          source: 'App\\Core\\Kernel::boot',
          source_kind: 'method',
          source_boundary: 'Core',
          target: 'App\\Http\\Router',
          target_kind: 'class',
          target_boundary: 'Http',
          path: 'src/Core/Kernel.php',
          line: 12,
        },
      ],
    },
    dead_code: [
      { name: 'unused', canonical_name: 'App\\Http\\Router::unused', kind: 'method', boundary: 'Http', reachability: 'unreferenced', confidence: 'possible', path: 'src/Http/Router.php', line: 40 },
    ],
    cycles: {
      count: 1,
      truncated: false,
      truncation_reasons: [],
      largest: [
        {
          size: 3,
          members: ['boot', 'route', 'dispatch'],
          nodes: [
            { name: 'boot', canonical_name: 'App\\Core\\Kernel::boot', kind: 'method', boundary: 'Core' },
            { name: 'route', canonical_name: 'App\\Http\\Router::route', kind: 'method', boundary: 'Http' },
            { name: 'dispatch', canonical_name: 'App\\Http\\Router::dispatch', kind: 'method', boundary: 'Http' },
          ],
          nodes_truncated: false,
        },
      ],
    },
    ...over,
  })

/** A dashboard that also sends the boundary matrix: Core may not depend on Http, and one of its classes does. */
const boundariesDashboard = (over: Record<string, unknown> = {}) =>
  issuesDashboard({
    boundary_matrix: {
      boundaries: ['Http', 'Core', 'module:cli (+composer:app/cli)'],
      members: [40, 30, 5],
      boundaries_truncated: false,
      cells: [
        [120, 14, 0],
        [3, 80, 0],
        [6, 0, 9],
      ],
      forbidden: [
        [1, 0],
        [1, 2],
      ],
      edges: 232,
      truncated: false,
      truncation_reasons: [],
    },
    ...over,
  })

/** What `component-detail --json` prints now: counterparts with edge counts and boundaries, and annotations. */
const fullDetailOf = (name: string) =>
  JSON.stringify({
    ...(JSON.parse(detailOf(name)) as object),
    component: {
      name: `App\\${name}`,
      display_name: name,
      kind: 'class',
      path: `src/Http/${name}.php`,
      line: 3,
      boundary: 'Http',
      boundaries: ['Http'],
      used_by: {
        count: 2,
        truncated: false,
        names: ['Kernel', 'Console'],
        items: [
          { name: 'Kernel', canonical_name: 'App\\Core\\Kernel', kind: 'class', boundary: 'Core', edges: 4 },
          { name: 'Console', canonical_name: 'App\\Cli\\Console', kind: 'class', boundary: null, edges: 1 },
        ],
      },
      uses: {
        count: 1,
        truncated: false,
        names: ['Request'],
        items: [{ name: 'Request', canonical_name: 'App\\Http\\Request', kind: 'class', boundary: 'Http', edges: 2 }],
      },
      annotations: [{ kind: 'note', value: 'The one way in.' }],
    },
  })

/** What `file-detail --json` prints for a file two others depend on (fourteen in all). */
const fileDetailOf = (path: string) =>
  JSON.stringify({
    status: 'ok',
    path: `${ROOT}/${path}`,
    project_id: 'p1',
    snapshot_id: 's1',
    file: {
      path,
      language: 'php',
      lines: 120,
      boundary: 'Http',
      dependents: {
        count: 14,
        truncated: true,
        boundaries: ['Core', 'tests'],
        items: [
          { path: 'src/Core/Kernel.php', edges: 6, boundary: 'Core' },
          { path: 'tests/Http/RouterTest.php', edges: 2, boundary: 'tests' },
        ],
      },
      components: {
        count: 2,
        truncated: false,
        items: [
          { name: 'Router', canonical_name: 'App\\Router', kind: 'class', line: 7, boundary: 'Http', used_by: 9 },
          { name: 'route', canonical_name: 'App\\Router::route', kind: 'method', line: 20, boundary: 'Http', used_by: 3 },
        ],
      },
    },
  })

/**
 * The cells a row's hotkey Buttons add on the terminal: it draws `k: label`
 * where the found text holds the label alone.
 */
function hotkeyPrefixes(row: { children: unknown[] }): number {
  return row.children.filter(c => {
    const child = c as { type?: unknown; props?: { hotkey?: unknown } } | null
    return typeof child === 'object' && child !== null && child.type === 'Button' && child.props?.hotkey !== undefined
  }).length * 3
}

/**
 * A row's text as the surface draws it: a Markdown link (`[label](file:…)`)
 * shows its label, unescaped, where the kit's text holds the markdown.
 */
function drawn(text: string): string {
  return text.replace(/\[((?:\\.|[^\]\\])*)\]\(file:[^)]*\)/g, (_, label: string) => label.replace(/\\(.)/g, '$1'))
}

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
  test('a session.start that cannot register /knossos yet still starts up, and registers it on a retry', async ($, on) => {
    // After a hot reload no session may be bound yet: the first registrations throw.
    let refusals = 2
    const w = world(on, { refuseRegister: () => refusals-- > 0 })
    const registered = w.registered
    await $.session.start(START)
    await w.clock.settle()
    // Start-up went on regardless: the dashboard loaded.
    expect(w.dashboardRuns()).toHaveLength(1)
    expect(registered).toEqual([])
    await w.clock.advance(5_000)
    expect(registered).toEqual(['knossos'])
    // Registered once; later retries and turns leave it alone.
    await w.clock.advance(60_000)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(registered).toEqual(['knossos'])
    expect(w.logs.filter(l => l.to === 'debug' && l.text.includes('could not register /knossos'))).toHaveLength(1)
  })

  test('a registration still refused after the timed retries is tried again at the end of a turn', async ($, on) => {
    let refusing = true
    const w = world(on, { refuseRegister: () => refusing })
    const registered = w.registered
    await $.session.start(START)
    await w.clock.advance(600_000)
    expect(registered).toEqual([])
    refusing = false
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(registered).toEqual(['knossos'])
  })

  test('a mod that turned itself off stops retrying the registration, on its timer and at a turn end', async ($, on) => {
    let attempts = 0
    const w = world(on, {
      dashboard: [{ stdout: '{"status":"no-binary"}' }],
      refuseRegister: () => {
        attempts++
        return true
      },
    })
    await $.session.start(START)
    await w.clock.settle()
    expect(attempts).toBe(1)
    await w.clock.advance(600_000)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(attempts).toBe(1)
    expect(w.registered).toEqual([])
  })

  test('a retry still in flight when a new session starts does not start a second retry chain', async ($, on) => {
    let attempts = 0
    const w = world(on, {
      refuseRegister: async () => {
        attempts++
        // The first timed retry is slow to be refused: a new session starts while it waits.
        if (attempts === 2) await w.clock.sleep(100)
        return true
      },
    })
    await $.session.start(START)
    await w.clock.advance(550)
    expect(attempts).toBe(2)
    await $.session.start(START)
    await w.clock.advance(600_000)
    // One attempt at each start, the slow retry, then the second start's seven timed retries: no more.
    expect(attempts).toBe(1 + 1 + 1 + 7)
  })

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

  test('the band names the declared boundaries a turn reaches, after its figures and age', async ($, on) => {
    const reaching = brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['namespace:App', 'Http', 'Core'], boundary: 'Http' } } })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], brief: [{ stdout: reaching }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await bandText($)).toMatch(/^knossos · 1 file → 41 dependents · as of 0s ago · reaching Core, Http details/)
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
    const w = world(on, { dashboard: [{ stdout: '{"status":"no-binary"}' }] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls.filter(c => c[2] === 'dashboard')).toHaveLength(1)
    const ran = await edit($, `${ROOT}/src/Router.php`)
    expect(ran.context ?? []).toHaveLength(0)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
    expect(w.toasts).toHaveLength(0)
    expect(w.logs).toHaveLength(1)
    expect(w.logs[0]?.text).toContain('no knossos binary found')
    expect(await bandText($)).toBeUndefined()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'empty' })).toBeUndefined()
    expect(await ui.find({ key: 'pane' })).toBeUndefined()
    await ui.unmount()
  })

  test('a binary that goes missing mid-session turns the mod off', async ($, on) => {
    const w = world(on, { brief: [{ stdout: '{"status":"no-binary"}' }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.logs.map(l => l.text)).toEqual(['knossos: no knossos binary found; the band and pane are off for this session.'])
    expect(await bandText($)).toBeUndefined()
    expect((await edit($, `${ROOT}/src/Router.php`)).context ?? []).toHaveLength(0)
  })

  test('a silent first dashboard is asked again at the next dirty turn, never disabling', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: '' }, { stdout: '' }, { stdout: dashboard }] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls.filter(c => c[2] === 'dashboard')).toHaveLength(1)
    expect(w.logs).toHaveLength(0)
    expect(await bandText($)).toBeUndefined()
    for (let i = 0; i < 2; i++) {
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    expect(w.briefRuns()).toHaveLength(2)
    expect(w.calls.filter(c => c[2] === 'dashboard')).toHaveLength(3)
    expect(w.logs).toHaveLength(0)
    expect(await bandText($)).toContain('1 file → 41 dependents')
    // The fan-in map arrived with the third dashboard, so the note is back.
    expect((await edit($, `${ROOT}/src/Router.php`)).context?.join('\n')).toContain('41 dependent files')
  })

  test('an error dashboard never replaces good figures', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: '{"status":"error"}' }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, '')
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'top-0' }))?.text).toContain('Router')
    expect((await ui.find({ key: 'title' }))?.text).toContain('● refresh failed · 1s')
    await ui.unmount()
  })

  test('the pane refreshes when the details button opens it, and its age keeps counting', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const before = w.calls.filter(c => c[2] === 'dashboard').length
    const band = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'AbovePrompt', props: BAND_PROPS })
    await band.press({ key: 'details' })
    await band.unmount()
    await w.clock.settle()
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBe(before + 1)
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh · 1s$/)
    await w.clock.advance(5_000)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh · 6s$/)
    await ui.unmount()
  })

  test('a failed refresh keeps the old figures and says how old they are', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: '' }] })
    await $.session.start(START)
    await w.clock.settle()
    await w.clock.advance(10_000)
    await slash($, '')
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● refresh failed · 11s$/)
    expect((await ui.find({ key: 'top-0' }))?.text).toContain('Router')
    await ui.unmount()
  })

  test('a walk cut short marks hubs and hotspots partial', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs_truncated: true, hubs_truncation_reasons: ['time_limit'] }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'top-head' }))?.text).toContain('partial')
    await ui.press({ key: 'tab:hubs' })
    expect((await ui.find({ key: 'hubs-head' }))?.text).toContain('partial')
    await ui.unmount()
  })

  test('an edit through a linked checkout lands in the project', async ($, on) => {
    const w = world(on, {}, { root: '/link/repo', links: { '/link/repo': ROOT } })
    await $.session.start(START)
    await w.clock.settle()
    const ran = await edit($, '/link/repo/src/Router.php')
    expect(ran.context?.join('\n')).toContain('src/Router.php has 41 dependent files')
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[0]).toContain('--files=src/Router.php')
  })

  test('before the first dashboard, a linked session root and a deleted file still count', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: '' }] }, {
      root: '/link/repo',
      links: { '/link/repo': ROOT },
      gone: ['/link/repo/src/Gone.php'],
    })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, '/link/repo/src/Gone.php')
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[0]).toContain(`--files=${ROOT}/src/Gone.php`)
  })

  test('a threshold that is not a whole number in range falls back to 20', { options: { fanInThreshold: 2.5 } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls[0]).toContain('--fan-in-threshold=20')
  })

  test('a threshold above the command limit falls back to 20', { options: { fanInThreshold: 100_001 } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    expect(w.calls[0]).toContain('--fan-in-threshold=20')
  })

  test('the allow-root hint names the roots file and the refused root', async ($, on) => {
    const refused = brief({ status: 'not-allowed', path: `${ROOT}/src`, roots_file: '/data/roots.json', refused_root: ROOT })
    const w = world(on, { brief: [{ stdout: refused }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await bandText($)).toContain(`not an allowed root: KNOSSOS_ROOTS_FILE='/data/roots.json' knossos allow-root '${ROOT}' --execute`)
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
    await w.clock.settle()
    // Opening reads the dashboard afresh; closing reads nothing.
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBe(before + 1)
    expect((await slash($, '  ')).text).toBe('Knossos pane closed.')
    await w.clock.settle()
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

  test('the overview shows health and the most depended on, on both surfaces', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'title' }))?.text).toMatch(/^repo +● fresh · 1s$/)
      expect((await ui.find({ key: 'summary' }))?.text).toBe('2 hubs · 1 cycle · 4 dead code · 0 drifted')
      expect((await ui.find({ key: 'health-cycles' }))?.text).toMatch(/cycles +1/)
      expect((await ui.find({ key: 'health-degree' }))?.text).toMatch(/max degree +12/)
      expect((await ui.find({ key: 'health-dead' }))?.text).toMatch(/dead code +4/)
      expect((await ui.find({ key: 'top-0' }))?.text).toMatch(/^› +Router .*41$/)
      // A hotspot that is not a hub is listed once, marked.
      expect((await ui.find({ key: 'top-1' }))?.text).toMatch(/◆ Kernel/)
      expect(await ui.find({ key: 'tab-rule' })).toEqual(surface === 'terminal' ? expect.anything() : undefined)
      await ui.unmount()
    }
  })

  test('truncated counts read as lower bounds', async ($, on) => {
    const cycles = { count: 50, truncated: true, truncation_reasons: ['cycle_limit'], largest: [] }
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ cycles, dead_code_candidates: 100, dead_code_truncated: true }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'health-cycles' }))?.text).toContain('50+')
    expect((await ui.find({ key: 'health-dead' }))?.text).toContain('100+')
    await ui.unmount()
  })

  test('a trend is drawn only from five snapshots that move', async ($, on) => {
    const trend = [1, 3, 2, 2, 4].map((cycles, i) => ({ snapshot_id: `s${i}`, cycles, max_degree: 10 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ trend }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    // Two snapshots: numbers only.
    expect((await ui.find({ key: 'health-head' }))?.text).not.toContain('trend')
    await slash($, '')
    await w.clock.settle()
    expect((await ui.find({ key: 'health-head' }))?.text).toContain('trend (5 scans)')
    expect((await ui.find({ key: 'health-cycles' }))?.text).toContain('▁▆▃▃█')
    // A flat line says nothing.
    expect((await ui.find({ key: 'health-degree' }))?.text).not.toMatch(/[▁-█]/)
    await ui.unmount()
  })

  test('the tabs switch by their hotkey buttons, and the hubs tab lists every ranked component', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      const tabs = await ui.findAll({ type: 'Button' })
      // Six tabs at 60 columns: the active one by name, the others by digit.
      const labelled = (t: (typeof tabs)[number]) => (t.props.hotkey === undefined ? String(t.text) : `${String(t.props.hotkey)}: ${t.text}`)
      expect(tabs.filter(t => String(t.key).startsWith('tab:')).map(labelled)).toEqual(['1: Overview', '2', '3', '4', '5', '6'])
      // Each digit-only tab keeps its hotkey on a hidden twin.
      expect(tabs.filter(t => String(t.key).startsWith('tabkey:')).map(t => `${String(t.props.hotkey)} ${String(t.key)}`)).toEqual([
        '2 tabkey:hubs',
        '3 tabkey:boundaries',
        '4 tabkey:cycles',
        '5 tabkey:issues',
        '6 tabkey:changes',
      ])
      await ui.press({ key: 'tabkey:cycles' })
      expect((await ui.find({ key: 'tab:cycles' }))?.text).toBe('Cycles')
      expect((await ui.find({ key: 'tab:cycles' }))?.props.hotkey).toBe('4')
      expect((await ui.find({ key: 'tab:overview' }))?.text).toBe('1')
      await ui.press({ key: 'tabkey:overview' })
      // The active tab is drawn at full strength, the others dim.
      expect((await ui.find({ key: 'tab:overview' }))?.props.dimColor).toBeUndefined()
      expect((await ui.find({ key: 'tab:hubs' }))?.props.dimColor).toBe(true)
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'tab:hubs' }))?.props.dimColor).toBeUndefined()
      expect((await ui.find({ key: 'hub-head' }))?.text).toMatch(/name +in out cross$/)
      expect((await ui.find({ key: 'hub-0' }))?.text).toMatch(/^› +Router .*41 +3 +2$/)
      expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/◆ Kernel/)
      await ui.press({ key: 'tab:boundaries' })
      expect((await ui.find({ key: 'pane' }))?.text).toContain('sends no boundary map')
      expect(await ui.find({ key: 'hub-0' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      expect(await ui.find({ key: 'top-0' })).toBeDefined()
      await ui.unmount()
    }
  })

  test('j and k move the selection marker within the list, and o opens the marked row', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'down' }))?.props.hotkey).toBe('j')
    expect((await ui.find({ key: 'up' }))?.props.hotkey).toBe('k')
    expect((await ui.find({ key: 'open' }))?.props.hotkey).toBe('o')
    await ui.press({ key: 'up' })
    expect((await ui.find({ key: 'top-0' }))?.text).toMatch(/^›/)
    await ui.press({ key: 'down' })
    await ui.press({ key: 'down' })
    expect((await ui.find({ key: 'top-0' }))?.text).toMatch(/^ /)
    expect((await ui.find({ key: 'top-1' }))?.text).toMatch(/^›/)
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Kernel')
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['App\\Kernel'])
    await ui.unmount()
  })

  test('the focus ring moving onto a row moves the marker with it, and Enter there opens it', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await $.ui.focus({ requestId: 'knossos', key: 'row:1' })).toEqual({})
    expect((await ui.find({ key: 'top-1' }))?.text).toMatch(/^›/)
    // Onto a tab: the marker stays on the list.
    await $.ui.focus({ requestId: 'knossos', key: 'tab:hubs' })
    expect((await ui.find({ key: 'top-1' }))?.text).toMatch(/^›/)
    // Enter on the focused row presses it.
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Kernel')
    await ui.press({ key: 'back' })
    expect((await ui.find({ key: 'top-1' }))?.text).toMatch(/^›/)
    await ui.unmount()
  })

  test('a focus ring landing on a hidden tab twin moves onto its visible tab, and back past it', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    // Forward: from Overview onto Hubs' twin lands on Hubs.
    await $.ui.focus({ requestId: 'knossos', key: 'tab:overview' })
    await $.ui.focus({ requestId: 'knossos', key: 'tabkey:hubs' })
    // Backward from Hubs, its twin comes first: the ring goes on to Overview.
    await $.ui.focus({ requestId: 'knossos', key: 'tabkey:hubs' })
    expect(w.focuses).toEqual(['tab:overview', 'tab:hubs', 'tab:overview'])
    await ui.unmount()
  })

  test('a Read of a hub in a policed boundary adds its dependents, boundary and rules, once per file', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const first = await readFile($, `${ROOT}/src/Router.php`)
    expect(first.context).toEqual(['knossos: src/Router.php (core) has 41 dependent files. Policy: core may not depend on workers, tests.'])
    expect((await readFile($, `${ROOT}/src/Router.php`)).context ?? []).toHaveLength(0)
    // The rules were stated: a quiet file in the same boundary adds nothing.
    expect((await readFile($, `${ROOT}/src/Quiet.php`)).context ?? []).toHaveLength(0)
    // A hub outside any boundary gets its count alone.
    expect((await readFile($, `${ROOT}/bin/router.php`)).context).toEqual(['knossos: bin/router.php has 30 dependent files.'])
    // Already told on Read: the edit adds nothing more, and a file outside the project nothing at all.
    expect((await edit($, `${ROOT}/src/Router.php`)).context ?? []).toHaveLength(0)
    expect((await readFile($, '/etc/hosts')).context ?? []).toHaveLength(0)
    expect(w.toasts).toHaveLength(0)
  })

  test('a quiet file in a policed boundary gets the rules when it is the first one read there', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    expect((await readFile($, `${ROOT}/src/Quiet.php`)).context).toEqual(['knossos: src/Quiet.php is in core. Policy: core may not depend on workers, tests.'])
    expect((await readFile($, `${ROOT}/src/Router.php`)).context).toEqual(['knossos: src/Router.php (core) has 41 dependent files.'])
  })

  test('a subagent is told on its own: what the main loop read was never in its context', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await readFile($, `${ROOT}/src/Router.php`)
    const sub = await $.tool.call({ tool: 'Read', file_path: `${ROOT}/src/Router.php`, agentId: 'a1' } as never)
    expect((sub as { context?: string[] }).context?.[0]).toContain('Policy: core may not depend on workers, tests.')
  })

  test('a boundary declared past the dashboard\'s short list is still named on a Read', async ($, on) => {
    const crowded = JSON.stringify({
      ...(JSON.parse(dashboard) as object),
      fan_in: [{ path: 'src/Router.php', dependent_files: 41, boundaries: [], boundary: 'routing' }],
      boundaries: {
        items: Array.from({ length: 12 }, (_, i) => ({ name: `big${i}`, source: 'explicit', members: 900 - i })),
        truncated: true,
        declared: ['routing', ...Array.from({ length: 12 }, (_, i) => `big${i}`)],
        declared_truncated: false,
      },
    })
    const w = world(on, { dashboard: [{ stdout: crowded }] })
    await $.session.start(START)
    await w.clock.settle()
    expect((await readFile($, `${ROOT}/src/Router.php`)).context).toEqual(['knossos: src/Router.php (routing) has 41 dependent files.'])
  })

  test('notes to the model stop at three a turn and start again with the next', async ($, on) => {
    const many = JSON.stringify({
      ...(JSON.parse(dashboard) as object),
      fan_in: ['a', 'b', 'c', 'd'].map(n => ({ path: `src/${n}.php`, dependent_files: 50, boundaries: [], boundary: null })),
    })
    const w = world(on, { dashboard: [{ stdout: many }] })
    await $.session.start(START)
    await w.clock.settle()
    const told = []
    for (const n of ['a', 'b', 'c', 'd']) told.push(((await readFile($, `${ROOT}/src/${n}.php`)).context ?? []).length)
    expect(told).toEqual([1, 1, 1, 0])
    await $.turn.complete(TURN)
    await w.clock.settle()
    // Dropped for the cap, not told: the next turn's Read still gets it.
    expect((await readFile($, `${ROOT}/src/d.php`)).context).toEqual(['knossos: src/d.php has 50 dependent files.'])
  })

  test('an edit note held back by the cap is not marked told: the next turn still gets it', async ($, on) => {
    const many = JSON.stringify({
      ...(JSON.parse(dashboard) as object),
      fan_in: ['a', 'b', 'c', 'd'].map(n => ({ path: `src/${n}.php`, dependent_files: 50, boundaries: [], boundary: null })),
    })
    const w = world(on, { dashboard: [{ stdout: many }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const n of ['a', 'b', 'c']) await readFile($, `${ROOT}/src/${n}.php`)
    expect((await edit($, `${ROOT}/src/d.php`)).context ?? []).toEqual([])
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await edit($, `${ROOT}/src/d.php`)).context).toEqual(['knossos: src/d.php has 50 dependent files; run test_impact before finishing.'])
  })

  test('a note that throws leaves the tool result as it was and one debug line', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }] }, { garbled: [`${ROOT}/src/Router.php`, `${ROOT}/src/Quiet.php`] })
    await $.session.start(START)
    await w.clock.settle()
    const read = await readFile($, `${ROOT}/src/Router.php`)
    expect(read.text).toBe('ran Read')
    expect(read.context ?? []).toEqual([])
    expect((await edit($, `${ROOT}/src/Quiet.php`)).text).toBe('ran Edit')
    expect(w.logs.filter(l => l.to === 'debug' && l.text.startsWith('knossos: a note after a tool call failed'))).toHaveLength(1)
  })

  test('agentNotes off keeps every note from the model', { options: { agentNotes: false } }, async ($, on) => {
    const violation = { policy_id: 'p', source: 'a', target: 'b', source_boundaries: [], target_boundaries: [] }
    const w = world(on, {
      dashboard: [{ stdout: policedDashboard() }],
      brief: [{ stdout: brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }], policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false } }) }],
    })
    await $.session.start(START)
    await w.clock.settle()
    expect((await readFile($, `${ROOT}/src/Router.php`)).context ?? []).toHaveLength(0)
    expect((await edit($, `${ROOT}/src/Router.php`)).context ?? []).toHaveLength(0)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect(turnNotes(w)).toHaveLength(0)
  })

  test('a session below the project root is told, and copies, test commands that change to the project root', async ($, on) => {
    const reached = brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }] })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], brief: [{ stdout: reached }] }, { root: `${ROOT}/packages/web` })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w).map(l => l.text.slice(l.text.indexOf('): ') + 3))).toEqual([
      `knossos: 1 test reaches this turn's changes. Run: cd ${ROOT} && vendor/bin/phpunit tests/RouterTest.php`,
    ])
    const ui = await mountPane($)
    await ui.press({ key: 'tests' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe(`cd ${ROOT} && vendor/bin/phpunit tests/RouterTest.php`)
    await ui.unmount()
  })

  test('the turn-end note names the tests that reach the changes and how to run them, once', async ($, on) => {
    const reached = brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }, { path: 'tests/Support/Helper.php', distance: 1 }] })
    const w = world(on, { brief: [{ stdout: reached }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w).map(l => l.text.slice(l.text.indexOf('): ') + 3))).toEqual([
      "knossos: 1 test reaches this turn's changes. Run: vendor/bin/phpunit tests/RouterTest.php",
    ])
    // Named once: the same tests reaching the next turn's changes are not named again.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(1)
  })

  test('a turn that ran the tests after its last edit gets no tests note', async ($, on) => {
    const reached = brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }] })
    const w = world(on, { brief: [{ stdout: reached }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await bash($, 'vendor/bin/phpunit --filter RouterTest')
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(0)
    // Run before the edit: it ran the old code, so the note comes.
    await bash($, 'vendor/bin/phpunit --filter RouterTest')
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(1)
  })

  test('violations and tests share one turn-end note, and a violation is reported once', async ($, on) => {
    const violation = { policy_id: 'p', source: 'App\\A', target: 'App\\B', source_boundaries: [], target_boundaries: [] }
    const turn = brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }], policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false } })
    const w = world(on, { brief: [{ stdout: turn }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(1)
    expect(turnNotes(w)[0]?.text).toContain('knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:\n- p: App\\A → App\\B\n\nknossos: 1 test reaches')
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(1)
  })

  test('the key help line shows and hides', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'help-0' })).toBeUndefined()
    await ui.press({ key: 'keys' })
    expect((await ui.find({ key: 'help-0' }))?.text).toMatch(/1–6 +switch tabs/)
    await ui.press({ key: 'keys' })
    expect(await ui.find({ key: 'help-0' })).toBeUndefined()
    await ui.unmount()
  })

  test('the rescan action shows only for a stale or drifted snapshot', async ($, on) => {
    const fresh = paneDashboard()
    const drifted = paneDashboard({ freshness: { state: 'fresh', age_seconds: 1, drift_files: 3 } })
    const stale = paneDashboard({ freshness: { state: 'stale', age_seconds: 7200, drift_files: 0 } })
    const w = world(on, { dashboard: [{ stdout: fresh }, { stdout: drifted }, { stdout: stale }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'rescan' })).toBeUndefined()
    await slash($, '')
    await w.clock.settle()
    expect((await ui.find({ key: 'rescan' }))?.props.hotkey).toBe('r')
    await slash($, '')
    await slash($, '')
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● stale · 2h {2}rescan$/)
    expect(w.scanRuns()).toEqual([])
    await ui.unmount()
  })

  test('a rescan runs one scan, shows scanning, then refreshes the pane', async ($, on) => {
    const stale = paneDashboard({ freshness: { state: 'stale', age_seconds: 7200, drift_files: 4 } })
    const after = paneDashboard({ snapshot_id: 's2' })
    const w = world(on, { dashboard: [{ stdout: stale }, { stdout: after }], scan: [{ stdout: '{"status":"ok"}', hold: 1000 }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      if (surface === 'terminal') {
        const before = w.dashboardRuns().length
        await ui.press({ key: 'rescan' })
        await ui.press({ key: 'rescan' })
        // Nothing runs inside the press: the scan starts on a timer.
        expect(w.scanRuns()).toEqual([])
        await w.clock.settle()
        expect((await ui.find({ key: 'title' }))?.text).toMatch(/● scanning… · stale · \d+[smh]$/)
        expect(await ui.find({ key: 'rescan' })).toBeUndefined()
        await w.clock.advance(1000)
        await w.clock.settle()
        // A second press while one is coming adds nothing.
        expect(w.scanRuns()).toHaveLength(1)
        expect(w.scanRuns()[0]).toEqual(['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'scan', ROOT])
        expect(w.dashboardRuns().length).toBeGreaterThan(before)
      }
      expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh · 1s$/)
      expect(await ui.find({ key: 'rescan' })).toBeUndefined()
      await ui.unmount()
    }
  })

  test('a rescan that does not land says why and keeps the figures', async ($, on) => {
    const stale = paneDashboard({ freshness: { state: 'stale', age_seconds: 7200, drift_files: 4 } })
    const w = world(on, { dashboard: [{ stdout: stale }], scan: [{ stdout: '{"status":"not-allowed"}' }, { stdout: '' }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    const loads = w.dashboardRuns().length
    await ui.press({ key: 'rescan' })
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toContain('● rescan failed: not an allowed root')
    expect((await ui.find({ key: 'top-0' }))?.text).toContain('Router')
    expect(w.dashboardRuns().length).toBe(loads)
    // Still stale, so it can be tried again; silence is a failure too.
    await ui.press({ key: 'rescan' })
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toContain('● rescan failed: knossos said nothing')
    expect(w.scanRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test('a rescan and a turn scan never run at once', async ($, on) => {
    const stale = paneDashboard({ freshness: { state: 'stale', age_seconds: 7200, drift_files: 4 } })
    const w = world(on, {
      dashboard: [{ stdout: stale }],
      scan: [{ stdout: '{"status":"ok"}', hold: 3000 }],
      brief: [{ stdout: brief(), hold: 1000 }],
    })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'rescan' })
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    // The turn's scan waits for the rescan.
    expect(w.briefRuns()).toEqual([])
    await w.clock.advance(3000)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    await ui.unmount()
  })

  test('nothing in the pane is wider than its body', async ($, on) => {
    const long = boundariesDashboard({
      project_root: '/work/a-project-with-a-rather-long-directory-name',
      freshness: { state: 'stale', age_seconds: 40_000, drift_files: 41 },
      hubs: [
        { name: 'ArchitectureQueryService', canonical_name: 'Knossos\\Query\\ArchitectureQueryService', kind: 'class', boundary: 'namespace:Knossos', in_degree: 262, out_degree: 8, cross_boundary_degree: 1 },
        { name: 'symbol', canonical_name: 'Knossos\\Store\\StableId::symbol', kind: 'method', boundary: 'core', in_degree: 199, out_degree: 1, cross_boundary_degree: 0 },
      ],
    })
    const touched = brief({
      changed_files: ['src/Http/a/rather/deeply/nested/directory/with/a/LongControllerName.php'],
      impact: { 'src/Http/a/rather/deeply/nested/directory/with/a/LongControllerName.php': { path: 'src/Http/a/rather/deeply/nested/directory/with/a/LongControllerName.php', dependent_files: 1234, boundaries: ['Http', 'Core'] } },
      tests: [{ path: 'tests/phpunit/Http/a/rather/deeply/nested/directory/LongControllerNameTest.php', distance: 1 }],
    })
    const w = world(on, { dashboard: [{ stdout: long }], detail: [{ stdout: fullDetailOf('ArchitectureQueryService') }], brief: [{ stdout: touched }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Http/x.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      for (const bodyColumns of [40, 60, 90, 120]) {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns } })
        for (const step of ['tab:overview', 'tab:hubs', 'filter', 'tab:boundaries', 'tab:cycles', 'tab:issues', 'tab:changes', 'keys', 'tab:hubs', 'row:0']) {
          await ui.press({ key: step })
          await w.clock.settle()
          const boxes = await ui.findAll({ type: 'Box' })
          // A hidden tab twin draws nothing: its label is out of the strip's width.
          const hidden = boxes.filter(b => b.props.display === 'none').reduce((n, b) => n + [...b.text].length, 0)
          const rows = boxes.filter(b => b.key !== 'pane' && b.key !== 'detail' && b.props.display !== 'none')
          expect(rows.length).toBeGreaterThan(5)
          for (const row of rows) {
            const width = [...drawn(row.text)].length + hotkeyPrefixes(row) - (row.key === 'tabs' ? hidden : 0)
            expect(width, `${surface} ${bodyColumns} ${step} ${String(row.key)}: ${row.text}`).toBeLessThanOrEqual(bodyColumns)
          }
          for (const grid of await ui.findAll({ type: 'Raster' })) {
            expect(grid.props.columns, `${surface} ${bodyColumns} ${step} raster`).toBeLessThanOrEqual(bodyColumns)
          }
        }
        await ui.press({ key: 'back' })
        await ui.unmount()
      }
    }
  })

  test('the pane without data says how to get some', async ($, on) => {
    const unscanned = JSON.stringify({ ...(JSON.parse(dashboard) as object), status: 'unscanned' })
    const w = world(on, { dashboard: [{ stdout: unscanned }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'empty' }))?.text).toContain('knossos scan')
    expect(await ui.find({ key: 'pane' })).toBeUndefined()
    await ui.unmount()
  })

  test('pressing a hub shows its detail and back returns', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    const detail = (await ui.find({ key: 'detail' }))?.text
    expect(detail).toContain('App\\Router')
    expect(detail).toMatch(/Used by 1 *› *Kernel/)
    expect(w.detailRuns()).toEqual([
      ['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'component-detail', ROOT, 'App\\Router'],
    ])
    await ui.press({ key: 'back' })
    expect(await ui.find({ key: 'detail' })).toBeUndefined()
    expect(await ui.find({ key: 'pane' })).toBeDefined()
    await ui.unmount()
  })

  test('pressing a hotspot shows its detail', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Kernel')
    // Looked up by its canonical name, shown by its display name.
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['App\\Kernel'])
    await ui.unmount()
  })

  test('a press shows the loading line before knossos answers', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router'), hold: 1000 }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    // Drawn from state alone: no process has run inside the render.
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Inspecting Router…')
    await w.clock.settle()
    expect(w.detailRuns()).toHaveLength(1)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Inspecting Router…')
    await w.clock.advance(1000)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Router')
    await ui.unmount()
  })

  test('two presses during the wait run one lookup', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router'), hold: 1000 }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await w.clock.advance(1000)
    expect(w.detailRuns()).toHaveLength(1)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Router')
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
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    // Router's answer arrives while Kernel is on screen.
    await w.clock.advance(1000)
    const text = (await ui.find({ key: 'detail' }))?.text
    expect(text).toContain('Inspecting Kernel…')
    expect(text).not.toContain('App\\Router')
    await w.clock.advance(4000)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Kernel')
    await ui.unmount()
  })

  test('pressing A, B, A within one lookup shows A again and stores its answer', async ($, on) => {
    const w = world(on, {
      dashboard: [{ stdout: paneDashboard() }],
      detail: [{ stdout: detailOf('Router'), hold: 1000 }, { stdout: detailOf('Kernel'), hold: 5000 }],
    })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Inspecting Router…')
    await w.clock.advance(1000)
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Router')
    expect(w.detailRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test('a component is looked up once per snapshot', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Router')
    expect(w.detailRuns()).toHaveLength(1)
    await ui.unmount()
  })

  test('a new snapshot looks the component up again', async ($, on) => {
    const w = world(on, {
      // Start, then the refresh the open schedules, then the scan's: only the last is a new snapshot.
      dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard() }, { stdout: paneDashboard({ snapshot_id: 's2' }) }],
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
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Router')
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
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('No details for Router')
    await ui.press({ key: 'back' })
    await ui.press({ key: 'row:0' })
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
    expect(await ui.find({ key: 'pane' })).toBeDefined()
    await ui.unmount()
  })

  test('edits a silent brief never saw are reported again by the next one', async ($, on) => {
    const w = world(on, { brief: [{ stdout: '' }, { stdout: brief({ status: 'scan-failed', reason: 'x' }) }, { stdout: brief() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Kernel.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Other.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const files = (run: string[] | undefined) => (run ?? []).filter(a => a.startsWith('--files=')).sort()
    expect(files(w.briefRuns()[1])).toEqual(['--files=src/Kernel.php', '--files=src/Router.php'])
    expect(files(w.briefRuns()[2])).toEqual(['--files=src/Kernel.php', '--files=src/Other.php', '--files=src/Router.php'])
  })

  test('an older, slower dashboard load never lands over a newer one', async ($, on) => {
    const newer = paneDashboard({
      snapshot_id: 's2',
      hubs: [{ name: 'Newer', canonical_name: 'App\\Newer', kind: 'class', in_degree: 50, out_degree: 1, cross_boundary_degree: 0 }],
    })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard(), hold: 5_000 }, { stdout: newer }] })
    await $.session.start(START)
    await w.clock.settle()
    // The open's reload is slow; the scan's reload after it reads the newer snapshot.
    await slash($, '')
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await w.clock.advance(5_000)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'top-0' }))?.text).toContain('Newer')
    await ui.unmount()
  })

  test('a closed pane stops the age tick redrawing', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, '')
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.unmount()
    await slash($, '')
    await w.clock.settle()
    const before = w.invalidations.length
    await w.clock.advance(5_000)
    expect(w.invalidations.length).toBe(before)
  })

  test('the issues tab lists violations, diagnostics, dead code and files, and opens what it lists', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], detail: [{ stdout: fullDetailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      // Two violations and one error: the label counts them.
      expect((await ui.find({ key: 'tab:issues' }))?.text).toBe('5³')
      await ui.press({ key: 'tab:issues' })
      const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
      expect(text).toMatch(/Policy violations *▲ 2/)
      expect(text).toContain('Kernel::boot')
      expect(text).toContain('Kernel.php:12')
      expect(text).toMatch(/Diagnostics *1 error · 0 warnings/)
      expect(text).toContain('PHP001 Syntax error')
      expect(text).toMatch(/Dead code *4 · first 1/)
      expect(text).toContain('Router::unused')
      expect(text).toContain('src/Http/Router.php')
      expect((await ui.find({ key: 'pol-0' }))?.text).toMatch(/^›/)
      await ui.press({ key: 'down' })
      expect((await ui.find({ key: 'dead-0' }))?.text).toMatch(/^›/)
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    const ui = await mountPane($)
    await ui.press({ key: 'tab:issues' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['App\\Core\\Kernel::boot'])
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Used by 2')
    await ui.unmount()
  })

  test('the cycles tab draws each cycle as a chain coloured by boundary', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      await ui.press({ key: 'tab:cycles' })
      expect((await ui.find({ key: 'cycles-head' }))?.text).toMatch(/^Cycles +1 · largest first$/)
      expect((await ui.find({ key: 'cycle-0' }))?.text).toContain('cycle 1 · 3 members')
      expect((await ui.find({ key: 'chain-0' }))?.text).toBe('   Kernel::boot → Router::route → Router::dispatch ↺')
      const boot = await ui.find({ type: 'Text', text: 'Kernel::boot' })
      const route = await ui.find({ type: 'Text', text: 'Router::route' })
      expect(boot?.props.color).toBeDefined()
      expect(boot?.props.color).not.toBe(route?.props.color)
      // Most members are in Http: the cycle's line names it; Core, where it crosses, is the one colour on the chain.
      expect((await ui.find({ key: 'cycle-0' }))?.text).toBe('›  cycle 1 · 3 members · Http')
      expect(route?.props.color).toBeUndefined()
      expect((await ui.find({ key: 'cycles-legend' }))?.text).toBe('   ■ Core')
      expect((await ui.find({ key: 'down' }))?.props.hotkey).toBe('j')
      await ui.unmount()
    }
  })

  test('a marked cycle copies its chain, asks how to break it on q alone, and opens the member where it crosses', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], detail: [{ stdout: fullDetailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:cycles' })
    const chain = 'App\\Core\\Kernel::boot → App\\Http\\Router::route → App\\Http\\Router::dispatch → App\\Core\\Kernel::boot'
    await ui.press({ key: 'copy' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe(chain)
    expect(w.toasts.at(-1)).toBe(`Copied cycle 1: ${chain}`)
    expect(w.prompts).toEqual([])
    await ui.press({ key: 'ask' })
    await w.clock.settle()
    expect(w.prompts).toEqual([`Using the Knossos graph, how could I break this dependency cycle: ${chain}? Name the edge to cut and what would have to move.`])
    // Kernel::boot is the member outside Http, the cycle's own boundary: o shows it.
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.at(-1)).toBe('App\\Core\\Kernel::boot')
    expect(await ui.find({ key: 'detail' })).toBeDefined()
    await ui.unmount()
  })

  test('the boundaries tab walks its boundaries, spells out the marked one, and opens nothing', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:boundaries' })
    expect((await ui.find({ key: 'focus-head' }))?.text).toBe('A Http')
    expect((await ui.find({ key: 'focus-out' }))?.text).toMatch(/depends on +Core 14/)
    expect(await ui.find({ key: 'open' })).toBeUndefined()
    await ui.press({ key: 'down' })
    expect((await ui.find({ key: 'focus-head' }))?.text).toBe('B Core')
    expect((await ui.find({ key: 'focus-out' }))?.text).toMatch(/depends on +Http 3/)
    expect((await ui.find({ key: 'focus-forbidden' }))?.text).toMatch(/may not use +× Http +× cli/)
    await ui.press({ key: 'row:2' })
    await w.clock.settle()
    expect((await ui.find({ key: 'focus-head' }))?.text).toBe('C cli')
    expect(w.detailRuns()).toHaveLength(0)
    await ui.press({ key: 'copy' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe('module:cli (+composer:app/cli)')
    expect(w.prompts).toEqual([])
    await ui.press({ key: 'ask' })
    await w.clock.settle()
    expect(w.prompts).toHaveLength(1)
    expect(w.prompts[0]).toContain('what does the boundary module:cli (+composer:app/cli) depend on')
    await ui.unmount()
  })

  test('the detail sets used by beside uses when wide, stacks them when narrow, and opens either', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], detail: [{ stdout: fullDetailOf('Router') }, { stdout: fullDetailOf('Request') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const wide = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100 } })
      await wide.press({ key: 'row:0' })
      await w.clock.settle()
      expect((await wide.find({ key: 'detail-name' }))?.text).toMatch(/^Router +class · Http$/)
      expect(drawn((await wide.find({ key: 'detail-place' }))?.text ?? '')).toBe('src/Http/Router.php:3')
      expect((await wide.find({ key: 'side-0' }))?.text).toMatch(/^Used by 2 +edges +Uses 1 +edges$/)
      expect((await wide.find({ key: 'side-1' }))?.text).toMatch(/Kernel +Core +[━╸]+·* +4 +Request +Http +[━╸]+·* +2$/)
      expect((await wide.find({ key: 'detail' }))?.text).toContain('note: The one way in.')
      // The tab strip gives way to the detail; back is a key and a click.
      expect(await wide.find({ key: 'tab:hubs' })).toBeUndefined()
      expect((await wide.find({ key: 'back' }))?.props.hotkey).toBe('b')
      await wide.unmount()
      const narrow = await mountPane($, surface)
      expect(await narrow.find({ key: 'side-0' })).toBeUndefined()
      expect((await narrow.find({ key: 'used-head' }))?.text).toMatch(/^Used by 2 +edges$/)
      expect((await narrow.find({ key: 'uses-0' }))?.text).toMatch(/Request +Http +[━╸]+·* +2$/)
      await narrow.press({ key: 'back' })
      expect(await narrow.find({ key: 'detail' })).toBeUndefined()
      await narrow.unmount()
    }
    // A counterpart opens in its turn, looked up by its canonical name.
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'rel:2' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.slice(4)).toEqual(['App\\Http\\Request'])
    expect((await ui.find({ key: 'detail-name' }))?.text).toMatch(/^Request /)
    await ui.unmount()
  })

  test('f opens the hubs filter, typing narrows the list, Enter keeps it and x clears it', async ($, on) => {
    const hubs = [
      { name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 },
      { name: 'Request', canonical_name: 'App\\Http\\Request', kind: 'class', boundary: 'Http', in_degree: 30, out_degree: 9, cross_boundary_degree: 0 },
      { name: 'Kernel', canonical_name: 'App\\Core\\Kernel', kind: 'class', boundary: 'Core', in_degree: 12, out_degree: 20, cross_boundary_degree: 5 },
    ]
    const w = world(on, { dashboard: [{ stdout: issuesDashboard({ hubs, hotspots: [] }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      // Only the hubs tab filters.
      expect(await ui.find({ key: 'filter' })).toBeUndefined()
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'filter' }))?.props.hotkey).toBe('f')
      await ui.press({ key: 'filter' })
      await w.clock.settle()
      // The field opens, asking for the focus.
      expect((await ui.find({ type: 'Input', key: 'filter' }))?.props.autoFocus).toBe(true)
      await ui.input({ key: 'filter', text: 'req', kind: 'change' })
      expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Request')
      expect(await ui.find({ key: 'hub-1' })).toBeUndefined()
      await ui.input({ key: 'filter', text: 'R ' })
      expect(await ui.find({ type: 'Input', key: 'filter' })).toBeUndefined()
      expect((await ui.find({ key: 'filter-row' }))?.text).toBe('   filter "R" · 3 of 3')
      await ui.press({ key: 'filter' })
      await ui.input({ key: 'filter', text: 'kern' })
      expect((await ui.find({ key: 'filter-row' }))?.text).toBe('   filter "kern" · 1 of 3')
      expect((await ui.find({ key: 'clear' }))?.props.hotkey).toBe('x')
      await ui.press({ key: 'clear' })
      expect(await ui.find({ key: 'filter-row' })).toBeUndefined()
      expect(await ui.find({ key: 'clear' })).toBeUndefined()
      expect((await ui.find({ key: 'hub-2' }))?.text).toContain('Kernel')
      // An empty Enter clears too.
      await ui.press({ key: 'filter' })
      await ui.input({ key: 'filter', text: 'zzz', kind: 'change' })
      expect((await ui.find({ key: 'hubs-none' }))?.text).toBe('   no hub matches "zzz"')
      await ui.input({ key: 'filter', text: '' })
      expect(await ui.find({ key: 'hubs-none' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('s sorts the hubs by in, out, then cross degree', async ($, on) => {
    const hubs = [
      { name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 },
      { name: 'Request', canonical_name: 'App\\Http\\Request', kind: 'class', boundary: 'Http', in_degree: 30, out_degree: 9, cross_boundary_degree: 0 },
      { name: 'Kernel', canonical_name: 'App\\Core\\Kernel', kind: 'class', boundary: 'Core', in_degree: 12, out_degree: 20, cross_boundary_degree: 5 },
    ]
    const w = world(on, { dashboard: [{ stdout: issuesDashboard({ hubs, hotspots: [] }) }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      await ui.press({ key: 'tab:hubs' })
      const order = async () => {
        const names: (string | undefined)[] = []
        for (const i of [0, 1, 2]) names.push((await ui.find({ key: `hub-${i}` }))?.text.replace(/^[›◆\s]+/, '').split(/\s+/)[0])
        return names
      }
      expect(await order()).toEqual(['Router', 'Request', 'Kernel'])
      expect((await ui.find({ key: 'sort' }))?.props.hotkey).toBe('s')
      expect((await ui.find({ key: 'sort' }))?.text).toBe('sort: in')
      await ui.press({ key: 'sort' })
      expect((await ui.find({ key: 'sort' }))?.text).toBe('sort: out')
      expect((await ui.find({ key: 'hubs-head' }))?.text).toMatch(/by out$/)
      expect(await order()).toEqual(['Kernel', 'Request', 'Router'])
      await ui.press({ key: 'sort' })
      expect(await order()).toEqual(['Kernel', 'Router', 'Request'])
      await ui.press({ key: 'sort' })
      expect((await ui.find({ key: 'sort' }))?.text).toBe('sort: in')
      await ui.unmount()
    }
    // The marker and the open follow the sorted order.
    const ui = await mountPane($)
    await ui.press({ key: 'tab:hubs' })
    await ui.press({ key: 'sort' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(w.detailRuns()[0]?.slice(4)).toEqual(['App\\Core\\Kernel'])
    await ui.unmount()
  })

  test('the header sums up components, boundaries and languages from the new counts', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'summary' }))?.text).toBe('1,234 components · 2 boundaries · 0 drifted · PHP TS')
      expect((await ui.find({ key: 'health-policy' }))?.text).toMatch(/policy +▲ 2$/)
      expect((await ui.find({ key: 'health-diagnostics' }))?.text).toMatch(/diagnostics +▲ 1$/)
      await ui.unmount()
    }
  })

  test('the boundaries tab draws the heat map as a Raster on the terminal and as glyphs elsewhere', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const term = await mountPane($, 'terminal')
    await term.press({ key: 'tab:boundaries' })
    expect((await term.find({ key: 'bounds-head' }))?.text).toMatch(/^Boundaries +3 · 232 deps$/)
    const grid = await term.find({ type: 'Raster' })
    expect(grid?.key).toBe('raster-heat')
    // The axis letters, one row per boundary and the legend's two rows, as wide as the widest of them.
    expect(grid?.props.rows).toBe(6)
    expect(grid?.props.columns).toBeLessThanOrEqual(PANE_PROPS.bodyColumns)
    expect(typeof grid?.props.cells).toBe('string')
    expect(await term.find({ key: 'heat-1' })).toBeUndefined()
    expect((await term.find({ key: 'bounds-1' }))?.text).toMatch(/^ {3}B Core +[━╸]+·* +30 +14 +3$/)
    await term.unmount()

    const desk = await mountPane($, 'desktop')
    await desk.press({ key: 'tab:boundaries' })
    expect(await desk.find({ type: 'Raster' })).toBeUndefined()
    expect((await desk.find({ key: 'heat-head' }))?.text).toMatch(/^ {3}from→to +A +B +C *$/)
    const core = await desk.find({ key: 'heat-1' })
    expect(core?.text).toMatch(/^ {3}B Core +▒+ +█+ +× +$/)
    // Core reaching into Http is forbidden and crossed: red; the empty forbidden cell is a red cross.
    const crossed = await desk.find({ type: 'Text', text: '▒▒' })
    expect(crossed?.props.color).toBe('error')
    expect((await desk.find({ key: 'heat-legend' }))?.text).toContain('forbidden')
    expect(await desk.find({ key: 'heat-legend-1' })).toBeDefined()
    expect((await desk.find({ key: 'heat-2' }))?.text).toMatch(/^ {3}C cli /)
    // The boundaries are walked, copied and asked about; there is nothing to open.
    expect((await desk.find({ key: 'down' }))?.props.hotkey).toBe('j')
    expect(await desk.find({ key: 'open' })).toBeUndefined()
    await desk.unmount()
  })

  test('the heat map is drawn in the theme colours, and follows a theme picked in /config', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard() }] })
    // Stands in for the config writer beneath the mod: the row takes the value it is given.
    on('config.set', (_$, e) => ({ value: e.value }))
    await $.session.start(START)
    await w.clock.settle()
    /** Every foreground the grid draws with, as 0xRRGGBB. */
    const foregrounds = async (ui: Awaited<ReturnType<typeof mountPane>>) => {
      const cells = (await ui.find({ type: 'Raster' }))?.props.cells as string
      const bytes = Uint8Array.from(atob(cells), c => c.charCodeAt(0))
      const view = new DataView(bytes.buffer)
      const out = new Set<number>()
      for (let i = 4; i < bytes.length; i += 12) out.add(view.getUint32(i, true))
      return out
    }
    const term = await mountPane($, 'terminal')
    await term.press({ key: 'tab:boundaries' })
    // The busiest step is the dark theme's accent, the crossed pair its error colour; no raw palette of the mod's own.
    expect(await foregrounds(term)).toContain(0xb1b9f9)
    expect(await foregrounds(term)).toContain(0xff6b80)
    await $.config.set({ key: 'theme', value: 'light' })
    await w.clock.settle()
    expect(await foregrounds(term)).toContain(0x5769f7)
    expect(await foregrounds(term)).toContain(0xab2b3f)
    expect(await foregrounds(term)).not.toContain(0xb1b9f9)
    await term.unmount()
  })

  test('c copies the marked component on the surface it was pressed on', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: fullDetailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'copy' }))?.props.hotkey).toBe('c')
      await ui.press({ key: 'down' })
      await ui.press({ key: 'copy' })
      await w.clock.settle()
      expect(w.copies.at(-1)).toEqual({ text: 'App\\Kernel', surface })
      expect(w.toasts.at(-1)).toBe('Copied App\\Kernel')
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    // In the detail, the component on show.
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    await ui.press({ key: 'copy' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe('App\\Router')
    expect(w.copies).toHaveLength(3)
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test('Ask Claude submits exactly one prompt, and only on its press', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      // Opening, switching tabs and walking the list never submit anything.
      for (const step of ['tab:hubs', 'down', 'up', 'tab:overview', 'keys', 'keys']) await ui.press({ key: step })
      await edit($, `${ROOT}/src/Router.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
      expect(w.prompts).toHaveLength(surface === 'terminal' ? 0 : 1)
      expect((await ui.find({ key: 'ask' }))?.props.hotkey).toBe('q')
      await ui.press({ key: 'ask' })
      await w.clock.settle()
      expect(w.prompts).toHaveLength(surface === 'terminal' ? 1 : 2)
      // The marker starts on the file the turn touched ("Look at now"): the prompt is about it.
      expect(w.prompts.at(-1)).toBe('Using the Knossos graph, what depends on src/Router.php and what would break if I changed it?')
      await ui.unmount()
    }
  })

  test('allow-root asks first, runs only on the confirming press, then scans the project', async ($, on) => {
    const refused = brief({ status: 'not-allowed', refused_root: ROOT, roots_file: '/data/roots.json', changed_files: [], impact: {} })
    const w = world(on, {
      dashboard: [{ stdout: paneDashboard() }],
      brief: [{ stdout: refused }, { stdout: brief() }],
      allow: [{ stdout: '{"path":"/repo","roots_file":"/data/roots.json","added":true}', hold: 500 }],
    })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['desktop', 'terminal'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'allow-offer-0' }))?.text).toContain('/repo is not an allowed root')
      expect((await ui.find({ key: 'allow' }))?.props.hotkey).toBe('a')
      await ui.press({ key: 'allow' })
      await w.clock.settle()
      // The offer only asks.
      expect(w.allowRuns()).toEqual([])
      expect((await ui.find({ key: 'allow-ask-0' }))?.text).toContain('Allow knossos to scan /repo?')
      expect((await ui.find({ key: 'allow-yes' }))?.props.hotkey).toBe('y')
      await ui.press({ key: 'allow-no' })
      await w.clock.settle()
      expect(w.allowRuns()).toEqual([])
      expect(await ui.find({ key: 'allow-yes' })).toBeUndefined()
      await ui.unmount()
    }
    const ui = await mountPane($)
    const briefs = w.briefRuns().length
    await ui.press({ key: 'allow' })
    await w.clock.settle()
    await ui.press({ key: 'allow-yes' })
    // Nothing runs inside the press.
    expect(w.allowRuns()).toEqual([])
    await w.clock.settle()
    // Running, the question and its answers are gone: there is nothing to press twice.
    expect(await ui.find({ key: 'allow-yes' })).toBeUndefined()
    expect((await ui.find({ key: 'allow-running' }))?.text).toContain('allowing /repo')
    await w.clock.advance(500)
    await w.clock.settle()
    expect(w.allowRuns()).toEqual([['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'allow-root', ROOT]])
    expect((await ui.find({ key: 'allow-done-0' }))?.text).toContain('✓ /repo allowed')
    // The refused edit is scanned now, and the offer is gone.
    expect(w.briefRuns().length).toBe(briefs + 1)
    expect(w.briefRuns().at(-1)).toContain('--files=src/Router.php')
    expect(await ui.find({ key: 'allow' })).toBeUndefined()
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test('an allow-root that does not land says so and can be asked again', async ($, on) => {
    const refused = brief({ status: 'not-allowed', refused_root: ROOT, roots_file: '/data/roots.json' })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], brief: [{ stdout: refused }], allow: [{ stdout: '' }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await mountPane($, 'desktop')
    await ui.press({ key: 'allow' })
    await w.clock.settle()
    await ui.press({ key: 'allow-yes' })
    await w.clock.settle()
    expect((await ui.find({ key: 'allow-offer-0' }))?.text).toContain('allow-root failed: knossos did not allow it')
    expect(w.allowRuns()).toHaveLength(1)
    await ui.press({ key: 'allow' })
    await w.clock.settle()
    expect((await ui.find({ key: 'allow-ask-0' }))?.text).toContain('Allow knossos to scan /repo?')
    expect(w.allowRuns()).toHaveLength(1)
    await ui.unmount()
  })

  test('the pane without data offers to allow a refused root', async ($, on) => {
    const unscanned = JSON.stringify({ ...(JSON.parse(dashboard) as object), status: 'unscanned' })
    const refused = brief({ status: 'not-allowed', refused_root: ROOT, roots_file: '/data/roots.json' })
    const w = world(on, { dashboard: [{ stdout: unscanned }], brief: [{ stdout: refused }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'empty' }))?.text).toContain('knossos scan')
    await ui.press({ key: 'allow' })
    await w.clock.settle()
    expect((await ui.find({ key: 'empty' }))?.text).toMatch(/This adds it to\s+\/data\/roots\.json\./)
    expect(w.allowRuns()).toEqual([])
    await ui.unmount()
  })
  for (const editor of ['opens', 'missing'] as const) {
    test(`a file:line is a link: a click opens it in the editor, or copies it where no editor answers (${editor})`, async ($, on) => {
      const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], editor })
      await $.session.start(START)
      await w.clock.settle()
      for (const surface of ['terminal', 'desktop'] as const) {
        const ui = await mountPane($, surface)
        await ui.press({ key: 'tab:issues' })
        const links = await ui.findAll({ type: 'Markdown' })
        const hrefs = links.map(l => /\]\((file:[^)]*)\)/.exec(String(l.props.text))?.[1])
        // The violation's place, the diagnostic's, the dead code's and the largest file.
        expect(hrefs).toEqual([
          'file:///repo/src/Core/Kernel.php#L12',
          'file:///repo/src/Broken.php#L9',
          'file:///repo/src/Http/Router.php#L40',
          'file:///repo/src/Http/Router.php',
        ])
        const dead = links[2]!
        await ui.press({ key: String(dead.key), link: { href: 'file:///repo/src/Http/Router.php#L40' } })
        await w.clock.settle()
        expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Http/Router.php:40'])
        if (editor === 'opens') {
          expect(w.toasts.at(-1)).toBe('Opened /repo/src/Http/Router.php:40')
        } else {
          expect(w.copies.at(-1)).toEqual({ text: '/repo/src/Http/Router.php:40', surface })
          expect(w.toasts.at(-1)).toBe('No editor command to open it; copied /repo/src/Http/Router.php:40')
        }
        // e opens the marked row's place: the first violation.
        await ui.press({ key: 'edit' })
        await w.clock.settle()
        expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Core/Kernel.php:12'])
        await ui.press({ key: 'tab:overview' })
        await ui.unmount()
      }
      expect(w.prompts).toEqual([])
    })
  }

  test("the detail's place is a link, and e opens it", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: fullDetailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    const place = await ui.find({ type: 'Markdown' })
    expect(place?.props.text).toBe('[src/Http/Router\\.php:3](file:///repo/src/Http/Router.php#L3)')
    await ui.press({ key: 'edit' })
    await w.clock.settle()
    expect(w.editorRuns()).toEqual([['code', '-g', '/repo/src/Http/Router.php:3']])
    await ui.unmount()
  })

  test('the changes tab adds up every turn: files, dependents, boundaries, tests and their command', async ($, on) => {
    const first = brief({
      changed_files: ['src/Router.php'],
      impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http' } },
      tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }],
    })
    const second = brief({
      changed_files: ['src/Router.php'],
      added_files: ['src/Kernel.php'],
      impact: {
        'src/Router.php': { path: 'src/Router.php', dependent_files: 42, boundaries: ['Http'], boundary: 'Http' },
        'src/Kernel.php': { path: 'src/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'Core' },
      },
      tests: [
        { path: 'tests/Http/RouterTest.php', distance: 2 },
        { path: 'tests/Core/KernelTest.php', distance: 1 },
      ],
    })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], brief: [{ stdout: first }, { stdout: second }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const file of ['src/Router.php', 'src/Kernel.php']) {
      await edit($, `${ROOT}/${file}`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'tab:changes' }))?.text).toBe('6²')
      await ui.press({ key: 'tab:changes' })
      const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
      expect(text).toMatch(/Changes this session +2 turns/)
      expect(text).toMatch(/2 files → 45 dependents reaching Http Core/)
      expect(drawn((await ui.find({ key: 'change-0' }))?.text ?? '')).toMatch(/^› +src\/Router\.php +Http .*42$/)
      expect(drawn((await ui.find({ key: 'change-1' }))?.text ?? '')).toMatch(/^ \+ src\/Kernel\.php +Core .*3$/)
      // Each test once, at its nearest distance.
      expect(text).toMatch(/Tests that reach these changes · 2 +hops/)
      expect(drawn((await ui.find({ key: 'test-0' }))?.text ?? '')).toMatch(/tests\/Core\/KernelTest\.php +1$/)
      expect(drawn((await ui.find({ key: 'test-1' }))?.text ?? '')).toMatch(/tests\/Http\/RouterTest\.php +1$/)
      expect(text).toContain("$ vendor/bin/phpunit --filter '(KernelTest|RouterTest)'")
      // `t` copies the test command, as on Overview; `c` copies the marked file, as on every list.
      await ui.press({ key: 'tests' })
      await w.clock.settle()
      expect(w.copies.at(-1)).toEqual({ text: "vendor/bin/phpunit --filter '(KernelTest|RouterTest)'", surface })
      expect(w.toasts.at(-1)).toBe('Copied the command for 2 tests')
      await ui.press({ key: 'copy' })
      await w.clock.settle()
      expect(w.copies.at(-1)).toEqual({ text: 'src/Router.php', surface })
      // `e` opens the marked file in the editor.
      await ui.press({ key: 'down' })
      await ui.press({ key: 'edit' })
      await w.clock.settle()
      expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Kernel.php'])
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    expect(w.detailRuns()).toEqual([])
    expect(w.prompts).toEqual([])
  })

  test('a changed file opens a detail that names who depends on it, from Changes, Last turn and Look at now', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], file: [{ stdout: fileDetailOf('src/Router.php') }], detail: [{ stdout: fullDetailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      // Look at now is row 0, the last turn's file row 1; each opens the same file's detail.
      for (const [tab, key] of [['tab:overview', 'row:0'], ['tab:overview', 'row:1'], ['tab:changes', 'row:0']] as const) {
        await ui.press({ key: tab })
        await ui.press({ key })
        await w.clock.settle()
        const text = drawn((await ui.find({ key: 'detail' }))?.text ?? '')
        expect(text, `${tab} ${key}`).toMatch(/Router\.php +Http/)
        expect(text).toMatch(/Depended on by 14 files +edges/)
        expect(drawn((await ui.find({ key: 'dep-0' }))?.text ?? '')).toMatch(/^› +src\/Core\/Kernel\.php +Core .*6$/)
        expect(text).toMatch(/Declares 2 components/)
        await ui.press({ key: 'back' })
        // Back on the tab, the marker stands where the detail was opened from.
        expect((await ui.find({ key: key === 'row:1' ? 'turn-0' : tab === 'tab:changes' ? 'change-0' : 'look-file' }))?.text).toMatch(/^›/)
      }
      await ui.unmount()
    }
    // Read once per snapshot, by its path under the project root.
    expect(w.fileRuns()).toEqual([['sh', expect.stringMatching(/knossos-run\.sh$/), 'file-detail', ROOT, 'src/Router.php']])
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    // From the detail, a dependent opens as its own file detail, a component as a component's.
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    expect(w.fileRuns().at(-1)?.slice(2)).toEqual(['file-detail', ROOT, 'tests/Http/RouterTest.php'])
    await ui.press({ key: 'back' })
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    await ui.press({ key: 'row:2' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.slice(2)).toEqual(['component-detail', ROOT, 'App\\Router'])
    await ui.unmount()
  })

  test('the drifted files are named where their count stands, and each opens', async ($, on) => {
    const drifted = issuesDashboard({
      freshness: {
        state: 'stale',
        age_seconds: 60,
        drift_files: 2,
        drifted: [
          { path: 'src/Gone.php', change: 'deleted', boundary: 'Core' },
          { path: 'src/Router.php', change: 'changed', boundary: 'Http' },
        ],
        drifted_truncated: false,
      },
    })
    const w = world(on, { dashboard: [{ stdout: drifted }], file: [{ stdout: fileDetailOf('src/Router.php') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'drifted' }))?.props.label).toBe('2 drifted')
      expect(await ui.find({ key: 'drift-head' })).toBeUndefined()
      await ui.press({ key: 'drifted' })
      expect(drawn((await ui.find({ key: 'drift-0' }))?.text ?? '')).toMatch(/^›− src\/Gone\.php +Core$/)
      // A deleted file has nothing for the editor; the changed one does.
      expect(await ui.find({ key: 'edit' })).toBeUndefined()
      await ui.press({ key: 'down' })
      await ui.press({ key: 'edit' })
      await w.clock.settle()
      expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Router.php'])
      await ui.press({ key: 'open' })
      await w.clock.settle()
      expect(drawn((await ui.find({ key: 'detail' }))?.text ?? '')).toMatch(/Depended on by 14 files/)
      await ui.press({ key: 'back' })
      // `d` hides them again; a tab switch does too.
      await ui.press({ key: 'drift' })
      expect(await ui.find({ key: 'drift-head' })).toBeUndefined()
      await ui.press({ key: 'drift' })
      await ui.press({ key: 'tab:hubs' })
      expect(await ui.find({ key: 'drift-head' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('look at now: the marker starts on the riskiest file touched, e opens the marked row and t copies the test command', async ($, on) => {
    const covered = brief({ tests: [{ path: 'hooks/lib/band.spec.ts', distance: 1, js_runner: 'vitest' }] })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], brief: [{ stdout: covered }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'look-head' })).toBeUndefined()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await ui.find({ key: 'look-head' }))?.text).toMatch(/Look at now +this session/)
    expect((await ui.find({ key: 'look-file' }))?.text).toMatch(/^› +Router\.php Http · 41 dependents/)
    expect((await ui.find({ key: 'look-tests' }))?.text).toContain('1 test reaches these changes')
    await ui.press({ key: 'edit' })
    await ui.press({ key: 'tests' })
    await w.clock.settle()
    expect(w.editorRuns()).toEqual([['code', '-g', '/repo/src/Router.php']])
    expect(w.copies.at(-1)?.text).toBe('npx vitest run hooks/lib/band.spec.ts')
    // Past the two file rows the marker is on the hub, which has no file: `e` goes, and opens nothing.
    await ui.press({ key: 'down' })
    await ui.press({ key: 'down' })
    expect(await ui.find({ key: 'edit' })).toBeUndefined()
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.slice(2)).toEqual(['component-detail', ROOT, 'App\\Router'])
    expect(w.editorRuns()).toHaveLength(1)
    await ui.unmount()
  })

  test('look at now warns when no test reaches the changes', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const again = await mountPane($)
    expect((await again.find({ key: 'look-tests' }))?.text).toContain('▲ no test reaches these changes')
    expect(await again.find({ key: 'tests' })).toBeUndefined()
    await again.unmount()
  })
})
