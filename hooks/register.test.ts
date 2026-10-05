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
    /** What `session-changes` answers: the scan ledger's changes since the session began. */
    ledger?: Answer[]
    /** What `session-head` answers (the commit the session begins at), and `session-diff` (a file's change since it). */
    head?: Answer[]
    diff?: Answer[]
    /** What `boundary-couplings` answers: one heat map cell spelled out. */
    couplings?: Answer[]
    /** What `graph-search` (the finder), `branch-diff` (the Branch tab) and `file-context` (the model's tool) answer. */
    search?: Answer[]
    branch?: Answer[]
    context?: Answer[]
    /** What `churn` (the Churn tab), `blast-radius` (a detail's rings), `path-between` (a route) and `annotate` (a note) answer. */
    churn?: Answer[]
    rings?: Answer[]
    route?: Answer[]
    note?: Answer[]
    editor?: 'opens' | 'missing'
    refuseRegister?: () => boolean | Promise<boolean>
    /** Per watcher start, the event lines it writes at once; none left: the start writes nothing and ends (no watcher offered). */
    watch?: object[][]
    /** How long each Bash call runs on the mocked clock (a command that takes a while). */
    bashMs?: number
    /** The prefix the engine gives a registered tool's full name (`mcp__knossos__` otherwise). */
    toolPrefix?: string
    /** What a Bash call prints, by its command (`ran Bash` otherwise). */
    bashOutput?: (command: string) => string
    /**
     * The project's repository: the reflog entries each Bash command adds to
     * HEAD, oldest first (`commit: x`, `checkout: moving from a to b`), each
     * moving HEAD to a new commit. Absent: the project is in no repository.
     * `reflogOff` keeps HEAD moving but prints no reflog.
     */
    git?: (command: string) => string[]
    reflogOff?: boolean
    /** The session's id, and what the plugin's store holds at the start (an earlier process's baselines). */
    sessionId?: string
    store?: Record<string, unknown>
  } = {},
  disk: { root?: string; links?: Record<string, string>; gone?: string[]; garbled?: string[] } = {},
) {
  const clock = mock.clock(on)
  /** The project's repository: HEAD and its reflog, newest first, one commit to begin with. */
  const repo = { made: 1, head: '1'.padStart(40, '0'), reflog: [{ sha: '1'.padStart(40, '0'), subject: 'commit (initial): start' }] }
  // The plugin's own store, in memory, readable by the test: what an earlier process left, and what this one keeps.
  const store = new Map<string, unknown>(Object.entries(answers.store ?? {}))
  on('store.get', (_$, e) => ({ value: structuredClone(store.get(e.key)) }))
  on('store.set', (_$, e) => {
    store.set(e.key, structuredClone(e.value))
    return { value: undefined }
  })
  on('store.delete', (_$, e) => {
    store.delete(e.key)
    return { value: undefined }
  })
  on('store.keys', () => ({ value: [...store.keys()] }))
  let sessionId = answers.sessionId ?? 'session-1'
  on('session.id', () => ({ value: sessionId }))
  /** The process going on under another session id, as after a /clear or a resume. */
  const switchSession = (id: string) => (sessionId = id)
  const links = disk.links ?? {}
  const gone = new Set(disk.gone ?? [])
  const queues = {
    dashboard: answers.dashboard ?? [{ stdout: dashboard }],
    brief: answers.brief ?? [{ stdout: brief() }],
    detail: answers.detail ?? [{ stdout: '' }],
    file: answers.file ?? [{ stdout: '' }],
    scan: answers.scan ?? [{ stdout: '{"status":"ok"}' }],
    allow: answers.allow ?? [{ stdout: '{"path":"/repo","roots_file":"/data/roots.json","added":true}' }],
    ledger: answers.ledger ?? [{ stdout: '' }],
    head: answers.head ?? [{ stdout: '' }],
    diff: answers.diff ?? [{ stdout: '' }],
    couplings: answers.couplings ?? [{ stdout: '' }],
    search: answers.search ?? [{ stdout: '' }],
    branch: answers.branch ?? [{ stdout: '' }],
    context: answers.context ?? [{ stdout: '' }],
    churn: answers.churn ?? [{ stdout: '' }],
    rings: answers.rings ?? [{ stdout: '' }],
    route: answers.route ?? [{ stdout: '' }],
    note: answers.note ?? [{ stdout: '' }],
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
  /** The tools the mod registered for the model. */
  const tools: string[] = []
  on('tool.register', (_$, e) => {
    tools.push(e.name)
    return { value: { tool: `${answers.toolPrefix ?? 'mcp__knossos__'}${e.name}` } }
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
  /**
   * The live watcher's child: each start writes its scripted lines, then
   * whatever `send` queues, sleeping on the mocked clock between, until
   * `stop` ends it or the mod leaves its loop (`ended` counts the ends).
   */
  const watcher = { starts: [] as string[][], ended: 0, queue: [] as string[], stopped: false }
  on('process.spawn', async function* (_$, e) {
    watcher.starts.push([...e.argv])
    const script = answers.watch?.shift()
    if (script === undefined) return { value: { code: 0, signal: null } }
    watcher.stopped = false
    try {
      for (const line of script) yield { stream: 'stdout' as const, text: `${JSON.stringify(line)}\n` }
      // A watcher that refused or stopped exits after saying so.
      const last = (script.at(-1) as { event?: string } | undefined)?.event
      watcher.stopped = last === 'refused' || last === 'stopped'
      // As the real one, it says it is still there every 15 s even when nothing changes.
      let quiet = 0
      while (!watcher.stopped) {
        while (watcher.queue.length > 0) yield { stream: 'stdout' as const, text: watcher.queue.shift()! }
        await clock.sleep(50)
        quiet += 50
        if (quiet >= 15_000) {
          quiet = 0
          yield { stream: 'stdout' as const, text: '{"event":"heartbeat"}\n' }
        }
      }
    } finally {
      watcher.ended++
    }
    return { value: { code: 0, signal: null } }
  })
  on('session.end', (_$, e) => ({ sessionId: e.sessionId }) as never)
  const watchSend = (event: object) => watcher.queue.push(`${JSON.stringify(event)}\n`)
  const watchStop = () => {
    watcher.stopped = true
  }
  on('process.run', async (_$, e) => {
    calls.push([...e.argv])
    // The project's repository, as `git rev-parse` and `git reflog` read it.
    if (e.argv[0] === 'git') {
      const done = (exitCode: number, stdout: string) => ({ value: { exitCode, stdout, stderr: '', isStdoutTruncated: false, isStderrTruncated: false } })
      if (answers.git === undefined) return done(128, '')
      if (e.argv.includes('rev-parse')) return done(0, `${repo.head}\n`)
      if (e.argv.includes('reflog')) return done(0, answers.reflogOff === true ? '' : repo.reflog.slice(0, 20).map(r => `${r.sha}\x1f${r.subject}\n`).join(''))
      return done(1, '')
    }
    // A signal to a process the mod started: delivered.
    if (e.argv[0] === 'kill') return { value: { exitCode: 0, stdout: '', stderr: '', isStdoutTruncated: false, isStderrTruncated: false } }
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
                : sub === 'session-changes'
                  ? queues.ledger
                  : sub === 'session-head'
                    ? queues.head
                    : sub === 'session-diff'
                      ? queues.diff
                      : sub === 'boundary-couplings'
                        ? queues.couplings
                        : sub === 'graph-search'
                          ? queues.search
                          : sub === 'branch-diff'
                            ? queues.branch
                            : sub === 'file-context'
                              ? queues.context
                              : sub === 'churn'
                                ? queues.churn
                                : sub === 'blast-radius'
                                  ? queues.rings
                                  : sub === 'path-between'
                                    ? queues.route
                                    : sub === 'annotate'
                                      ? queues.note
                                      : queues.brief
    const answer = (queue.length > 1 ? queue.shift() : queue[0]) ?? { stdout: '' }
    if (answer.hold !== undefined) await clock.sleep(answer.hold)
    return {
      value: { exitCode: 0, stdout: answer.stdout, stderr: '', isStdoutTruncated: false, isStderrTruncated: false },
    }
  })
  // The engine's own band: an empty row, which no hook of the mod's draws.
  on('ui.render', () => ({ type: 'Box', props: { key: 'engine' }, children: [] }))
  on('tool.call', async (_$, e) => {
    if (e.tool === 'Bash' && answers.bashMs !== undefined) await clock.sleep(answers.bashMs)
    const command = (e as { command?: unknown }).command
    if (e.tool === 'Bash' && typeof command === 'string') {
      for (const subject of answers.git?.(command) ?? []) {
        repo.head = (++repo.made).toString(16).padStart(40, '0')
        repo.reflog.unshift({ sha: repo.head, subject })
      }
    }
    return { result: {} as never, text: e.tool === 'Bash' && answers.bashOutput !== undefined && typeof command === 'string' ? answers.bashOutput(command) : `ran ${e.tool}` }
  })
  const briefRuns = () => calls.filter(c => c[2] === 'turn-brief')
  const detailRuns = () => calls.filter(c => c[2] === 'component-detail')
  const fileRuns = () => calls.filter(c => c[2] === 'file-detail')
  const scanRuns = () => calls.filter(c => c[2] === 'scan')
  const dashboardRuns = () => calls.filter(c => c[2] === 'dashboard')
  const allowRuns = () => calls.filter(c => c[2] === 'allow-root')
  const editorRuns = () => calls.filter(c => c[0] === 'code')
  const kills = () => calls.filter(c => c[0] === 'kill')
  const ledgerRuns = () => calls.filter(c => c[2] === 'session-changes')
  const headRuns = () => calls.filter(c => c[2] === 'session-head')
  const diffRuns = () => calls.filter(c => c[2] === 'session-diff')
  const couplingRuns = () => calls.filter(c => c[2] === 'boundary-couplings')
  const searchRuns = () => calls.filter(c => c[2] === 'graph-search')
  const branchRuns = () => calls.filter(c => c[2] === 'branch-diff')
  const contextRuns = () => calls.filter(c => c[2] === 'file-context')
  const churnRuns = () => calls.filter(c => c[2] === 'churn')
  const ringRuns = () => calls.filter(c => c[2] === 'blast-radius')
  const routeRuns = () => calls.filter(c => c[2] === 'path-between')
  const noteRuns = () => calls.filter(c => c[2] === 'annotate')
  return { churnRuns, ringRuns, routeRuns, noteRuns, tools, searchRuns, branchRuns, contextRuns, couplingRuns, store, switchSession, headRuns, diffRuns, ledgerRuns, kills, watcher, watchSend, watchStop, registered, clock, calls, briefRuns, detailRuns, fileRuns, scanRuns, dashboardRuns, allowRuns, editorRuns, toasts, logs, opened, closed, invalidations, prompts, copies, focuses }
}

const START = { cwd: ROOT, surface: 'terminal', isInteractive: true } as const
const TURN = { answer: '', durationMs: 1, isAborted: false, turnId: 't', reason: 'answer' } as const

async function edit($: Engine, path: string) {
  return $.tool.call({ tool: 'Edit', file_path: path, old_string: 'a', new_string: 'b' })
}

async function readFile($: Engine, path: string) {
  return $.tool.call({ tool: 'Read', file_path: path })
}

/** What a shell prints for a command that commits (git's `[branch sha] subject` line), and for any other. */
const committing = (command: string): string => (/\bgit\b.*\bcommit\b/.test(command) && !command.includes('--dry-run') && !command.startsWith('echo') && !command.startsWith('grep') ? '[main abc1234] x\n 1 file changed, 1 insertion(+)' : 'done')
/** The reflog a `git commit` adds to the project's repository: one commit, none for a dry run or a mere mention. */
const committed = (command: string): string[] => (committing(command) === 'done' ? [] : [command.includes('--amend') ? 'commit (amend): x' : 'commit: x'])

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
    complexity_hotspots: [{ path: 'src/Http/Router.php', language: 'php', lines: 900, dependent_files: 12, score: 10_800 }],
    over_budget: { source: 'maintainability-budgets.json', max_function_lines: 205, total: 0, files: [] },
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

/** A card's text without its frame and the glyph before its title: the rule's dashes, corners and sides as single spaces. */
function titled(text: string | undefined): string {
  return (text ?? '').replace(/[╭╮╰╯│]/g, ' ').replace(/─+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^[◆▤◎≡▦▥◈⇄↻!±✓∿◇←→✎Δ] /u, '')
}

const PANE_PROPS = {
  title: 'Knossos',
  isFocused: false,
  bodyColumns: 60,
  placement: 'dock',
  scroll: { offset: 0, bodyRows: 30 },
  view: {},
} as RenderPropsOf['Pane']

/** The element keyed `key` in a drawn tree, read raw: where its `hover` stands beside its props. */
function nodeOf(tree: unknown, key: string): { props?: Record<string, unknown>; hover?: unknown } | undefined {
  const node = tree as { props?: { key?: unknown }; children?: unknown[] } | null
  if (typeof node !== 'object' || node === null) return undefined
  if (node.props?.key === key) return node as { props?: Record<string, unknown>; hover?: unknown }
  for (const child of node.children ?? []) {
    const found = nodeOf(child, key)
    if (found !== undefined) return found
  }
  return undefined
}

/** What the footer says after an action, while it lasts: its `✓` or `✗` line; undefined when it says nothing. */
async function said(ui: Awaited<ReturnType<typeof mountPane>>): Promise<string | undefined> {
  // The footer's word says what happened; a card's verdict (`✓ 0`) is a figure.
  return (await ui.findAll({ type: 'Text' })).map(t => t.text).find(t => /^[✓✗] [^\d\s]/.test(t))
}

/** The narrow pane's stat tiles as one line of text: every row of the wrapped line, joined. */
async function tilesLine(ui: Awaited<ReturnType<typeof mountPane>>): Promise<string> {
  const rows = (await ui.findAll({ type: 'Box' })).filter(b => typeof b.key === 'string' && /^tiles-line(-\d+)?$/.test(b.key))
  return rows.map(b => b.text.trim()).join('   ')
}

async function mountPane($: Engine, surface: 'terminal' | 'desktop' = 'terminal', bodyColumns?: number) {
  return $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: bodyColumns === undefined ? PANE_PROPS : { ...PANE_PROPS, bodyColumns } })
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

type DrawnNode = { type?: string; props?: Record<string, unknown>; children?: unknown[] }

/** A drawn node's text: its string children, and its descendants', in order. */
function nodeText(node: DrawnNode): string {
  return (node.children ?? []).map(c => (typeof c === 'string' || typeof c === 'number' ? String(c) : typeof c === 'object' && c !== null ? nodeText(c as DrawnNode) : '')).join('')
}

/**
 * Everything in a drawn tree that could wrap or push its container wider
 * than `columns`, as lines naming where: a Text that does not cut at its
 * edge, or that stands in no Box of a set width, or holds more cells than
 * that Box (a row, or the Box a Button stands in); on the terminal a Button whose label (with its `k: `) is wider
 * than its Box; a Box wider than the one it stands in, or placed past its
 * right edge; a row whose fixed-width children add up past its own width,
 * or that does not clip; a Raster wider than its Box. Measured from the
 * tree the surface is handed, so it holds whatever a preview draws.
 */
function overflowsOf(tree: unknown, columns: number, terminal: boolean): string[] {
  const out: string[] = []
  const walk = (node: unknown, box: number | undefined, path: string) => {
    if (typeof node !== 'object' || node === null) return
    const n = node as DrawnNode
    const props = n.props ?? {}
    const at = `${path}/${String(props.key ?? n.type)}`
    if (props.display === 'none') return
    if (n.type === 'Text') {
      const width = [...nodeText(n)].length
      if (props.wrap !== 'truncate-end') out.push(`${at}: a Text that wraps`)
      if (box === undefined) out.push(`${at}: a Text in no Box of a set width`)
      else if (width > box) out.push(`${at}: "${nodeText(n)}" is ${width} cells in a Box of ${box}`)
      return
    }
    if (n.type === 'Button') {
      const width = [...String(props.label ?? '')].length + (props.hotkey === undefined ? 0 : 3)
      if (terminal && (box === undefined || width > box)) out.push(`${at}: a Button ${width} cells in a Box of ${String(box)}`)
      return
    }
    if (n.type === 'Raster' && typeof props.columns === 'number' && box !== undefined && props.columns > box) out.push(`${at}: a Raster ${props.columns} wide in ${box}`)
    let inner = box
    if (n.type === 'Box' && typeof props.width === 'number') {
      const width = props.width
      const left = typeof props.left === 'number' ? props.left : 0
      if (box !== undefined && (props.position === 'absolute' ? left + width : width) > box) out.push(`${at}: a Box ${width} wide (at ${left}) in ${box}`)
      if (props.flexDirection === 'row') {
        if (props.overflow !== 'hidden') out.push(`${at}: a row that does not clip`)
        // What its children take: a sized Box its width, a Text its cells (it cuts at the row's edge, never wraps).
        const taken = (c: unknown): number => {
          if (typeof c !== 'object' || c === null) return 0
          const child = c as DrawnNode
          if (child.props?.display === 'none' || child.props?.position === 'absolute') return 0
          if (child.type === 'Text') return [...nodeText(child)].length
          // A Button draws its label (and `k: ` before a hotkey); the surface's own elsewhere, so measured on the terminal only.
          if (child.type === 'Button') return terminal ? [...String(child.props?.label ?? '')].length + (child.props?.hotkey === undefined ? 0 : 3) : 0
          if (child.type === 'Markdown') return [...String(child.props?.text ?? '').replace(/\[((?:\\.|[^\]\\])*)\]\(file:[^)]*\)/g, (_m: string, l: string) => l.replace(/\\(.)/g, '$1'))].length
          return typeof child.props?.width === 'number' ? child.props.width : 0
        }
        const fixed = (n.children ?? []).reduce((sum: number, c) => sum + taken(c), 0)
        if (fixed > width) out.push(`${at}: its segments take ${fixed} cells of ${width}`)
      }
      inner = width
    }
    for (const child of n.children ?? []) walk(child, inner, at)
  }
  walk(tree, columns, '')
  return out
}

/** How big a drawn tree is, as the engine bounds every tree: its nodes, its depth and its length serialized. */
function treeSize(tree: unknown): { nodes: number; depth: number; chars: number } {
  let nodes = 0
  const deepest = (node: unknown, depth: number): number => {
    if (typeof node !== 'object' || node === null) return depth - 1
    nodes++
    return Math.max(depth, ...((node as DrawnNode).children ?? []).map(c => deepest(c, depth + 1)))
  }
  const depth = deepest(tree, 1)
  return { nodes, depth, chars: JSON.stringify(tree).length }
}

describe('knossos mod', () => {
  test('every view stays well within the bounds the engine sets every tree (20,000 nodes, 32 deep, 100,000 characters), with the largest lists the dashboard sends', { timeoutMs: 120_000 }, async ($, on) => {
    const name = (i: number) => `ProjectModuleIndexWithAVeryLongName${i}::_is_python_script_with_a_shebang`
    const path = (i: number) => `src/a/rather/deeply/nested/directory/with/an/UnreasonablyLongControllerName${i}.php`
    const bounds = ['Http', 'Core', 'module:cli (+composer:app/cli)', 'tests', 'tooling', 'hooks', 'types', 'php-worker', 'rust-worker', 'python-worker', 'typescript-worker', 'composer:knossos']
    const ranked = (i: number) => ({ name: name(i), canonical_name: `App\\Core\\${name(i)}`, kind: 'method', boundary: bounds[i % 12], in_degree: 900 - i, out_degree: i, cross_boundary_degree: i % 7, dependent_files: 400 - i, path: path(i), line: i + 1, top_dependents: [path(i + 1), path(i + 2), path(i + 3)] })
    const nodes = Array.from({ length: 40 }, (_, i) => ({ name: name(i), canonical_name: `App\\${name(i)}`, kind: 'method', boundary: bounds[i % 12] }))
    const big = boundariesDashboard({
      hubs: Array.from({ length: 50 }, (_, i) => ranked(i)),
      hotspots: Array.from({ length: 50 }, (_, i) => ({ ...ranked(i + 50), score: 99 })),
      fan_in: Array.from({ length: 50 }, (_, i) => ({ path: path(i), dependent_files: 500 - i, boundaries: bounds.slice(0, 3), boundary: bounds[i % 12], top_dependents: [path(i + 1), path(i + 2), path(i + 3)] })),
      dead_code: Array.from({ length: 50 }, (_, i) => ({ name: name(i), canonical_name: `App\\${name(i)}`, kind: 'method', boundary: bounds[i % 12], reachability: 'unreferenced', confidence: 'possible', path: path(i), line: i + 1 })),
      dead_code_candidates: 5_000,
      complexity_hotspots: Array.from({ length: 30 }, (_, i) => ({ path: path(i), language: 'php', lines: 9_000 - i, dependent_files: 400 - i, score: (9_000 - i) * (400 - i) })),
      over_budget: { source: 'maintainability-budgets.json', max_function_lines: 205, total: 900, files: Array.from({ length: 30 }, (_, i) => ({ path: path(i + 30), functions: 9 - (i % 9), longest: 2_000 - i, line: i + 1 })) },
      cycles: { count: 50, truncated: true, truncation_reasons: [], largest: Array.from({ length: 10 }, (_, c) => ({ size: 40, members: nodes.map(n => n.name), nodes, nodes_truncated: c === 0 })) },
      trend: Array.from({ length: 20 }, (_, i) => ({ snapshot_id: `s${i}`, cycles: i % 4, max_degree: 900 + i, dead_code: 5_000 - i, diagnostics: i % 3, components: 99_000 + i })),
      deltas: { against: 's18', components: 12, cycles: 1, max_degree: -3, dead_code: 9, diagnostics: 0 },
      in_degree: { buckets: [{ from: 0, to: 0, components: 9_000 }, { from: 1, to: 5, components: 20_000 }, { from: 6, to: 20, components: 4_000 }, { from: 21, to: 100, components: 1_800 }, { from: 101, to: null, components: 260 }], truncated: false },
      boundary_matrix: {
        boundaries: bounds,
        members: bounds.map((_, i) => 9_000 - i * 500),
        labelled: 99_000,
        boundaries_truncated: true,
        cells: bounds.map((_, f) => bounds.map((_, t) => (f === t ? 9_000 : (f * 7 + t * 3) % 50))),
        forbidden: [[1, 0], [1, 2]],
        flows: Array.from({ length: 8 }, (_, i) => ({ from: i, to: (i + 1) % 12, edges: 9_000 - i, forbidden: i === 1 })),
        edges: 400_000,
        truncated: false,
        truncation_reasons: [],
      },
    })
    const touched = brief({ changed_files: Array.from({ length: 30 }, (_, i) => path(i)), impact: Object.fromEntries(Array.from({ length: 30 }, (_, i) => [path(i), { path: path(i), dependent_files: 300 - i, boundaries: bounds.slice(0, 4), boundary: bounds[i % 12] }])), tests: Array.from({ length: 30 }, (_, i) => ({ path: `tests/${name(i)}Test.php`, distance: 1 })) })
    const placed = (i: number) => ({ name: name(i), canonical_name: `App\\${name(i)}`, kind: 'method', path: path(i), line: i + 1, boundary: bounds[i % 12] })
    const branched = JSON.stringify({
      status: 'no-snapshot',
      branch: 'feat/a-branch-with-a-very-long-name-indeed',
      default_branch: 'origin/main',
      merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
      ahead: 1_234,
      base: { snapshot_id: 's0', rev: '4054d3a2fc5346529576462db547efe648538fb0', at: '2026-10-04T11:05:02Z', match: 'after', commits: 1_000 },
      comparison: {
        crossing: { count: 5_000, items: Array.from({ length: 8 }, (_, i) => ({ source: placed(i), target: placed(i + 8) })) },
        cycles: { count: 50, items: Array.from({ length: 8 }, (_, i) => ({ size: 40, members: Array.from({ length: 8 }, (_, j) => placed(i * 8 + j)) })) },
        hubs: { count: 900, items: Array.from({ length: 8 }, (_, i) => ({ component: placed(i), before: 100 + i, after: 9_000 + i })) },
        dead_code: { count: 5_000, items: Array.from({ length: 8 }, (_, i) => placed(i + 20)) },
        violations: { count: 100, truncated: true, items: Array.from({ length: 8 }, (_, i) => ({ policy_id: 'a-policy-with-a-long-id', source: `App\\${name(i)}`, source_kind: 'method', target: `App\\${name(i + 1)}`, target_kind: 'method' })) },
      },
    })
    const found = JSON.stringify({ status: 'ok', query: 'x', truncated: true, results: Array.from({ length: 20 }, (_, i) => ({ type: i % 2 === 0 ? 'component' : 'file', ...placed(i), ...(i % 2 === 0 ? {} : { name: path(i), canonical_name: path(i), kind: 'file', line: null }) })) })
    const churned = JSON.stringify({ status: 'ok', days: 30, head: 'a'.repeat(40), commits: 500, truncated: true, files: Array.from({ length: 40 }, (_, i) => ({ path: path(i), commits: 90 - i, dependents: 900 - i * 20, score: (90 - i) * (900 - i * 20), boundary: bounds[i % 12] })) })
    const ringed = JSON.stringify({
      status: 'ok',
      component: { ...placed(0), kind: 'class' },
      truncated: true,
      rings: [1, 2, 3].map(hop => ({ hop, count: 1_500, tested: 700, items: Array.from({ length: 12 }, (_, i) => ({ ...placed(i + hop * 12), tested: i % 2 === 0 })), tests: { count: 400, items: Array.from({ length: 12 }, (_, i) => ({ path: `tests/${name(i)}Test.php`, hop })) } })),
    })
    const routed = JSON.stringify({ status: 'ok', from: placed(0), to: placed(7), reversed: true, truncated: true, routes: Array.from({ length: 5 }, (_, r) => ({ nodes: Array.from({ length: 7 }, (_, i) => placed(i + r)), hops: Array.from({ length: 6 }, (_, i) => ({ kind: 'dispatches', confidence: 'possible', path: path(i), line: i + 1 })) })) })
    const w = world(on, { dashboard: [{ stdout: big }], brief: [{ stdout: touched }], detail: [{ stdout: fullDetailOf('Router') }], file: [{ stdout: fileDetailOf(path(0)) }], branch: [{ stdout: branched }], search: [{ stdout: found }], churn: [{ stdout: churned }], rings: [{ stdout: ringed }], route: [{ stdout: routed }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/${path(0)}`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      for (const [bodyColumns, bodyRows] of [[200, 60], [140, 120], [100, 60], [60, 60]] as const) {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns, scroll: { offset: 0, bodyRows } } })
        // Every tab, the key list, a detail with its rings and a note being typed, a route picked and drawn, the finder.
        for (const step of ['tab:overview', 'tab:hubs', 'tab:boundaries', 'tab:cycles', 'tab:issues', 'tab:changes', 'tab:branch', 'tab:churn', 'keys', 'tab:hubs', 'row:0', 'note', 'route', 'typed', 'row:0', 'back', 'back', 'row:50', 'find', 'typed', 'find-close']) {
          if (step === 'typed') {
            await ui.input({ key: 'field:find', text: 'x', kind: 'change' })
            await w.clock.advance(200)
          } else {
            // A press only where its Button is drawn: a note still being typed from the last size draws its field instead.
            if ((await ui.find({ type: 'Button', key: step })) === undefined) continue
            await ui.press({ key: step })
          }
          await w.clock.settle()
          const size = treeSize(await ui.drawn())
          const at = `${surface} ${bodyColumns}x${bodyRows} after ${step}: ${JSON.stringify(size)}`
          expect(size.nodes, at).toBeGreaterThan(20)
          expect(size.nodes, at).toBeLessThan(10_000)
          expect(size.depth, at).toBeLessThan(16)
          expect(size.chars, at).toBeLessThan(72_000)
        }
        await ui.unmount()
      }
    }
  })

  test('no line can wrap or widen its container: every view at every width, on terminal and desktop, measured from the drawn tree', async ($, on) => {
    const long = 'ProjectModuleIndexWithAnUnreasonablyLongName::_is_python_script_with_a_shebang_line'
    const nodes = Array.from({ length: 14 }, (_, i) => ({ name: `${long}${i}`, canonical_name: `App\\Core\\${long}${i}`, kind: 'method', boundary: i % 2 === 0 ? 'Core' : 'module:cli (+composer:app/cli)' }))
    const path = 'src/a/rather/deeply/nested/directory/with/an/UnreasonablyLongControllerName.php'
    const hubs = nodes.slice(0, 6).map((n, i) => ({ ...n, in_degree: 400 - i, out_degree: 120_000 + i, cross_boundary_degree: 9_999, dependent_files: 54_321, top_dependents: [path, path] }))
    const d = boundariesDashboard({
      project_root: '/work/a-project-with-a-rather-long-directory-name-that-keeps-going',
      freshness: { state: 'stale', age_seconds: 400_000, drift_files: 41 },
      hubs,
      hotspots: [],
      fan_in: [{ path, dependent_files: 123_456, boundaries: ['Core'], boundary: 'module:cli (+composer:app/cli)', top_dependents: [path] }],
      cycles: { count: 2, truncated: true, truncation_reasons: ['result_limit'], largest: [{ size: 14, members: nodes.map(n => n.name), nodes, nodes_truncated: false }, { size: 2, members: [long, long], nodes: nodes.slice(0, 2), nodes_truncated: false }] },
      trend: Array.from({ length: 8 }, (_, i) => ({ snapshot_id: `s${i}`, cycles: i % 3, max_degree: 100 + i, dead_code: 90_000 + i, diagnostics: i, components: 1_234_567 + i })),
      deltas: { against: 's6', components: 123_456, cycles: -1, max_degree: 12_345, dead_code: 99_999, diagnostics: 0 },
      in_degree: { buckets: [{ from: 0, to: 0, components: 1_234_567 }, { from: 1, to: 99_999, components: 3 }, { from: 100_000, to: null, components: 1 }], truncated: true },
    })
    const matrix = (JSON.parse(d) as { boundary_matrix: Record<string, unknown> }).boundary_matrix
    const withFlows = JSON.stringify({ ...(JSON.parse(d) as object), boundary_matrix: { ...matrix, flows: [{ from: 2, to: 0, edges: 1_234_567, forbidden: true }, { from: 0, to: 2, edges: 3, forbidden: false }] } })
    const detail = JSON.parse(fullDetailOf('Router')) as { component: Record<string, unknown> }
    detail.component.display_name = long
    detail.component.used_by = { count: 9, truncated: false, names: [], items: nodes.slice(0, 9).map((n, i) => ({ ...n, edges: 1000 + i })) }
    const file = JSON.parse(fileDetailOf(path)) as { file: { dependents: { items: unknown[] } } }
    file.file.dependents.items = [{ path, edges: 99_999, boundary: 'module:cli (+composer:app/cli)' }]
    const touched = brief({ changed_files: [path], impact: { [path]: { path, dependent_files: 123_456, boundaries: ['Core', 'Http'], boundary: 'Core' } } })
    const placed = (n: (typeof nodes)[number]) => ({ ...n, path, line: 123_456 })
    const branched = JSON.stringify({
      status: 'ok',
      branch: `feat/${long}`,
      default_branch: 'origin/main',
      merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
      ahead: 123_456,
      base: { snapshot_id: 's0', rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: '2026-10-02T15:01:06Z', match: 'before', commits: 99_999 },
      comparison: {
        crossing: { count: 123_456, items: nodes.slice(0, 3).map((n, i) => ({ source: placed(n), target: placed(nodes[i + 1]!) })) },
        cycles: { count: 2, items: [{ size: 14, members: nodes.slice(0, 8).map(placed) }] },
        hubs: { count: 3, items: nodes.slice(0, 3).map((n, i) => ({ component: placed(n), before: 1_000 + i, after: 1_234_567 + i })) },
        dead_code: { count: 99_999, items: nodes.slice(0, 3).map(placed) },
        violations: { count: 7, truncated: true, items: [{ policy_id: long, source: nodes[0]!.canonical_name, source_kind: 'method', target: nodes[1]!.canonical_name, target_kind: 'method' }] },
      },
    })
    const w = world(on, { dashboard: [{ stdout: withFlows }], detail: [{ stdout: JSON.stringify(detail) }], file: [{ stdout: JSON.stringify(file) }], brief: [{ stdout: touched }], branch: [{ stdout: branched }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/${path}`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const terminal = surface === 'terminal'
      for (const bodyColumns of [40, 60, 100, 140, 200]) {
        for (const bodyRows of [24, 60]) {
          const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns, scroll: { offset: 0, bodyRows } } })
          for (const step of ['tab:overview', 'row:3', 'tab:overview', 'tab:hubs', 'tab:boundaries', 'tab:cycles', 'next:1', 'tab:issues', 'tab:changes', 'tab:branch', 'keys', 'tab:hubs', 'row:0', 'back', `row:${hubs.length}`, 'find', 'find-close']) {
            if ((await ui.find({ key: step })) === undefined) continue
            await ui.press({ key: step })
            await w.clock.settle()
            expect(overflowsOf(await ui.drawn(), bodyColumns, terminal), `${surface} ${bodyColumns}x${bodyRows} after ${step}`).toEqual([])
          }
          await ui.unmount()
        }
      }
      for (const bodyColumns of [40, 100]) {
        const band = await $.ui.mount({ plugin: 'knossos', surface, component: 'AbovePrompt', props: { ...BAND_PROPS, bodyColumns } })
        expect(overflowsOf(await band.drawn(), bodyColumns, false), `band ${surface} ${bodyColumns}`).toEqual([])
        await band.unmount()
      }
    }
  })

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
    expect(w.dashboardRuns()[0]).toContain('--fan-in-threshold=50')
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
    expect(await bandText($)).toContain('not allowed: repo')
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
    expect(w.dashboardRuns()[0]).toContain('--fan-in-threshold=20')
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

  test('knossos_context says what to do instead when there is no binary, and which root to allow when it is refused', async ($, on) => {
    const ask = async () => String(((await $.tool.call({ tool: 'mcp__knossos__knossos_context', path: 'src/Router.php' })) as { result?: unknown }).result)
    const w = world(on, { dashboard: [{ stdout: '{"status":"unscanned"}' }], brief: [{ stdout: brief({ status: 'not-allowed', refused_root: ROOT, roots_file: '/data/roots.json', changed_files: [], impact: {} }) }, { stdout: '{"status":"no-binary"}' }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await ask()).toBe(`knossos_context: knossos may not scan ${ROOT}: it is not an allowed root. Ask the person to allow it (the knossos band offers the command), then call this again.`)
    // The binary gone: no scan would help.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await ask()).toContain('no knossos binary was found')
  })

  test('knossos_context answers under the name its registration returned', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: '{"status":"unscanned"}' }], toolPrefix: 'mcp__plugin_knossos_knossos__' })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.tools).toEqual(['knossos_context'])
    const answered = await $.tool.call({ tool: 'mcp__plugin_knossos_knossos__knossos_context', path: 'src/Router.php' } as never)
    expect(String((answered as { result?: unknown }).result)).toContain('knossos_context: knossos has no graph of this project yet')
    // The name the mod would have guessed is not the tool's: the call goes on to whatever answers it.
    const guessed = await $.tool.call({ tool: 'mcp__knossos__knossos_context', path: 'src/Router.php' } as never)
    expect((guessed as { text?: string }).text).toBe('ran mcp__knossos__knossos_context')
  })

  test('a search typed just before the binary goes missing never runs', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], brief: [{ stdout: '{"status":"no-binary"}' }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($, 'terminal', 120)
    await ui.press({ key: 'find' })
    await w.clock.settle()
    await ui.input({ key: 'field:find', text: 'dsvc', kind: 'change' })
    // Before the pause ends, a turn's brief finds no binary: the mod turns off.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.advance(10)
    expect(w.logs.some(l => l.text.includes('no knossos binary found'))).toBe(true)
    await w.clock.advance(1_000)
    await w.clock.settle()
    expect(w.searchRuns()).toEqual([])
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
    const w = world(on, { dashboard: [{ stdout: '' }, { stdout: '' }, { stdout: '' }, { stdout: dashboard }] })
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
    // Asked again after each turn while there is none: the second turn's end finds it.
    expect(w.calls.filter(c => c[2] === 'dashboard').length).toBeGreaterThanOrEqual(3)
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
    await ui.press({ key: 'tab:hubs' })
    expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Router')
    expect((await ui.find({ key: 'title' }))?.text).toContain('● refresh failed 1s')
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
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh 1s $/)
    await w.clock.advance(5_000)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh 6s $/)
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
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● refresh failed 11s $/)
    await ui.press({ key: 'tab:hubs' })
    expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Router')
    await ui.unmount()
  })

  test('a walk cut short marks hubs and hotspots partial', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs_truncated: true, hubs_truncation_reasons: ['time_limit'] }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
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
    expect(w.dashboardRuns()[0]).toContain('--fan-in-threshold=20')
  })

  test('a threshold above the command limit falls back to 20', { options: { fanInThreshold: 100_001 } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    expect(w.dashboardRuns()[0]).toContain('--fan-in-threshold=20')
  })

  test('the allow-root hint names the roots file and the refused root', async ($, on) => {
    const refused = brief({ status: 'not-allowed', path: `${ROOT}/src`, roots_file: '/data/roots.json', refused_root: ROOT })
    const w = world(on, { brief: [{ stdout: refused }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    // The band names the root; the command, too long for a band, is copied whole.
    expect(await bandText($)).toContain('not allowed: repo')
    const band = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'AbovePrompt', props: { ...BAND_PROPS, bodyColumns: 50 } })
    expect(drawn((await band.find({ key: 'band' }))?.text ?? '')).not.toContain('allow-root')
    await band.press({ key: 'copy' })
    expect(w.copies.map(c => c.text)).toEqual([`KNOSSOS_ROOTS_FILE='/data/roots.json' knossos allow-root '${ROOT}' --execute`])
    await band.unmount()
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

  test('the overview shows the figures and lists nothing, on both surfaces; the hubs are on Hubs', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      expect((await ui.find({ key: 'title' }))?.text).toMatch(/^ repo +● fresh 1s $/)
      // The header is two rows, the title and the tabs: no summary line under them.
      expect(await ui.find({ key: 'summary' })).toBeUndefined()
      // Narrow, the stat tiles are a line of figures under the tabs.
      expect(await tilesLine(ui)).toMatch(/^1 cycle +12 max degree +4 dead code +0 drifted$/)
      expect(await ui.find({ key: 'top-0' })).toBeUndefined()
      expect(titled((await ui.find({ key: 'session-head' }))?.text)).toMatch(/^This session +nothing changed yet$/)
      await ui.press({ key: 'tab:hubs' })
      // The marked row is tinted to the card's edge: the spaces that carry the tint end it.
      expect((await ui.find({ key: 'hub-0' }))?.text).toMatch(/^› +Router .*41/)
      // A hotspot that is not a hub is listed once, marked.
      expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/◆ Kernel/)
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('an Overview bucket opens Hubs narrowed to it, x clears it; a flow opens its cell on Boundaries; the way to Changes opens Changes', async ($, on) => {
    const in_degree = { buckets: [{ from: 0, to: 0, components: 30 }, { from: 1, to: 40, components: 12 }, { from: 41, to: null, components: 2 }], truncated: false }
    const d = JSON.parse(boundariesDashboard({ in_degree })) as { boundary_matrix: Record<string, unknown> }
    d.boundary_matrix.flows = [{ from: 1, to: 0, edges: 3, forbidden: true }]
    const w = world(on, { dashboard: [{ stdout: JSON.stringify(d) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100, scroll: { offset: 0, bodyRows: 60 } } })
      // The top bucket is the hubs: Router has 41 dependents.
      expect((await ui.find({ key: 'row:2' }))?.props.label).toBe('41+')
      expect((await ui.find({ key: 'degree-2' }))?.text).toMatch(/◆ 41\+ .* 2 hubs/)
      await ui.press({ key: 'row:2' })
      expect((await ui.find({ key: 'degree-row' }))?.text).toMatch(/in-degree 41\+ · 1 listed of 2/)
      expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Router')
      expect(await ui.find({ key: 'hub-1' })).toBeUndefined()
      await ui.press({ key: 'clear' })
      expect(await ui.find({ key: 'degree-row' })).toBeUndefined()
      expect((await ui.find({ key: 'hub-1' }))?.text).toContain('Kernel')
      // A flow, forbidden, opens the Boundaries tab with its source marked and its cell run to its target.
      await ui.press({ key: 'tab:overview' })
      expect((await ui.find({ key: 'flow-0' }))?.text).toMatch(/Core +→ ■ Http .* 3 × forbidden/)
      await ui.press({ key: 'row:3' })
      expect(titled((await ui.find({ key: 'coupling-head' }))?.text)).toMatch(/^Core → Http/)
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    expect(w.prompts).toEqual([])
  })

  test('truncated counts read as lower bounds', async ($, on) => {
    const cycles = { count: 50, truncated: true, truncation_reasons: ['cycle_limit'], largest: [] }
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ cycles, dead_code_candidates: 100, dead_code_truncated: true }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await tilesLine(ui)).toMatch(/^50\+ cycles +12 max degree +100\+ dead code +0 drifted$/)
    await ui.unmount()
  })

  test('a trend is drawn only from five snapshots that move', async ($, on) => {
    const trend = [1, 3, 2, 2, 4].map((cycles, i) => ({ snapshot_id: `s${i}`, cycles, max_degree: 10 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ trend }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    // Two snapshots: numbers only.
    expect(await tilesLine(ui)).not.toMatch(/[▁-█]/)
    await slash($, '')
    await w.clock.settle()
    expect(await tilesLine(ui)).toContain('1 cycle ▁▆▃▃█')
    // A flat line says nothing.
    expect(await tilesLine(ui)).not.toMatch(/max degree [▁-█]/)
    await ui.unmount()
  })

  test('the tabs switch by their hotkey buttons, and the hubs tab lists every ranked component', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      const tabs = await ui.findAll({ type: 'Button' })
      // Six tabs at 60 columns, narrow: the active one by name, the others by digit, none drawing a hotkey.
      const labelled = (t: (typeof tabs)[number]) => (t.props.hotkey === undefined ? String(t.text) : `${String(t.props.hotkey)}: ${t.text}`)
      expect(tabs.filter(t => String(t.key).startsWith('tab:')).map(labelled)).toEqual([' Overview ', ' 2 ', ' 3 ', ' 4 ', ' 5 ', ' 6 ', ' 7 ', ' 8 '])
      // The open tab stands on the selection colour.
      expect((await ui.find({ key: 'tab:overview-bg' }))?.props.backgroundColor).toBe('selectionBg')
      expect(await ui.find({ key: 'tab:hubs-bg' })).toBeUndefined()
      // Every tab keeps its hotkey on a hidden twin.
      expect(tabs.filter(t => String(t.key).startsWith('tabkey:')).map(t => `${String(t.props.hotkey)} ${String(t.key)}`)).toEqual([
        '1 tabkey:overview',
        '2 tabkey:hubs',
        '3 tabkey:boundaries',
        '4 tabkey:cycles',
        '5 tabkey:issues',
        '6 tabkey:changes',
        '7 tabkey:branch',
        '8 tabkey:churn',
      ])
      await ui.press({ key: 'tabkey:cycles' })
      expect((await ui.find({ key: 'tab:cycles' }))?.text).toBe(' Cycles ')
      expect((await ui.find({ key: 'tab:cycles' }))?.props.hotkey).toBeUndefined()
      expect((await ui.find({ key: 'tab:overview' }))?.text).toBe(' 1 ')
      await ui.press({ key: 'tabkey:overview' })
      // The active tab is drawn at full strength, the others dim.
      expect((await ui.find({ key: 'tab:overview' }))?.props.dimColor).toBeUndefined()
      expect((await ui.find({ key: 'tab:hubs' }))?.props.dimColor).toBe(true)
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'tab:hubs' }))?.props.dimColor).toBeUndefined()
      expect((await ui.find({ key: 'hub-head' }))?.text).toMatch(/name +in out cross$/)
      expect((await ui.find({ key: 'hub-0' }))?.text).toMatch(/^› +Router .*41 +3 +2 *$/)
      expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/◆ Kernel/)
      await ui.press({ key: 'tab:boundaries' })
      expect((await ui.find({ key: 'pane' }))?.text).toContain('sends no boundary map')
      expect(await ui.find({ key: 'hub-0' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      expect(await ui.find({ key: 'session-head' })).toBeDefined()
      await ui.unmount()
    }
  })

  test('j and k move the selection marker within the list, and o opens the marked row', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:hubs' })
    expect((await ui.find({ key: 'down' }))?.props.hotkey).toBe('j')
    expect((await ui.find({ key: 'up' }))?.props.hotkey).toBe('k')
    expect((await ui.find({ key: 'open' }))?.props.hotkey).toBe('o')
    await ui.press({ key: 'up' })
    expect((await ui.find({ key: 'hub-0' }))?.text).toMatch(/^›/)
    await ui.press({ key: 'down' })
    expect((await ui.find({ key: 'hub-0' }))?.text).toMatch(/^ /)
    expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/^›/)
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
    await ui.press({ key: 'tab:hubs' })
    expect(await $.ui.focus({ requestId: 'knossos', key: 'row:1' } as never)).toEqual({})
    expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/^›/)
    // Onto a tab: the marker stays on the list.
    await $.ui.focus({ requestId: 'knossos', key: 'tab:hubs' } as never)
    expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/^›/)
    // Enter on the focused row presses it.
    await ui.press({ key: 'row:1' })
    await w.clock.settle()
    expect((await ui.find({ key: 'detail' }))?.text).toContain('App\\Kernel')
    await ui.press({ key: 'back' })
    expect((await ui.find({ key: 'hub-1' }))?.text).toMatch(/^›/)
    await ui.unmount()
  })

  test('a focus ring landing on a hidden tab twin moves onto its visible tab, and back past it', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    // Forward: from Overview onto Hubs' twin lands on Hubs.
    await $.ui.focus({ requestId: 'knossos', key: 'tab:overview' } as never)
    await $.ui.focus({ requestId: 'knossos', key: 'tabkey:hubs' } as never)
    // Backward from Hubs, its twin comes first: the ring goes on to Overview.
    await $.ui.focus({ requestId: 'knossos', key: 'tabkey:hubs' } as never)
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

  test('the knossos_context tool answers one file in one compact call: boundary, rules, dependents, tests, commits and this session', async ($, on) => {
    const context = JSON.stringify({
      status: 'ok',
      path: `${ROOT}/src/Router.php`,
      project_id: 'p1',
      snapshot_id: 's1',
      file: {
        path: 'src/Router.php',
        language: 'php',
        lines: 120,
        boundary: 'core',
        components: 4,
        dependents: { count: 41, boundaries: ['Http'], top: ['src/Kernel.php'] },
        tests: { items: [{ path: 'tests/RouterTest.php', distance: 1 }], more: false },
        commits: [{ rev: 'abc1234', at: 1_791_115_340, subject: 'feat: route' }],
      },
    })
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }], context: [{ stdout: context }], brief: [{ stdout: brief() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const asked of ['src/Router.php', `${ROOT}/src/Router.php`]) {
      const answer = await $.tool.call({ tool: 'mcp__knossos__knossos_context', path: asked })
      const text = String((answer as { result?: unknown }).result)
      expect(text).toContain('src/Router.php: boundary core, PHP, 120 lines, 4 components.')
      expect(text).toContain('Rules: core may not depend on workers, tests.')
      expect(text).toContain('Dependents: 41 files in Http; closest: src/Kernel.php, and 40 more.')
      expect(text).toContain('Tests that reach it: tests/RouterTest.php (1 hop).')
      expect(text).toContain('This session changed it.')
      expect(text.length).toBeLessThanOrEqual(2_000)
    }
    // Read through the wrapper by the path under the project, and only that.
    expect(w.contextRuns().map(r => r.slice(4))).toEqual([['src/Router.php'], ['src/Router.php']])
    // Registered for the model when the session started, once.
    expect(w.tools).toEqual(['knossos_context'])
    const outside = await $.tool.call({ tool: 'mcp__knossos__knossos_context', path: '/etc/passwd' })
    expect(String((outside as { result?: unknown }).result)).toContain('is outside the project')
    expect(w.contextRuns()).toHaveLength(2)
  })

  test('a git commit in any loop gets one note of what it carries: violations, untested files, new cycles; said once, and never with notes off', async ($, on) => {
    const violation = { policy_id: 'core-alone', source: 'App\\Router', target: 'App\\Worker', source_boundaries: [], target_boundaries: [] }
    const turn = brief({
      changed_files: ['src/Router.php', 'src/Kernel.php'],
      impact: {
        'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 2 },
        'src/Kernel.php': { path: 'src/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'core', tests: 0 },
      },
      policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false },
    })
    // The later graph still reports the violation the turn introduced.
    const policed = JSON.parse(policedDashboard()) as { policy: object }
    const later = JSON.stringify({ ...policed, snapshot_id: 's2', cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [{ size: 2, members: ['Router', 'Kernel'] }] }, policy: { ...policed.policy, total: 1, items: [{ ...violation, source_kind: 'class', target_kind: 'class', source_boundary: 'core', target_boundary: 'workers', path: 'src/Router.php', line: 3 }] } })
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }, { stdout: later }], brief: [{ stdout: turn }], bashOutput: committing, git: committed })
    await $.session.start(START)
    await w.clock.settle()
    // Nothing yet: a commit carries nothing the graph knows of.
    expect((await bash($, 'git status')).context ?? []).toEqual([])
    expect((await bash($, 'git commit -m first')).context ?? []).toEqual([])
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const said = (await bash($, 'git add -A && git commit -m "route"')).context ?? []
    expect(said).toEqual([
      "knossos: this session's changes carry 1 boundary-policy violation this session introduced (App\\Router → App\\Worker); 1 changed file no test reaches (src/Kernel.php); 1 dependency cycle new since the session began (Router → Kernel). Check them before you push.",
    ])
    // The same note is not said twice in one loop; a subagent's loop is told on its own.
    expect((await bash($, 'git commit --amend --no-edit')).context ?? []).toEqual([])
    const sub = await $.tool.call({ tool: 'Bash', command: 'git commit -m sub', agentId: 'agent-1' } as never)
    expect((sub as { context?: string[] }).context ?? []).toHaveLength(1)
  })

  test('a commit gets no note with notes off', { options: { agentNotes: false } }, async ($, on) => {
    const turn = brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 0 } } })
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }], brief: [{ stdout: turn }], bashOutput: committing, git: committed })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await bash($, 'git commit -m x')).context ?? []).toEqual([])
  })

  test("a commit is told by the project's HEAD moving to a commit: a mention, a reprint, a checkout or another repository's commit gets no note", async ($, on) => {
    const turn = brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 0 } } })
    // What each command prints, and what it adds to the project's reflog.
    const runs: Record<string, { out: string; reflog: string[] }> = {
      'grep -rn "git commit" scripts': { out: 'scripts/release.sh:4: git commit -m release', reflog: [] },
      "echo 'git commit -m x'": { out: 'git commit -m x', reflog: [] },
      'gh pr create --body "after git commit"': { out: 'https://github.com/o/r/pull/7', reflog: [] },
      'git commit --dry-run -m x': { out: 'On branch main\nChanges to be committed:\n\tmodified:   src/Router.php', reflog: [] },
      'git commit -m x': { out: 'On branch main\nnothing to commit, working tree clean', reflog: [] },
      // An earlier commit's line printed again, and a traced process: HEAD stays where it was.
      'git log -1 --format=%B': { out: '[x 1234567] an old subject', reflog: [] },
      'strace -f make': { out: '[pid 1234567] write(1, "ok", 2) = 2', reflog: [] },
      // A commit in another repository prints git's line, but the project's HEAD does not move.
      'cd /other && git commit -m elsewhere': { out: '[main abc1234] elsewhere\n 1 file changed', reflog: [] },
      // HEAD moves without a commit.
      'git checkout -b topic': { out: "Switched to a new branch 'topic'", reflog: ['checkout: moving from main to topic'] },
      'git pull --ff-only': { out: 'Fast-forward', reflog: ['pull --ff-only: Fast-forward'] },
      'git merge origin/main': { out: 'Updating 1a2b3c4..5d6e7f8\nFast-forward', reflog: ['merge origin/main: Fast-forward'] },
      // Commits git prints no `[branch sha]` line for, or whose line is cut away, and one made under another name.
      'git merge --no-ff topic': { out: "Merge made by the 'ort' strategy.\n src/Router.php | 2 +-", reflog: ["merge topic: Merge made by the 'ort' strategy."] },
      'git commit -q -m quiet': { out: '', reflog: ['commit: quiet'] },
      'git commit -m piped | tail -1': { out: ' 1 file changed, 1 insertion(+)', reflog: ['commit: piped'] },
      'gc -m "via an alias"': { out: '[main 1a2b3c4] via an alias\n 1 file changed', reflog: ['commit: via an alias'] },
      'git commit -m first && git checkout main': { out: "[topic 1a2b3c4] first\nSwitched to branch 'main'", reflog: ['commit: first', 'checkout: moving from topic to main'] },
      'git cherry-pick topic': { out: '[main 9f8e7d6]  a subject that starts with spaces', reflog: ['cherry-pick: three'] },
    }
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }], brief: [{ stdout: turn }], bashOutput: command => runs[command]?.out ?? 'done', git: command => runs[command]?.reflog ?? [] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const commands = Object.keys(runs)
    const made = commands.slice(commands.indexOf('git merge --no-ff topic'))
    for (const command of commands.filter(c => !made.includes(c))) expect((await bash($, command)).context ?? [], command).toEqual([])
    // Each in a loop of its own: the same note is said once per loop.
    for (const [i, command] of made.entries()) {
      const ran = (await $.tool.call({ tool: 'Bash', command, agentId: `agent-${i}` } as never)) as { context?: string[] }
      expect(ran.context ?? [], command).toHaveLength(1)
    }
  })

  test('without a reflog, a HEAD that moved is a commit only when git printed its line for one', async ($, on) => {
    const turn = brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 0 } } })
    const outputs: Record<string, string> = { 'git checkout main': "Switched to branch 'main'", 'git commit -m x': '[main 1a2b3c4]   x\n 1 file changed' }
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }], brief: [{ stdout: turn }], bashOutput: command => outputs[command] ?? 'done', git: command => (command in outputs ? ['moved'] : []), reflogOff: true })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await bash($, 'git checkout main')).context ?? []).toEqual([])
    expect((await bash($, 'git commit -m x')).context ?? []).toHaveLength(1)
  })

  test('a project in no repository gets no commit note, whatever a command printed', async ($, on) => {
    const turn = brief({ impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 0 } } })
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }], brief: [{ stdout: turn }], bashOutput: committing })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await bash($, 'cd /elsewhere && git commit -m x')).context ?? []).toEqual([])
    expect(w.calls.filter(c => c[0] === 'git').length).toBeGreaterThan(0)
  })

  test("a commit note and knossos_context speak only of this session's changes, and a commit note names new cycles only against a whole list", async ($, on) => {
    const violation = { policy_id: 'core-alone', source: 'App\\Router', target: 'App\\Worker', source_boundaries: [], target_boundaries: [] }
    const turn = brief({
      changed_files: ['src/Router.php'],
      impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 0 } },
      policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false },
    })
    // The graph as the session began lists 10 of its 12 cycles; the later one holds 13, one listed it did not list before.
    const cycles = (count: number, extra: string[][]) => ({ count, truncated: true, truncation_reasons: [], largest: [...Array.from({ length: 10 }, (_, i) => ({ size: 2, members: [`A${i}`, `B${i}`] })), ...extra.map(members => ({ size: members.length, members }))].slice(0, 10) })
    const first = JSON.stringify({ ...(JSON.parse(policedDashboard()) as object), cycles: cycles(12, []) })
    const kernelContext = JSON.stringify({ status: 'ok', path: `${ROOT}/src/Kernel.php`, project_id: 'p1', snapshot_id: 's1', file: { path: 'src/Kernel.php', language: 'php', lines: 40, boundary: 'core', components: 1, dependents: { count: 3, boundaries: [], top: [] }, tests: { items: [], more: false }, commits: [] } })
    const later = JSON.stringify({ ...(JSON.parse(policedDashboard()) as object), snapshot_id: 's2', cycles: { ...cycles(13, []), largest: [{ size: 2, members: ['New', 'Cycle'] }, ...cycles(13, []).largest.slice(0, 9)] } })
    // Since the session began: the Router changed by this session, the Kernel by someone else.
    const ledger = JSON.stringify({ status: 'ok', since: 's1', complete: true, files: { 'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'core', tests: 0 }, 'src/Kernel.php': { status: 'changed', dependents: 3, boundaries: [], boundary: 'core', tests: 0 } }, files_truncated: false, tests: [], tests_truncated: false })
    const w = world(on, { dashboard: [{ stdout: first }, { stdout: later }], brief: [{ stdout: turn }], ledger: [{ stdout: ledger }], bashOutput: committing, git: committed, watch: [[{ event: 'ready', project_id: 'p1', snapshot_id: 's1', files: 3, scanned: false }]], context: [{ stdout: kernelContext }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 2 })
    await w.clock.advance(100)
    await w.clock.settle()
    await $.turn.complete(TURN)
    await w.clock.settle()
    const said = (await bash($, 'git commit -m route')).context ?? []
    expect(said).toEqual(["knossos: this session's changes carry 1 changed file no test reaches (src/Router.php); 1 dependency cycle new since the session began. Check them before you push."])
    // The Kernel changed since the session began, but not by it.
    const answer = String((await $.tool.call({ tool: 'mcp__knossos__knossos_context', path: 'src/Kernel.php' }) as { result?: unknown }).result)
    expect(answer).toContain('This session has not changed it.')
  })

  test('a violation the policy check no longer reports is not carried by the commit note', async ($, on) => {
    const violation = { policy_id: 'core-alone', source: 'App\\Router', target: 'App\\Worker', source_boundaries: [], target_boundaries: [] }
    const turn = brief({
      changed_files: ['src/Router.php'],
      impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 2 } },
      policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false },
    })
    // Fixed since: the later graph, past the brief's own, reports nothing in its complete check.
    const later = JSON.stringify({ ...(JSON.parse(policedDashboard()) as object), snapshot_id: 's2', trend: [{ snapshot_id: 's1', cycles: 0, max_degree: 0 }, { snapshot_id: 's2', cycles: 0, max_degree: 0 }] })
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }, { stdout: later }], brief: [{ stdout: turn }], bashOutput: committing, git: committed })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect((await bash($, 'git commit -m route')).context ?? []).toEqual([])
  })

  test('a violation a turn introduced is carried by the commit note while the dashboard is older than the brief that reported it', async ($, on) => {
    const violation = { policy_id: 'core-alone', source: 'App\\Router', target: 'App\\Worker', source_boundaries: [], target_boundaries: [] }
    // The turn's scan made s2 and found the violation there.
    const turn = brief({
      snapshot_id: 's2',
      changed_files: ['src/Router.php'],
      impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'core', tests: 2 } },
      policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false },
    })
    // The dashboard reload after the turn fails, so the pane keeps s1's figures, whose complete check predates the violation.
    const w = world(on, { dashboard: [{ stdout: policedDashboard() }, { stdout: '' }], brief: [{ stdout: turn }], bashOutput: committing, git: committed })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect((await bash($, 'git commit -m route')).context ?? []).toEqual([
      "knossos: this session's changes carry 1 boundary-policy violation this session introduced (App\\Router → App\\Worker). Check them before you push.",
    ])
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

  test('after a /clear the new session is told again what the one before it was told', async ($, on) => {
    const violation = { policy_id: 'p', source: 'App\\A', target: 'App\\B', source_boundaries: [], target_boundaries: [] }
    const turn = brief({ tests: [{ path: 'tests/RouterTest.php', distance: 1 }], policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false } })
    const w = world(on, { brief: [{ stdout: turn }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(turnNotes(w)).toHaveLength(1)
    await $.session.end({ reason: 'clear', sessionId: 'x', resume: { id: 'x' } } as never)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    // The model of the new session never read the first note: the violation and the tests are its news too.
    expect(turnNotes(w)).toHaveLength(2)
    expect(turnNotes(w)[1]?.text).toContain('- p: App\\A → App\\B')
    expect(turnNotes(w)[1]?.text).toContain('knossos: 1 test reaches')
  })

  test('the key help line shows and hides', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'help-0' })).toBeUndefined()
    await ui.press({ key: 'keys' })
    expect((await ui.find({ key: 'help-0' }))?.text).toMatch(/1–8 +switch tabs/)
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
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● stale 2h {3}rescan$/)
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
        expect((await ui.find({ key: 'title' }))?.text).toMatch(/● scanning… · stale \d+[smh] $/)
        expect(await ui.find({ key: 'rescan' })).toBeUndefined()
        await w.clock.advance(1000)
        await w.clock.settle()
        // A second press while one is coming adds nothing.
        expect(w.scanRuns()).toHaveLength(1)
        expect(w.scanRuns()[0]).toEqual(['sh', expect.stringMatching(/\/hooks\/scripts\/knossos-run\.sh$/), 'scan', ROOT])
        expect(w.dashboardRuns().length).toBeGreaterThan(before)
      }
      expect((await ui.find({ key: 'title' }))?.text).toMatch(/● fresh 1s $/)
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
    await ui.press({ key: 'tab:hubs' })
    const loads = w.dashboardRuns().length
    await ui.press({ key: 'rescan' })
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/not an allowed root +● scan failed /)
    expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Router')
    expect(w.dashboardRuns().length).toBe(loads)
    // Still stale, so it can be tried again; silence is a failure too.
    await ui.press({ key: 'rescan' })
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/knossos said nothing +● scan failed /)
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
          const rows = boxes.filter(b => b.key !== 'pane' && b.key !== 'detail' && b.key !== 'bar' && b.props.display !== 'none')
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
    expect((await ui.find({ key: 'empty' }))?.text).toContain('No architecture graph yet')
    expect(await ui.find({ key: 'pane' })).toBeUndefined()
    // One thing to do, and only the press sends it.
    expect(w.prompts).toEqual([])
    await ui.press({ key: 'scan-ask' })
    expect(w.prompts).toEqual(['Scan this project with Knossos (scan_project), then give me a short summary of its architecture.'])
    await ui.unmount()
  })

  test('the pane opened before the first dashboard lands says it is reading the graph, and q there sends nothing', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard(), hold: 5_000 }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'empty' }))?.text).toContain('Reading the graph…')
    expect((await ui.find({ key: 'empty' }))?.text).not.toContain('has not scanned')
    expect(await ui.find({ key: 'scan-ask' })).toBeUndefined()
    expect(await ui.findAll({ type: 'Button' })).toEqual([])
    await w.clock.advance(5_000)
    expect(await ui.find({ key: 'pane' })).toBeDefined()
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test('after a silent first load the pane says it could not read the graph, retries, and offers no scan', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: '' }, { stdout: '' }, { stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, '')
    await w.clock.settle()
    expect(w.dashboardRuns()).toHaveLength(2)
    const ui = await mountPane($)
    const text = (await ui.find({ key: 'empty' }))?.text ?? ''
    expect(text).toContain('Could not read the graph')
    expect(text).toContain('retrying')
    expect(text).not.toContain('has not scanned')
    expect(await ui.find({ key: 'scan-ask' })).toBeUndefined()
    expect(await ui.findAll({ type: 'Button' })).toEqual([])
    // Retried on its own while the pane shows it, not only at the next turn's end.
    await w.clock.advance(16_000)
    expect(w.dashboardRuns()).toHaveLength(3)
    expect(await ui.find({ key: 'pane' })).toBeDefined()
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test('an error from the dashboard is not called unscanned either', async ($, on) => {
    const failed = JSON.stringify({ ...(JSON.parse(dashboard) as object), status: 'error' })
    const w = world(on, { dashboard: [{ stdout: failed }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'empty' }))?.text).toContain('Could not read the graph')
    expect(await ui.find({ key: 'scan-ask' })).toBeUndefined()
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test('pressing a hub shows its detail and back returns', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: detailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:hubs' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    const detail = (await ui.find({ key: 'detail' }))?.text
    expect(detail).toContain('App\\Router')
    expect(titled(detail)).toMatch(/Dependencies · used by 1 · uses 0.*Kernel/)
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
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

  test('lays the pane out by its width: one column to 130, a two-column grid past it, every row within the width, on terminal and desktop', async ($, on) => {
    const hubs = Array.from({ length: 30 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: i % 3, boundary: i % 2 === 0 ? 'Http' : 'Core' }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      for (const bodyColumns of [60, 100, 130, 140, 200]) {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns, scroll: { offset: 0, bodyRows: 40 } } })
        for (const tab of ['tab:overview', 'tab:hubs', 'tab:issues', 'tab:changes', 'tab:cycles', 'tab:boundaries']) {
          await ui.press({ key: tab })
          const boxes = await ui.findAll({ type: 'Box' })
          const hidden = boxes.filter(b => b.props.display === 'none').reduce((n, b) => n + [...b.text].length, 0)
          const rows = boxes.filter(b => b.key !== 'pane' && b.key !== 'detail' && b.props.display !== 'none')
          for (const row of rows) {
            const width = [...drawn(row.text)].length + hotkeyPrefixes(row) - (row.key === 'tabs' ? hidden : 0)
            expect(width, `${surface} ${bodyColumns} ${tab} ${String(row.key)}`).toBeLessThanOrEqual(bodyColumns)
          }
        }
        await ui.press({ key: 'tab:hubs' })
        const grid = (await ui.findAll({ type: 'Box' })).some(b => typeof b.key === 'string' && b.key.includes('|'))
        expect(grid, `${surface} ${bodyColumns}`).toBe(bodyColumns > 130)
        // Framed from 80 columns on, a light top rule below; wide, the list and the marked row's detail side by side, each framed.
        const keyed = (await ui.findAll({ type: 'Box' })).find(b => typeof b.key === 'string' && (bodyColumns > 130 ? b.key.startsWith('hubs-head|peek-') : b.key === 'hubs-head'))
        const text = keyed?.text ?? ''
        const head = text
        expect(head.startsWith(bodyColumns >= 80 ? '╭─ ' : '── '), `${surface} ${bodyColumns}: ${head}`).toBe(true)
        await ui.unmount()
      }
    }
  })

  test("sizes the pane's lists to the rows its body has, says how many more there are, and scrolls to the marker", async ($, on) => {
    const hubs = Array.from({ length: 30 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const listed = async (bodyRows: number) => {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100, scroll: { offset: 0, bodyRows } } })
        await ui.press({ key: 'tab:hubs' })
        const rows = (await ui.findAll({ type: 'Box' })).filter(b => typeof b.key === 'string' && /^hub-\d+$/.test(b.key))
        const more = (await ui.findAll({ type: 'Box' })).find(b => b.key === 'hub-window')?.text ?? ''
        await ui.unmount()
        return { count: rows.length, more }
      }
      const short = await listed(20)
      const tall = await listed(45)
      expect(short.count).toBeGreaterThanOrEqual(5)
      expect(tall.count).toBeGreaterThan(short.count)
      expect(short.more).toMatch(/\d+ more ↓/)
      // However tall the pane, a list stops at 28 rows (of the 31: every hub and the one hotspot that is not a hub) and says how many more.
      expect((await listed(200)).count).toBe(28)
    }
    // The marker past the window brings the window along.
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100, scroll: { offset: 0, bodyRows: 20 } } })
    await ui.press({ key: 'tab:hubs' })
    for (let i = 0; i < 25; i++) await ui.press({ key: 'down' })
    expect((await ui.find({ key: 'hub-25' }))?.text).toMatch(/›/)
    expect((await ui.find({ key: 'hub-window' }))?.text).toMatch(/\d+ above ↑/)
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
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
    await ui.press({ key: 'tab:hubs' })
    expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Newer')
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
      expect((await ui.find({ key: 'tab:issues' }))?.text).toBe(' 5³ ')
      await ui.press({ key: 'tab:issues' })
      const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
      expect(titled(text)).toMatch(/Policy violations *▲ 2/)
      expect(text).toContain('Kernel::boot')
      expect(text).toContain('Kernel.php:12')
      expect(titled(text)).toMatch(/Diagnostics *1 error · 0 warnings/)
      expect(text).toContain('PHP001 Syntax error')
      expect(titled(text)).toMatch(/Dead code *4 · first 1/)
      expect(text).toContain('Router::unused')
      expect(text).toContain('src/Http/Router.php')
      expect((await ui.find({ key: 'pol-0' }))?.text).toMatch(/^›/)
      // The marker walks the diagnostics after the violations, then the dead code.
      await ui.press({ key: 'down' })
      expect((await ui.find({ key: 'diag-0' }))?.text).toMatch(/^›/)
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
    expect((await ui.find({ key: 'detail' }))?.text).toContain('Dependencies · used by 2')
    await ui.unmount()
  })

  test('the cycles tab draws the marked cycle as boxes framed in their boundary colours, and j walks its members', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      await ui.press({ key: 'tab:cycles' })
      expect(titled((await ui.find({ key: 'cycle-diagram-head' }))?.text)).toMatch(/^Cycle 1 +3 members · crosses 2 boundaries$/)
      expect((await ui.find({ key: 'cycle-legend' }))?.text).toBe('■ Core  ■ Http  ╫ crosses a boundary')
      expect(titled((await ui.find({ key: 'cycles-head' }))?.text)).toMatch(/^Cycles +1 · largest first$/)
      // Most members are in Http: the cycle's line names it, and how many other boundaries it reaches.
      expect((await ui.find({ key: 'cycle-0' }))?.text).toMatch(/^› {2}cycle 1 · 3 members · ■ Http \+1 boundary *$/)
      // Each member a box whose name opens it; the marked one on the tint.
      expect((await ui.find({ key: 'row:0' }))?.props.label).toBe('Kernel::boot')
      expect(await ui.find({ key: 'row:0-bg' })).toBeDefined()
      expect((await ui.find({ key: 'row:2' }))?.props.label).toBe('Router::dispatch')
      // The frames carry the boundaries' colours, two of them.
      // The marked one's frame in the accent.
      const frames = new Set((await ui.findAll({ type: 'Text' })).filter(t => /^╭─+╮$/.test(t.text)).map(t => String(t.props.color)))
      expect(frames.has('suggestion')).toBe(true)
      expect([...frames].some(c => /_FOR_SUBAGENTS_ONLY$/.test(c))).toBe(true)
      // j names the member it moves to; pressing it moves the tint there.
      expect((await ui.find({ key: 'next:1' }))?.props.hotkey).toBe('j')
      await ui.press({ key: 'next:1' })
      expect(await ui.find({ key: 'row:1-bg' })).toBeDefined()
      expect(await ui.find({ key: 'row:0-bg' })).toBeUndefined()
      expect((await ui.find({ key: 'prev:0' }))?.props.hotkey).toBe('k')
      // A press on the cycle's line in the list marks its first member again.
      await ui.press({ key: 'mark:0' })
      expect(await ui.find({ key: 'row:0-bg' })).toBeDefined()
      await ui.unmount()
    }
  })

  test('a marked member copies its name, asks how to break its cycle on q alone, and opens on o', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], detail: [{ stdout: fullDetailOf('Kernel') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:cycles' })
    const chain = 'App\\Core\\Kernel::boot → App\\Http\\Router::route → App\\Http\\Router::dispatch → App\\Core\\Kernel::boot'
    await ui.press({ key: 'copy' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe('App\\Core\\Kernel::boot')
    expect(await said(ui)).toBe('✓ copied Kernel::boot')
    expect(w.prompts).toEqual([])
    await ui.press({ key: 'ask' })
    await w.clock.settle()
    expect(w.prompts).toEqual([`Using the Knossos graph, how could I break this dependency cycle: ${chain}? Name the edge to cut and what would have to move.`])
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.at(-1)).toBe('App\\Core\\Kernel::boot')
    expect(await ui.find({ key: 'detail' })).toBeDefined()
    await ui.unmount()
  })

  test('a long cycle folds its middle on a narrow pane; o on the fold unfolds it and marks the first member it hid', async ($, on) => {
    const names = Array.from({ length: 9 }, (_, i) => `m${i}`)
    const cycles = { count: 1, truncated: false, truncation_reasons: [], largest: [{ size: 9, members: names, nodes: names.map(n => ({ name: n, canonical_name: `App\\Loop::${n}`, kind: 'method', boundary: 'Core' })), nodes_truncated: false }] }
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ cycles }) }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:cycles' })
    // Six boxes on a narrow pane: four members, the fold, the last.
    expect(await ui.find({ key: 'row:4' })).toBeUndefined()
    expect((await ui.find({ key: 'fold:0:4' }))?.props.label).toBe('… 4 more …')
    for (const step of ['next:1', 'next:2', 'next:3', 'next:9']) await ui.press({ key: step })
    // On the fold, o unfolds.
    const o = (await ui.findAll({ type: 'Button' })).find(b => b.props.hotkey === 'o')
    expect(o?.key).toBe('unfold:0:4')
    await ui.press({ key: 'unfold:0:4' })
    expect((await ui.find({ key: 'row:4' }))?.props.label).toBe('Loop::m4')
    expect(await ui.find({ key: 'row:4-bg' })).toBeDefined()
    await ui.unmount()
  })

  test('the boundaries tab walks its boundaries, spells out the marked one, and opens nothing', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:boundaries' })
    expect(titled((await ui.find({ key: 'focus-head' }))?.text)).toMatch(/^A Http ■ \d+ components$/)
    expect((await ui.find({ key: 'focus-out' }))?.text).toMatch(/depends on +■ Core 14/)
    expect(await ui.find({ key: 'open' })).toBeUndefined()
    await ui.press({ key: 'down' })
    expect(titled((await ui.find({ key: 'focus-head' }))?.text)).toMatch(/^B Core ■ \d+ components$/)
    expect((await ui.find({ key: 'focus-out' }))?.text).toMatch(/depends on +■ Http 3/)
    expect((await ui.find({ key: 'focus-forbidden' }))?.text).toMatch(/may not use +× Http +× cli/)
    await ui.press({ key: 'row:2' })
    await w.clock.settle()
    expect(titled((await ui.find({ key: 'focus-head' }))?.text)).toMatch(/^C cli ■ \d+ components?$/)
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

  test('the detail draws its neighbourhood: used by fanning in, uses fanning out when wide, stacked when narrow, each opening', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], detail: [{ stdout: fullDetailOf('Router') }, { stdout: fullDetailOf('Request') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const wide = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 140 } })
      await wide.press({ key: 'tab:hubs' })
      await wide.press({ key: 'row:0' })
      await w.clock.settle()
      expect(titled((await wide.find({ key: 'detail-head' }))?.text)).toMatch(/^Router +class · ■ Http$/)
      expect(titled(drawn((await wide.find({ key: 'detail-place' }))?.text ?? ''))).toBe('src/Http/Router.php:3')
      expect(titled((await wide.find({ key: 'hood-head' }))?.text)).toMatch(/^Dependencies · used by 2 · uses 1 +edges$/)
      // Each neighbour a box whose name opens it, its count on its edge; the centre between them.
      expect((await wide.find({ key: 'rel:0' }))?.props.label).toBe('Kernel')
      expect((await wide.find({ key: 'rel:2' }))?.props.label).toBe('Request')
      const lines = (await wide.findAll({ type: 'Box' })).filter(b => String(b.key).startsWith('hood-')).map(b => b.text)
      const centre = lines.findIndex(l => l.includes('Router ├'))
      expect(lines[centre]).toMatch(/►│ Router ├/)
      expect(lines.join('\n')).toMatch(/─ 4 ─/)
      expect((await wide.find({ key: 'detail' }))?.text).toContain('note: The one way in.')
      // The tab strip gives way to the detail; back is a key and a click.
      expect(await wide.find({ key: 'tab:hubs' })).toBeUndefined()
      expect((await wide.find({ key: 'back' }))?.props.hotkey).toBe('b')
      await wide.unmount()
      const narrow = await mountPane($, surface)
      const stacked = (await narrow.findAll({ type: 'Box' })).filter(b => String(b.key).startsWith('hood-')).map(b => b.text).join('\n')
      expect(stacked.indexOf('Kernel')).toBeLessThan(stacked.indexOf('Router'))
      expect(stacked.indexOf('Router')).toBeLessThan(stacked.indexOf('Request'))
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
    expect(titled((await ui.find({ key: 'detail-head' }))?.text)).toMatch(/^Request /)
    await ui.unmount()
  })

  // Claude Code resolves `$.ui.focus` by key against the drawing it holds when the call arrives, the first element
  // under that key in document order. That drawing can still be the one with the Button the person just pressed, and
  // the hubs filter's Button stays drawn above its field. A ring moved onto a Button leaves the field without the
  // keys, and the next drawing dropping that Button hands them back to the prompt: what the person types lands there.
  // So the key a field is drawn (and focused) under must be its own, in the drawing before the press and after it.
  test('n, f and m open a field under a key no Button in the pane carries, before the press or after it', async ($, on) => {
    type Mounted = Awaited<ReturnType<typeof mountPane>>
    // What a focus ring can land on: the Buttons, Inputs and Selects drawn.
    const focusable = async (ui: Mounted) => (await Promise.all(['Button', 'Input', 'Select'].map(type => ui.findAll({ type })))).flat()
    const opens = async (ui: Mounted, w: ReturnType<typeof world>, press: string) => {
      const before = await focusable(ui)
      await ui.press({ key: press })
      await w.clock.settle()
      const after = await focusable(ui)
      const fields = after.filter(el => el.type === 'Input')
      expect(fields, press).toHaveLength(1)
      const key = fields[0]?.key
      const under = (drawn: typeof after) => drawn.filter(el => el.key === key).map(el => el.type)
      return { press, before: under(before), after: under(after) }
    }
    const hubs = [{ name: 'Router', canonical_name: 'App\\Http\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 }]
    const w = world(on, { dashboard: [{ stdout: issuesDashboard({ hubs, hotspots: [] }) }], detail: [{ stdout: fullDetailOf('Greeter') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($, 'terminal', 120)
    await ui.press({ key: 'tab:hubs' })
    expect(await opens(ui, w, 'filter')).toEqual({ press: 'filter', before: [], after: ['Input'] })
    await ui.press({ key: 'tab:issues' })
    expect(await opens(ui, w, 'find')).toEqual({ press: 'find', before: [], after: ['Input'] })
    await ui.press({ key: 'find-close' })
    await ui.unmount()
    await slash($, 'inspect Greeter')
    await w.clock.settle()
    const detail = await mountPane($, 'terminal', 100)
    expect(await opens(detail, w, 'note')).toEqual({ press: 'note', before: [], after: ['Input'] })
    await detail.unmount()
  })

  test('n opens the hubs filter, typing narrows the list, Enter keeps it and x clears it', async ($, on) => {
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
      expect((await ui.find({ key: 'filter' }))?.props.hotkey).toBe('n')
      await ui.press({ key: 'filter' })
      await w.clock.settle()
      // The field opens, asking for the focus.
      expect((await ui.find({ type: 'Input', key: 'field:filter' }))?.props.autoFocus).toBe(true)
      await ui.input({ key: 'field:filter', text: 'req', kind: 'change' })
      expect((await ui.find({ key: 'hub-0' }))?.text).toContain('Request')
      expect(await ui.find({ key: 'hub-1' })).toBeUndefined()
      await ui.input({ key: 'field:filter', text: 'R ' })
      expect(await ui.find({ type: 'Input', key: 'field:filter' })).toBeUndefined()
      expect((await ui.find({ key: 'filter-row' }))?.text).toBe('   filter "R" · 3 of 3')
      await ui.press({ key: 'filter' })
      await ui.input({ key: 'field:filter', text: 'kern' })
      expect((await ui.find({ key: 'filter-row' }))?.text).toBe('   filter "kern" · 1 of 3')
      expect((await ui.find({ key: 'clear' }))?.props.hotkey).toBe('x')
      await ui.press({ key: 'clear' })
      expect(await ui.find({ key: 'filter-row' })).toBeUndefined()
      expect(await ui.find({ key: 'clear' })).toBeUndefined()
      expect((await ui.find({ key: 'hub-2' }))?.text).toContain('Kernel')
      // An empty Enter clears too.
      await ui.press({ key: 'filter' })
      await ui.input({ key: 'field:filter', text: 'zzz', kind: 'change' })
      expect((await ui.find({ key: 'hubs-none' }))?.text).toBe('   no hub matches "zzz"')
      await ui.input({ key: 'field:filter', text: '' })
      expect(await ui.find({ key: 'hubs-none' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('f finds any component or file by its letters, after a pause in the typing, and Enter or a click opens a match', async ($, on) => {
    const found = (query: string) =>
      JSON.stringify({
        status: 'ok',
        query,
        truncated: false,
        results: [
          { type: 'component', name: 'DashboardService', canonical_name: 'App\\Query\\DashboardService', kind: 'class', path: 'src/Query/DashboardService.php', line: 12, boundary: 'Core' },
          { type: 'file', name: 'src/Query/DashboardService.php', canonical_name: 'src/Query/DashboardService.php', kind: 'file', path: 'src/Query/DashboardService.php', line: null, boundary: 'Core' },
        ],
      })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], search: [{ stdout: found('dsvc') }], detail: [{ stdout: fullDetailOf('DashboardService') }], file: [{ stdout: fileDetailOf('src/Query/DashboardService.php') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 120)
      await ui.press({ key: 'tab:issues' })
      expect((await ui.find({ key: 'find' }))?.props.hotkey).toBe('f')
      await ui.press({ key: 'find' })
      await w.clock.settle()
      // The field opens over the tab and asks for the focus; the footer offers closing it.
      expect((await ui.find({ type: 'Input', key: 'field:find' }))?.props.autoFocus).toBe(true)
      expect((await ui.find({ key: 'find-close' }))?.props.hotkey).toBe('x')
      expect(await ui.find({ key: 'pol-0' })).toBeUndefined()
      // Typed fast, one search after the pause: never in a render.
      const before = w.searchRuns().length
      await ui.input({ key: 'field:find', text: 'd', kind: 'change' })
      await ui.input({ key: 'field:find', text: 'ds', kind: 'change' })
      await ui.input({ key: 'field:find', text: 'dsvc', kind: 'change' })
      expect(w.searchRuns()).toHaveLength(before)
      await w.clock.advance(200)
      await w.clock.settle()
      expect(w.searchRuns().slice(before).map(r => r.slice(4))).toEqual([['--query=dsvc']])
      expect((await ui.find({ key: 'found-0' }))?.text).toContain('DashboardService')
      expect((await ui.find({ key: 'found-1' }))?.text).toMatch(/src\/Query\/\s*DashboardService\.php/)
      // Enter opens the first match as its detail, and the finder closes.
      await ui.input({ key: 'field:find', text: 'dsvc' })
      await w.clock.settle()
      expect(w.detailRuns().at(-1)?.at(-1)).toBe('App\\Query\\DashboardService')
      expect(await ui.find({ type: 'Input', key: 'field:find' })).toBeUndefined()
      // Back from the detail is the tab as it was.
      await ui.press({ key: 'back' })
      expect(await ui.find({ key: 'pol-0' })).toBeDefined()
      // A click on a file match opens the file.
      await ui.press({ key: 'find' })
      await w.clock.settle()
      await ui.press({ key: 'row:1' })
      await w.clock.settle()
      expect(w.fileRuns().at(-1)?.at(-1)).toBe('src/Query/DashboardService.php')
      await ui.press({ key: 'back' })
      // x closes it; an empty Enter closes it too.
      await ui.press({ key: 'find' })
      await ui.press({ key: 'find-close' })
      expect(await ui.find({ type: 'Input', key: 'field:find' })).toBeUndefined()
      await ui.press({ key: 'find' })
      await ui.input({ key: 'field:find', text: '' })
      expect(await ui.find({ type: 'Input', key: 'field:find' })).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    expect(w.prompts).toEqual([])
  })

  test('the Branch tab compares the branch with its merge base only while open, once per graph, and opens what it lists', async ($, on) => {
    const item = (name: string, boundary: string) => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 4, boundary })
    const diff = JSON.stringify({
      status: 'no-snapshot',
      branch: 'feat/x',
      default_branch: 'main',
      merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
      ahead: 12,
      base: { snapshot_id: 's0', rev: '4054d3a2fc5346529576462db547efe648538fb0', at: '2026-10-04T11:05:02Z', match: 'after', commits: 9 },
      comparison: {
        crossing: { count: 1, items: [{ source: item('Greeter', 'Core'), target: item('Caller', 'Http') }] },
        cycles: { count: 0, items: [] },
        hubs: { count: 0, items: [] },
        dead_code: { count: 1, items: [item('Unused', 'Core')] },
        violations: null,
      },
    })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], branch: [{ stdout: diff }], detail: [{ stdout: fullDetailOf('Greeter') }] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.branchRuns()).toHaveLength(0)
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 120)
      expect((await ui.findAll({ key: 'tabkey:branch' })).map(b => b.props.hotkey)).toContain('7')
      await ui.press({ key: 'tab:branch' })
      await w.clock.settle()
      const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
      // Said plainly: no snapshot near the merge base, and how partial the comparison is.
      expect(text).toContain('No snapshot near the merge base is retained')
      expect(text).toContain("already holds 9 of the branch's 12 commits")
      expect(titled(text)).toMatch(/New cross-boundary dependencies +▲ 1/)
      expect(titled(text)).toMatch(/New cycles +✓ 0/)
      await ui.press({ key: 'row:0' })
      await w.clock.settle()
      expect(w.detailRuns().at(-1)?.at(-1)).toBe('App\\Greeter')
      await ui.press({ key: 'back' })
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    // Once for this graph, however often the tab was opened.
    expect(w.branchRuns()).toHaveLength(1)
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
      expect(titled((await ui.find({ key: 'hubs-head' }))?.text)).toMatch(/by out$/)
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
      // Narrow: the tiles' line says every figure, the title row the name and the pill alone.
      expect(await ui.find({ key: 'summary' })).toBeUndefined()
      expect(await tilesLine(ui)).toMatch(/^1,234 components +2 boundaries .*1 diagnostics +2 policy +0 drifted$/)
      await ui.unmount()
      // From the medium tier the tiles say the figures, and the languages are chips in the title row.
      const wide = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 140, scroll: { offset: 0, bodyRows: 40 } } })
      expect((await wide.findAll({ type: 'Text' })).filter(t => t.props.backgroundColor === 'userMessageBackground').map(t => t.text).slice(0, 2)).toEqual([' PHP ', ' TS '])
      expect((await wide.find({ key: 'tiles-0-label' }))?.text).toMatch(/components +│ boundaries +│ cycles? +│ .*│ diagnostics +│ policy +│ drifted +│$/)
      expect((await wide.find({ key: 'tiles-0-value' }))?.text).toMatch(/1,234 +│ 2 +│ .*│ 1 +│ 2 +│ 0 +│$/)
      await wide.unmount()
    }
  })

  test('the boundaries tab draws the heat map as a Raster on the terminal and as glyphs elsewhere', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    const term = await mountPane($, 'terminal')
    await term.press({ key: 'tab:boundaries' })
    expect(titled((await term.find({ key: 'bounds-head' }))?.text)).toMatch(/^Boundaries +3 boundaries · 232 deps$/)
    const grid = await term.find({ type: 'Raster' })
    expect(grid?.key).toBe('raster-heat')
    // The axis letters, each boundary's cells (one row each, or two or three when the pane has rows to spare) and the legend's two rows.
    expect([6, 9, 12]).toContain(grid?.props.rows)
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
    expect(core?.text).toMatch(/^ ■ B Core +▒+ +█+ +× +$/)
    // Core reaching into Http is forbidden and crossed: red; the empty forbidden cell is a red cross.
    // A styled piece of a row is a Text nested in the row's run: the one in the error colour.
    const crossed = (await desk.findAll({ type: 'Text', text: '▒▒' })).map(t => t.props.color)
    expect(crossed).toContain('error')
    expect((await desk.find({ key: 'heat-legend' }))?.text).toContain('forbidden')
    expect(await desk.find({ key: 'heat-legend-1' })).toBeDefined()
    expect((await desk.find({ key: 'heat-2' }))?.text).toMatch(/^ ■ C cli /)
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
    await $.config.set({ key: 'theme', value: 'light' } as never)
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
      // The footer says it, for a moment, instead of a toast.
      expect(await said(ui)).toBe('✓ copied Kernel')
      expect(w.toasts).toEqual([])
      // About two seconds: gone by the age tick after its time is up.
      await w.clock.advance(3_100)
      expect(await said(ui)).toBeUndefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    // In the detail, the component on show.
    const ui = await mountPane($)
    await ui.press({ key: 'tab:hubs' })
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
      // The marker starts on the way to this session's changes: the prompt is about what they reached.
      expect(w.prompts.at(-1)).toBe('Using the Knossos graph, what did the changes in this session reach, and which tests should I run?')
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
    expect((await ui.find({ key: 'empty' }))?.text).toContain('No architecture graph yet')
    // The offer is the one action: no second one beside it.
    expect(await ui.find({ key: 'scan-ask' })).toBeUndefined()
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
        // The violation's place, the diagnostic's and the dead code's; a hotspot is a row that opens its file's detail.
        expect(hrefs).toEqual(['file:///repo/src/Core/Kernel.php#L12', 'file:///repo/src/Broken.php#L9', 'file:///repo/src/Http/Router.php#L40'])
        const dead = links[2]!
        await ui.press({ key: String(dead.key), link: { href: 'file:///repo/src/Http/Router.php#L40' } })
        await w.clock.settle()
        expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Http/Router.php:40'])
        if (editor === 'opens') {
          expect(await said(ui)).toBe('✓ opened in editor')
        } else {
          expect(w.copies.at(-1)).toEqual({ text: '/repo/src/Http/Router.php:40', surface })
          expect(await said(ui)).toBe('✗ no editor · path copied')
          expect((await ui.findAll({ type: 'Text', text: '✗ no editor · path copied' })).map(t => t.props.color)).toContain('error')
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
    await ui.press({ key: 'tab:hubs' })
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    // The file's name is the link, its directory dim beside it.
    const place = await ui.find({ type: 'Markdown' })
    expect(place?.props.text).toBe('[Router\\.php:3](file:///repo/src/Http/Router.php#L3)')
    expect((await ui.findAll({ type: 'Text' })).some(t => t.text.endsWith('src/Http/'))).toBe(true)
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
      expect((await ui.find({ key: 'tab:changes' }))?.text).toBe(' 6² ')
      await ui.press({ key: 'tab:changes' })
      const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
      expect(titled(text)).toMatch(/Changes this session +2 turns/)
      expect(text).toMatch(/2 files → 45 dependents reaching ■ Http ■ Core/)
      expect(drawn((await ui.find({ key: 'change-0' }))?.text ?? '')).toMatch(/^› +src\/Router\.php +■ Http .*42 *$/)
      expect(drawn((await ui.find({ key: 'change-1' }))?.text ?? '')).toMatch(/^ \+ src\/Kernel\.php +■ Core .*3$/)
      // Each test once, at its nearest distance.
      expect(titled(text)).toMatch(/Tests that reach these changes · 2 +nearest first/)
      expect(drawn((await ui.find({ key: 'test-0' }))?.text ?? '')).toMatch(/tests\/Core\/KernelTest\.php +1$/)
      expect(drawn((await ui.find({ key: 'test-1' }))?.text ?? '')).toMatch(/tests\/Http\/RouterTest\.php +1$/)
      expect(text).toContain("$ vendor/bin/phpunit --filter '(KernelTest|RouterTest)'")
      // `t` copies the test command, as on Overview; `c` copies the marked file, as on every list.
      await ui.press({ key: 'tests' })
      await w.clock.settle()
      expect(w.copies.at(-1)).toEqual({ text: "vendor/bin/phpunit --filter '(KernelTest|RouterTest)'", surface })
      expect(await said(ui)).toBe('✓ copied the command for 2 tests')
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

  test('the rows a new snapshot changed are lit for about three seconds after it lands, then go back', async ($, on) => {
    const hubs = (cache: number) => [
      { name: 'Router', canonical_name: 'App\\Router', kind: 'class', in_degree: 41, out_degree: 3, cross_boundary_degree: 2 },
      { name: 'Cache', canonical_name: 'App\\Cache', kind: 'class', in_degree: cache, out_degree: 1, cross_boundary_degree: 0 },
    ]
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs: hubs(30) }) }, { stdout: paneDashboard({ snapshot_id: 's2', hubs: hubs(36) }) }], brief: [{ stdout: brief() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:hubs' })
    await ui.press({ key: 'tab:overview' })
    await ui.press({ key: 'tab:hubs' })
    const lit = async () => (await ui.findAll({ type: 'Text' })).filter(t => t.props.backgroundColor === 'memoryBackgroundColor').map(t => t.text)
    expect(await lit()).toEqual([])
    // A turn's scan lands a new snapshot in which Cache is depended on more: its row lights (Router, marked, keeps its own ground).
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await lit()).join('')).toContain(' 36 ')
    expect((await lit()).join('')).not.toContain(' 41 ')
    // The name, a Button, stands on the same ground.
    expect((await ui.find({ key: 'row:1-bg' }))?.props.backgroundColor).toBe('memoryBackgroundColor')
    await w.clock.advance(4_000)
    await w.clock.settle()
    expect(await lit()).toEqual([])
    expect(await ui.find({ key: 'row:1-bg' })).toBeUndefined()
    await ui.unmount()
  })

  test('a changed file no test reaches is marked on Changes and counted on the session card', async ($, on) => {
    const turn = brief({
      changed_files: ['src/Router.php', 'src/Kernel.php'],
      impact: {
        'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http', tests: 2 },
        'src/Kernel.php': { path: 'src/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'Core', tests: 0 },
      },
      tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }],
    })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], brief: [{ stdout: turn }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 120)
      expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).toContain('▲ 1 file no test reaches')
      await ui.press({ key: 'tab:changes' })
      expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).toContain('▲ 1 file no test reaches')
      expect(drawn((await ui.find({ key: 'change-1' }))?.text ?? '')).toMatch(/Kernel\.php .* 3 ▲ none/)
      expect(drawn((await ui.find({ key: 'change-0' }))?.text ?? '')).toMatch(/Router\.php .* 41 +2 /)
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('the count of files no test reaches lists only them, the marker on the first; again, or show all files, lists every file', async ($, on) => {
    const turn = brief({
      changed_files: ['src/Router.php', 'src/Kernel.php'],
      impact: {
        'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http', tests: 2 },
        'src/Kernel.php': { path: 'src/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'Core', tests: 0 },
      },
      tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }],
    })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], brief: [{ stdout: turn }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const listed = async (ui: Awaited<ReturnType<typeof mountPane>>) =>
      (await ui.findAll({})).filter(e => typeof e.key === 'string' && /^change-\d+$/.test(e.key)).map(e => drawn(e.text ?? ''))
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 120)
      await ui.press({ key: 'tab:changes' })
      expect(await listed(ui)).toHaveLength(2)
      expect((await ui.find({ key: 'untested-row' }))?.type).toBe('Button')
      await ui.press({ key: 'untested-row' })
      // Only the file none reaches, marked, and the count says the list is narrowed.
      const only = await listed(ui)
      expect(only).toHaveLength(1)
      expect(only[0]).toMatch(/Kernel\.php/)
      expect(await ui.find({ key: 'row:0-bg' })).toBeDefined()
      expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).toMatch(/▲ 1 file no test reaches · showing only these/)
      // The footer's `u` says what a press does now.
      expect((await ui.find({ key: 'untested' }))?.props).toMatchObject({ hotkey: 'u', label: 'all files' })
      // Again, every file, the marker still on the file it was on.
      await ui.press({ key: 'untested-row' })
      expect(await listed(ui)).toHaveLength(2)
      expect(drawn((await ui.find({ key: 'change-1' }))?.text ?? '')).toMatch(/Kernel\.php/)
      expect(await ui.find({ key: 'row:1-bg' })).toBeDefined()
      // `u` narrows it, and the row the narrowing adds widens it again.
      await ui.press({ key: 'untested' })
      expect(await listed(ui)).toHaveLength(1)
      await ui.press({ key: 'untested-all' })
      expect(await listed(ui)).toHaveLength(2)
      // From the Overview's session card the count opens Changes narrowed.
      await ui.press({ key: 'tab:overview' })
      await ui.press({ key: 'untested-row' })
      expect(await listed(ui)).toHaveLength(1)
      expect(await ui.find({ key: 'tab:changes-bg' })).toBeDefined()
      // Within the bounds the engine sets every tree, at the sizes the pane is drawn.
      for (const [bodyColumns, bodyRows] of [[60, 30], [120, 40], [200, 60]] as const) {
        await ui.redraw({ ...PANE_PROPS, bodyColumns, scroll: { offset: 0, bodyRows } })
        const size = treeSize(await ui.drawn())
        expect(size.chars, `${bodyColumns}`).toBeLessThan(72_000)
        expect(size.nodes, `${bodyColumns}`).toBeLessThan(10_000)
        expect(size.depth, `${bodyColumns}`).toBeLessThan(16)
      }
      await ui.press({ key: 'untested-all' })
      await ui.unmount()
    }
    // A /clear lists every file again.
    const ui = await mountPane($, 'terminal', 120)
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'untested-row' })
    await $.session.end({ reason: 'clear', sessionId: 'session-1', resume: { id: 'session-1' } } as never)
    w.switchSession('session-2')
    await w.clock.settle()
    expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).not.toMatch(/showing only these/)
    await ui.unmount()
  })

  test('once a later turn leaves no file untested, the list is every file again, and a file untested after that does not narrow it unasked', async ($, on) => {
    const impact = (kernel: number, config?: number) => ({
      'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http', tests: 2 },
      'src/Kernel.php': { path: 'src/Kernel.php', dependent_files: 3, boundaries: ['Core'], boundary: 'Core', tests: kernel },
      ...(config === undefined ? {} : { 'src/Config.php': { path: 'src/Config.php', dependent_files: 1, boundaries: [], boundary: null, tests: config } }),
    })
    const first = brief({ changed_files: ['src/Router.php', 'src/Kernel.php'], impact: impact(0), tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }] })
    // The kernel gains a test: no file is left that none reaches.
    const second = brief({ changed_files: ['src/Kernel.php'], impact: { 'src/Kernel.php': impact(1)['src/Kernel.php'] }, tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }] })
    // Then a new file none reaches.
    const third = brief({ changed_files: ['src/Config.php'], impact: { 'src/Config.php': impact(1, 0)['src/Config.php']! }, tests: [{ path: 'tests/Http/RouterTest.php', distance: 1 }] })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], brief: [{ stdout: first }, { stdout: second }, { stdout: third }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await mountPane($, 'terminal', 120)
    const listed = async () => (await ui.findAll({})).filter(e => typeof e.key === 'string' && /^change-\d+$/.test(e.key)).length
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'untested-row' })
    expect(await listed()).toBe(1)
    await edit($, `${ROOT}/src/Kernel.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(await listed()).toBe(2)
    await edit($, `${ROOT}/src/Config.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    // Three files, one none reaches, and the list is not narrowed to it.
    expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).toContain('▲ 1 file no test reaches')
    expect(drawn((await ui.find({ key: 'pane' }))?.text ?? '')).not.toContain('showing only these')
    expect(await listed()).toBe(3)
    await ui.unmount()
  })

  test("a tile that counts a set opens the tab that lists it, and a cut list's line moves the marker onto what it counts", async ($, on) => {
    const hubs = Array.from({ length: 40 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0, path: `src/Hub${i}.php`, line: 3 }))
    const w = world(on, { dashboard: [{ stdout: issuesDashboard({ hubs }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 120)
      const tiles = (await ui.findAll({ type: 'Button' })).map(b => b.key).filter((k): k is string => typeof k === 'string' && k.startsWith('stat:'))
      expect(tiles).toContain('stat:cycles')
      await ui.press({ key: 'stat:cycles' })
      expect(await ui.find({ key: 'tab:cycles-bg' })).toBeDefined()
      await ui.press({ key: 'tab:overview' })
      // Forty hubs on a short pane: the line under the list moves the marker onto the first it hides.
      await ui.redraw({ ...PANE_PROPS, bodyColumns: 120, scroll: { offset: 0, bodyRows: 24 } })
      await ui.press({ key: 'tab:hubs' })
      const more = (await ui.findAll({ type: 'Button' })).map(b => b.key).find((k): k is string => typeof k === 'string' && /^more:\d+$/.test(k))
      expect(more).toBeDefined()
      await ui.press({ key: more! })
      expect(await ui.find({ key: `row:${more!.slice('more:'.length)}-bg` })).toBeDefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('a changed file opens a detail that names who depends on it, from Changes', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], file: [{ stdout: fileDetailOf('src/Router.php') }], detail: [{ stdout: fullDetailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface)
      for (const [tab, key] of [['tab:changes', 'row:0']] as const) {
        await ui.press({ key: tab })
        await ui.press({ key })
        await w.clock.settle()
        const text = drawn((await ui.find({ key: 'detail' }))?.text ?? '')
        expect(titled(text), `${tab} ${key}`).toMatch(/Router\.php +■ Http/)
        expect(titled(text)).toMatch(/Dependencies · used by 14 · uses 0 +edges/)
        // The marked dependent: a box by its name, on the tint, its count on its edge.
        expect((await ui.find({ key: 'row:0' }))?.props.label).toBe('Kernel.php')
        expect(await ui.find({ key: 'row:0-bg' })).toBeDefined()
        expect(text).toMatch(/Kernel\.php +├─* 6 /)
        expect(titled(text)).toMatch(/Declares · 2 components/)
        await ui.press({ key: 'back' })
        // Back on the tab, the marker stands where the detail was opened from.
        expect((await ui.find({ key: 'change-0' }))?.text, `${tab} ${key}`).toMatch(/^›/)
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
      // Narrow, the drifted figure stands in the tiles' line, its word the press that lists them.
      expect((await ui.find({ key: 'drifted' }))?.props.label).toBe('drifted')
      expect(await tilesLine(ui)).toContain('2 drifted')
      expect(await ui.find({ key: 'drift-head' })).toBeUndefined()
      await ui.press({ key: 'drifted' })
      expect(drawn((await ui.find({ key: 'drift-0' }))?.text ?? '')).toMatch(/^›− src\/Gone\.php +■ Core *$/)
      // A deleted file has nothing for the editor; the changed one does.
      expect(await ui.find({ key: 'edit' })).toBeUndefined()
      await ui.press({ key: 'down' })
      await ui.press({ key: 'edit' })
      await w.clock.settle()
      expect(w.editorRuns().at(-1)).toEqual(['code', '-g', '/repo/src/Router.php'])
      await ui.press({ key: 'open' })
      await w.clock.settle()
      expect(titled(drawn((await ui.find({ key: 'detail' }))?.text ?? ''))).toMatch(/Dependencies · used by 14/)
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

  test('the stat tiles say the figures from the medium tier on, the drifted one listing the files, on both surfaces', async ($, on) => {
    const drifted = issuesDashboard({
      freshness: { state: 'stale', age_seconds: 60, drift_files: 2, drifted: [{ path: 'src/Router.php', change: 'changed', boundary: 'Http' }], drifted_truncated: false },
    })
    const w = world(on, { dashboard: [{ stdout: drifted }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100, scroll: { offset: 0, bodyRows: 40 } } })
      // The tab is the session's, not the surface's: start each on the Overview.
      await ui.press({ key: 'tab:overview' })
      // The languages are chips in the title row: no summary line.
      expect(await ui.find({ key: 'summary' })).toBeUndefined()
      expect((await ui.find({ key: 'title' }))?.text).toMatch(/ PHP {3}TS /)
      expect(await ui.find({ key: 'tiles-top' })).toBeDefined()
      // The drifted tile's label is the press that lists them, as the summary's was.
      expect((await ui.find({ key: 'drifted' }))?.props.label).toBe('drifted')
      await ui.press({ key: 'drifted' })
      expect(drawn((await ui.find({ key: 'drift-0' }))?.text ?? '')).toMatch(/src\/Router\.php/)
      await ui.press({ key: 'drift' })
      // Off the Overview `d` lists them.
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'drift' }))?.props.hotkey).toBe('d')
      expect(await ui.find({ key: 'tiles-top' })).toBeUndefined()
      await ui.unmount()
    }
  })

  test('a wide Hubs tab lists the files most depended on beside the components, each opening as a file', async ($, on) => {
    const hubs = Array.from({ length: 12 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0, dependent_files: 40 - i, path: `src/Hub${i}.php`, line: 3 }))
    const fanIn = Array.from({ length: 6 }, (_, i) => ({ path: `src/Deep/File${i}.php`, dependent_files: 90 - i, boundaries: ['Core'], boundary: 'Core' }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs, hotspots: [], fan_in: fanIn }) }], file: [{ stdout: fileDetailOf('src/Deep/File0.php') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 200, scroll: { offset: 0, bodyRows: 40 } } })
      await ui.press({ key: 'tab:hubs' })
      const boxes = await ui.findAll({ type: 'Box' })
      // Wide, the list stands beside the marked row's detail: the files under the components, the detail framed beside them.
      expect(boxes.some(b => typeof b.key === 'string' && b.key.startsWith('files-head|'))).toBe(true)
      const head = boxes.find(b => typeof b.key === 'string' && b.key.startsWith('hub-head|'))
      expect(head?.text).toMatch(/in +out +cross +where +/)
      await ui.press({ key: 'row:12' })
      await w.clock.settle()
      expect(titled(drawn((await ui.find({ key: 'detail' }))?.text ?? ''))).toMatch(/Dependencies · used by/)
      await ui.press({ key: 'back' })
      await ui.unmount()
    }
  })

  test('wide, the marked row shows its detail beside the list: looked up after the marker settles, never on a narrower pane', async ($, on) => {
    const hubs = Array.from({ length: 6 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0, path: `src/Hub${i}.php`, line: 3 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs, hotspots: [] }) }], detail: [{ stdout: fullDetailOf('Hub1') }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      // Medium: the marker moves, nothing is looked up until a row is opened.
      const narrow = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 120, scroll: { offset: 0, bodyRows: 40 } } })
      await narrow.press({ key: 'tab:hubs' })
      const before = w.detailRuns().length
      await narrow.press({ key: 'down' })
      await w.clock.advance(500)
      await w.clock.settle()
      expect(w.detailRuns()).toHaveLength(before)
      expect((await narrow.findAll({ type: 'Box' })).some(b => typeof b.key === 'string' && b.key.includes('peek-'))).toBe(false)
      await narrow.unmount()
      // Wide: three quick moves are one lookup, of the row the marker rests on, drawn beside the list.
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 200, scroll: { offset: 0, bodyRows: 40 } } })
      await ui.press({ key: 'tab:overview' })
      await ui.press({ key: 'tab:hubs' })
      const start = w.detailRuns().length
      await ui.press({ key: 'down' })
      await ui.press({ key: 'down' })
      await ui.press({ key: 'up' })
      await w.clock.advance(300)
      await w.clock.settle()
      expect(w.detailRuns().slice(start).map(r => r.at(-1))).toEqual(['App\\Hub1'])
      const boxes = await ui.findAll({ type: 'Box' })
      expect(boxes.some(b => typeof b.key === 'string' && b.key.includes('|peek-detail-head'))).toBe(true)
      // Still the tab, not a detail: the way back is not offered; a press in the panel opens its row as the detail.
      expect(await ui.find({ key: 'back' })).toBeUndefined()
      await ui.press({ key: 'peek:0' })
      await w.clock.settle()
      expect(await ui.find({ key: 'back' })).toBeDefined()
      await ui.press({ key: 'back' })
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('a closed pane is no longer wide: a new graph looks nothing up beside a tab no one sees', async ($, on) => {
    const hubs = Array.from({ length: 6 }, (_, i) => ({ name: `Hub${i}`, canonical_name: `App\\Hub${i}`, kind: 'class', in_degree: 300 - i, out_degree: i, cross_boundary_degree: 0, path: `src/Hub${i}.php`, line: 3 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs, hotspots: [] }) }, { stdout: paneDashboard({ hubs, hotspots: [], snapshot_id: 's2' }) }], detail: [{ stdout: fullDetailOf('Hub0') }], brief: [{ stdout: brief() }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 200, scroll: { offset: 0, bodyRows: 40 } } })
    await ui.press({ key: 'tab:hubs' })
    await w.clock.advance(300)
    await w.clock.settle()
    const looked = w.detailRuns().length
    expect(looked).toBeGreaterThan(0)
    await ui.unmount()
    // A tick sees it closed.
    await w.clock.advance(2_000)
    await w.clock.settle()
    // A turn brings a new graph: the pane is closed, so nothing is looked up for it.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.advance(2_000)
    await w.clock.settle()
    expect(w.dashboardRuns().length).toBeGreaterThan(1)
    expect(w.detailRuns()).toHaveLength(looked)
  })

  test('health over time is one row per figure on one axis, as text on every surface; the tiles carry a sparkline', async ($, on) => {
    const trend = [1, 3, 2, 2, 4, 3].map((cycles, i) => ({ snapshot_id: `s${i}`, cycles, max_degree: 10, dead_code: 9 - i, diagnostics: 0 }))
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ trend }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      for (const bodyRows of [24, 80]) {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 100, scroll: { offset: 0, bodyRows } } })
        expect(titled((await ui.find({ key: 'health-head' }))?.text)).toMatch(/^Health over time +6 snapshots$/)
        expect((await ui.find({ key: 'health-0' }))?.text).toMatch(/│ cycles +[▁-█]+ +3 +1–4 /)
        expect((await ui.find({ key: 'health-1' }))?.text).toMatch(/│ gate unreferenced +[▁-█]+ +4 +4–9 /)
        // The gate's count is named apart from the dead-code tile, which counts the listed candidates.
        expect((await ui.find({ key: 'health-said' }))?.text).toContain("gate unreferenced: the quality gate's count, wider than the dead-code list")
        expect((await ui.find({ key: 'health-3' }))?.text).toMatch(/│ diagnostics +▁+ +0 +no change /)
        expect(await ui.find({ type: 'Raster' })).toBeUndefined()
        expect((await ui.find({ key: 'tiles-0-value' }))?.text).toMatch(/[▁-█]{5}/)
        await ui.unmount()
      }
    }
  })

  test('this session: the way to Changes is marked first, t copies the test command, and o opens Changes', async ($, on) => {
    const covered = brief({ tests: [{ path: 'hooks/lib/band.spec.ts', distance: 1, js_runner: 'vitest' }] })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], brief: [{ stdout: covered }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect(titled((await ui.find({ key: 'session-head' }))?.text)).toMatch(/^This session +nothing changed yet$/)
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect((await ui.find({ key: 'session-said' }))?.text).toContain('1 file · 41 dependents · 1 test reaches them')
    expect((await ui.find({ key: 'session-open' }))?.text).toMatch(/^› +Changes/)
    expect((await ui.find({ key: 'tests' }))?.props.hotkey).toBe('t')
    // The way to Changes has no file: `e` is not offered.
    expect(await ui.find({ key: 'edit' })).toBeUndefined()
    await ui.press({ key: 'tests' })
    await w.clock.settle()
    expect(w.copies.at(-1)?.text).toBe('npx vitest run hooks/lib/band.spec.ts')
    await ui.press({ key: 'open' })
    expect((await ui.find({ key: 'tab:changes-bg' }))?.props.backgroundColor).toBe('selectionBg')
    expect((await ui.find({ key: 'change-0' }))?.text).toMatch(/^› .*src\/Router\.php/)
    await ui.press({ key: 'tab:overview' })
    await ui.unmount()
  })

  test('this session warns when no test reaches the changes', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const again = await mountPane($)
    expect((await again.find({ key: 'session-said' }))?.text).toContain('▲ no test reaches them')
    expect(await again.find({ key: 'tests' })).toBeUndefined()
    await again.unmount()
  })
})

describe('the live watcher', () => {
  const READY = { event: 'ready', project_id: 'p1', snapshot_id: 's1', files: 3, scanned: false }

  test('starts once a dashboard of a scanned project is stored, and the header says live', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.watcher.starts).toHaveLength(1)
    expect(w.watcher.starts[0]?.slice(2)).toEqual(['watch', ROOT, '--poll-ms=1000'])
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● live · \d+s $/)
    await ui.unmount()
    // Its own ready names the snapshot the dashboard already has: no second load.
    expect(w.dashboardRuns()).toHaveLength(1)
  })

  test('says scanning while it scans, then reloads the dashboard once for the new snapshot', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    w.watchSend({ event: 'changes', changes: 1 })
    w.watchSend({ event: 'scan_started', mode: 'incremental', changes: 1 })
    await w.clock.advance(100)
    expect((await ui.find({ key: 'title' }))?.text).toContain('scanning…')
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    expect(w.dashboardRuns()).toHaveLength(2)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● live · \d+s $/)
    await ui.unmount()
  })

  test('another session leading: this one follows, says so, and reloads when that session scans', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[{ event: 'following', owner_pid: 7, stale: false, snapshot_id: 's1' }]] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● following · \d+s $/)
    w.watchSend({ event: 'snapshot', snapshot_id: 's9' })
    await w.clock.advance(100)
    expect(w.dashboardRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test('is not started when switched off', { options: { watch: false } }, async ($, on) => {
    const w = world(on, { watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.watcher.starts).toHaveLength(0)
  })

  test('takes its poll interval from the options', { options: { watchPollMs: 2500 } }, async ($, on) => {
    const w = world(on, { watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.watcher.starts[0]?.at(-1)).toBe('--poll-ms=2500')
  })

  test('ends with the session: the child is stopped, never left behind', async ($, on) => {
    const w = world(on, { watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.watcher.ended).toBe(0)
    await $.session.end({ reason: 'other', sessionId: 'x', resume: { id: 'x' } } as never)
    // Stopped at once, or at the latest when its next line (a heartbeat) reaches a loop that has let go.
    await w.clock.advance(15_100)
    expect(w.watcher.ended).toBe(1)
    // And is not started again by a later dashboard.
    await w.clock.advance(120_000)
    expect(w.watcher.starts).toHaveLength(1)
  })

  test('after a /clear the same project is watched again', async ($, on) => {
    const w = world(on, { watch: [[READY], [READY]] })
    await $.session.start(START)
    await w.clock.settle()
    await $.session.end({ reason: 'clear', sessionId: 'x', resume: { id: 'x' } } as never)
    await w.clock.advance(15_100)
    expect(w.watcher.ended).toBe(1)
    expect(w.watcher.starts).toHaveLength(2)
  })

  test('a watcher that never says a word is not offered here and is not started again', async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await w.clock.advance(200_000)
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.advance(5_000)
    expect(w.watcher.starts).toHaveLength(1)
  })

  test('a refused watcher stays off until a root is allowed', async ($, on) => {
    const w = world(on, { watch: [[{ event: 'refused', status: 'not-allowed' }], [READY]] })
    await $.session.start(START)
    await w.clock.settle()
    await w.clock.advance(200_000)
    expect(w.watcher.starts).toHaveLength(1)
  })

  test('a watcher that ran and then ended on its own is started again after a pause', async ($, on) => {
    const w = world(on, { watch: [[READY], [READY]] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchStop()
    await w.clock.advance(100)
    expect(w.watcher.starts).toHaveLength(1)
    await w.clock.advance(31_000)
    expect(w.watcher.starts).toHaveLength(2)
  })

  test("a turn's brief waits for the watcher, then names the turn's start and reuses its scan", async ($, on) => {
    const w = world(on, { watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    w.watchSend({ event: 'changes', changes: 1 })
    await w.clock.advance(100)
    await $.turn.complete(TURN)
    await w.clock.advance(500)
    // The watcher still holds the change: the brief waits.
    expect(w.briefRuns()).toHaveLength(0)
    w.watchSend({ event: 'scan_started', mode: 'incremental', changes: 1 })
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(3_000)
    expect(w.briefRuns()).toHaveLength(1)
    const args = w.briefRuns()[0]!
    // s1: the snapshot before the turn's edit, though the watcher has since moved the graph to s2.
    expect(args).toContain('--since=s1')
    expect(args).toContain('--reuse-scan')
    expect(args).toContain('--files=src/Router.php')
  })

  test("without a watcher the brief scans as before: no --reuse-scan", { options: { watch: false } }, async ($, on) => {
    const w = world(on)
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[0]).toContain('--since=s1')
    expect(w.briefRuns()[0]).not.toContain('--reuse-scan')
  })

  test('a brief that did not land keeps the turn\'s start for the next one', { options: { watch: false } }, async ($, on) => {
    const later = JSON.stringify({ ...(JSON.parse(dashboard) as object), snapshot_id: 's3' })
    const w = world(on, { brief: [{ stdout: '' }, { stdout: brief({ snapshot_id: 's3' }) }], dashboard: [{ stdout: dashboard }, { stdout: later }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[1]).toContain('--since=s1')
    // Once one lands, the next turn starts from the snapshot that brief reported.
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()[2]).toContain('--since=s3')
  })
  test('an in-process /resume stops the watcher, stops saying live, scans without it, and then watches again', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[{ ...READY, pid: 4242 }], [READY]] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● live · \d+s $/)
    await $.session.end({ reason: 'resume', sessionId: 'x', resume: { id: 'x' } } as never)
    await w.clock.settle()
    // Nothing watches now: the header says so at once, and a brief in the gap scans for itself.
    expect((await ui.find({ key: 'title' }))?.text).not.toContain('live')
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(1)
    expect(w.briefRuns()[0]).not.toContain('--reuse-scan')
    // The resumed session is watched again, from the next tick.
    await w.clock.advance(1_100)
    expect(w.watcher.starts).toHaveLength(2)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● live · \d+s $/)
    await ui.unmount()
  })

  for (const reason of ['prompt_input_exit', 'logout', 'other'] as const) {
    test(`after the session ends for good (${reason}) no watcher starts again and the header says nothing is live`, async ($, on) => {
      const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY], [READY]] })
      await $.session.start(START)
      await w.clock.settle()
      const ui = await mountPane($)
      await $.session.end({ reason, sessionId: 'x', resume: { id: 'x' } } as never)
      await w.clock.advance(60_000)
      expect(w.watcher.starts).toHaveLength(1)
      expect((await ui.find({ key: 'title' }))?.text).not.toContain('live')
      await ui.unmount()
    })
  }

  test('a session end signals the watcher it named at once, so its lock is free for the next session', async ($, on) => {
    const w = world(on, { watch: [[{ ...READY, pid: 4242 }], [READY]] })
    await $.session.start(START)
    await w.clock.settle()
    await $.session.end({ reason: 'clear', sessionId: 'x', resume: { id: 'x' } } as never)
    await w.clock.settle()
    expect(w.kills()).toEqual([['kill', '-TERM', '4242']])
  })

  test("following this process's own earlier watcher is not called another session", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[{ event: 'following', owner_pid: 7, stale: false, same_process: true, snapshot_id: 's1' }]] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/● live · \d+s $/)
    await ui.unmount()
  })

  test("a leader that stopped answering is said plainly while following it", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[{ event: 'following', owner_pid: 7, stale: false, snapshot_id: 's1' }]] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await mountPane($)
    w.watchSend({ event: 'following', owner_pid: 7, stale: true, snapshot_id: 's1' })
    await w.clock.advance(100)
    expect((await ui.find({ key: 'title' }))?.text).toContain("another session's watcher is stuck")
    await ui.unmount()
  })

  test("an edit outside the project, or a turn with nothing to scan, leaves no turn start behind", async ($, on) => {
    const later = JSON.stringify({ ...(JSON.parse(dashboard) as object), snapshot_id: 's2' })
    const w = world(on, { dashboard: [{ stdout: dashboard }, { stdout: later }], watch: [[READY]] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, '/elsewhere/notes.md')
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.briefRuns()).toHaveLength(0)
    // Another writer moves the graph between the turns.
    w.watchSend({ event: 'snapshot', snapshot_id: 's2' })
    await w.clock.advance(100)
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.advance(3_000)
    expect(w.briefRuns()).toHaveLength(1)
    expect(w.briefRuns()[0]).toContain('--since=s2')
  })

  const LEDGER = JSON.stringify({
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's2',
    since: 's1',
    complete: true,
    files: {
      'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'Http' },
      'src/Config/app.php': { status: 'added', dependents: 0, boundaries: [], boundary: null },
    },
    files_truncated: false,
    tests: [{ path: 'tests/RouterTest.php', distance: 1 }],
    tests_truncated: false,
  })

  test('the Changes tab lists every change since the session began, each labelled this session or outside', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }] })
    await $.session.start(START)
    await w.clock.settle()
    // A subagent's edit is the session's own as much as the main loop's.
    await $.tool.call({ tool: 'Edit', file_path: `${ROOT}/src/Router.php`, old_string: 'a', new_string: 'b', agentId: 'sub-1' } as never)
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 2 })
    await w.clock.advance(100)
    expect(w.ledgerRuns().at(-1)).toContain('--since=s1')
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const rows = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('\n')
    expect(rows).toContain('since it began')
    expect((await ui.find({ key: 'change-0' }))?.text).toMatch(/Router\.php.*this session/)
    expect((await ui.find({ key: 'change-1' }))?.text).toMatch(/app\.php.*outside/)
    expect(rows).not.toContain('No live watcher')
    await ui.unmount()
  })

  test('without a watcher the Changes tab falls back to the turns and says so', { options: { watch: false } }, async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], ledger: [{ stdout: LEDGER }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    // A note's figures and words are separate runs: read them as one line.
    const rows = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('')
    expect(rows).toContain('No live watcher')
    expect(rows).toContain('1 turn')
    expect(rows).not.toContain('outside')
    expect(w.ledgerRuns()).toEqual([])
    await ui.unmount()
  })

  test("a scan record that does not reach back to the session's start says so with the date, and lists every change since the oldest point it holds", async ($, on) => {
    const reached = JSON.stringify({ ...(JSON.parse(LEDGER) as object), complete: false, reached: { snapshot_id: 's1b', at: 1_791_100_000 } })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: reached }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 2 })
    await w.clock.advance(100)
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const rows = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('').replace(/\s+/g, ' ')
    const date = String.raw`\d{4}-\d{2}-\d{2} \d{2}:\d{2}`
    expect(rows).toMatch(new RegExp(`The scan record does not reach back to when this session began \\(${date}\\)\\. It lists every change since ${date}, the oldest point it still holds\\.`))
    expect(rows).toMatch(new RegExp(`since ${date}`))
    // The files since that point are listed, each with its origin, instead of nothing.
    expect((await ui.find({ key: 'change-0' }))?.text).toMatch(/Router\.php/)
    expect((await ui.find({ key: 'change-1' }))?.text).toMatch(/app\.php.*outside/)
    await ui.unmount()
  })

  test("a scan record from an older knossos that names no point it reaches falls back to the turns, with the session's start date", async ($, on) => {
    const older = JSON.stringify({ ...(JSON.parse(LEDGER) as object), complete: false, files: {}, tests: [] })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: older }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 2 })
    await w.clock.advance(100)
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const rows = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('').replace(/\s+/g, ' ')
    expect(rows).toMatch(/The scan record does not reach back to when this session began \(\d{4}-\d{2}-\d{2} \d{2}:\d{2}\), so only what its turns reported is listed\./)
    await ui.unmount()
  })

  test('a session that began among merged scans lists what changed after it and says its start is approximate', async ($, on) => {
    const merged = JSON.stringify({ ...(JSON.parse(LEDGER) as object), start_approximate: true })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: merged }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 2 })
    await w.clock.advance(100)
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const rows = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('').replace(/\s+/g, ' ')
    expect(rows).toContain('its start is approximate')
    expect(rows).toContain('since it began')
    expect((await ui.find({ key: 'change-0' }))?.text).toMatch(/Router\.php/)
    await ui.unmount()
  })

  /** The ledger since s1: Router changed by the scan that made s2, app.php by the one that made s3. */
  const SCANNED = (over: Record<string, unknown> = {}) =>
    JSON.stringify({
      ...(JSON.parse(LEDGER) as object),
      snapshot_id: 's3',
      files: {
        'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'Http', scans: ['s2'] },
        'src/Config/app.php': { status: 'added', dependents: 0, boundaries: [], boundary: null, scans: ['s3'] },
      },
      ...over,
    })

  /** The watcher noticing changes and scanning them into `snapshot`, `ms` apart. */
  async function watcherScans(w: ReturnType<typeof world>, snapshot: string, ms = 100) {
    w.watchSend({ event: 'changes', count: 1 })
    w.watchSend({ event: 'scan_started', mode: 'incremental', changes: 1 })
    await w.clock.advance(ms)
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: snapshot, parsed_files: 1 })
    await w.clock.advance(100)
  }

  /** The Changes tab's row for each file, as drawn. */
  async function changeRows($: Engine) {
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const rows = [(await ui.find({ key: 'change-0' }))?.text ?? '', (await ui.find({ key: 'change-1' }))?.text ?? '']
    await ui.unmount()
    return rows
  }

  test("a subagent's shell edit the watcher scans while the command runs is this session's, and one scanned while it is idle is outside", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: SCANNED() }], bashMs: 3_000 })
    await $.session.start(START)
    await w.clock.settle()
    // A subagent rewrites a file with sed: no edit tool, so only the activity says whose it was.
    const call = $.tool.call({ tool: 'Bash', command: "sed -i 's/a/b/' src/Router.php", agentId: 'sub-1' } as never)
    await w.clock.advance(1_000)
    await watcherScans(w, 's2')
    await w.clock.advance(2_000)
    await call
    // Later, nothing of the session's running: the person's editor.
    await w.clock.advance(20_000)
    await watcherScans(w, 's3')
    const [router, app] = await changeRows($)
    expect(router).toMatch(/Router\.php.*this session/)
    expect(app).toMatch(/app\.php.*outside/)
  })

  test("a change the watcher notices just after the session's command ended is still the session's; one seconds later is not", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: SCANNED() }] })
    await $.session.start(START)
    await w.clock.settle()
    // The main loop's formatter writes the file and ends; the watcher's poll and debounce come after.
    await bash($, 'npx prettier --write src/Router.php')
    await w.clock.advance(1_200)
    await watcherScans(w, 's2')
    // Five seconds after anything of the session's ran.
    await w.clock.advance(5_000)
    await watcherScans(w, 's3')
    const [router, app] = await changeRows($)
    expect(router).toMatch(/this session/)
    expect(app).toMatch(/outside/)
  })

  test('a call that only waits (a subagent running, a question to the person) does not make what changes meanwhile the session\'s', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: SCANNED() }] })
    on('tool.call', { tool: 'AskUserQuestion' }, async () => {
      await w.clock.sleep(30_000)
      return { result: {} as never }
    })
    await $.session.start(START)
    await w.clock.settle()
    const asking = $.tool.call({ tool: 'AskUserQuestion', questions: [] } as never)
    await w.clock.advance(10_000)
    // The person edits in their editor while the question waits.
    await watcherScans(w, 's2')
    await w.clock.advance(20_000)
    await asking
    const [router] = await changeRows($)
    expect(router).toMatch(/outside/)
  })

  test("while following another session's watcher, a scan its leader began during the session's command is the session's", async ($, on) => {
    const FOLLOWING = { event: 'following', owner_pid: 7, stale: false, same_process: false, snapshot_id: 's1' }
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[FOLLOWING]], ledger: [{ stdout: SCANNED() }], bashMs: 500 })
    await $.session.start(START)
    await w.clock.settle()
    const call = bash($, "sed -i 's/a/b/' src/Router.php")
    await w.clock.advance(1_000)
    await call
    // The leader's scan, seen in its lock within a poll, lands a few seconds later.
    w.watchSend({ event: 'leader_scanning' })
    await w.clock.advance(4_000)
    w.watchSend({ event: 'snapshot', snapshot_id: 's2' })
    await w.clock.advance(100)
    // Later, with the session idle, another writer's scan lands unannounced.
    await w.clock.advance(20_000)
    w.watchSend({ event: 'snapshot', snapshot_id: 's3' })
    await w.clock.advance(100)
    const [router, app] = await changeRows($)
    expect(router).toMatch(/this session/)
    expect(app).toMatch(/outside/)
  })

  test("a turn brief that scanned the turn's edits itself makes its scan the session's", async ($, on) => {
    const scanned = brief({ snapshot_id: 's3', scanned: true })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: SCANNED() }], brief: [{ stdout: scanned }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/Router.php`)
    await $.turn.complete(TURN)
    await w.clock.advance(25_000)
    expect(w.briefRuns()).toHaveLength(1)
    // app.php was changed by the brief's own scan, not by an edit tool.
    const [, app] = await changeRows($)
    expect(app).toMatch(/app\.php.*this session/)
  })

  const REV = '0123456789abcdef0123456789abcdef01234567'
  const HEAD = JSON.stringify({ status: 'ok', path: ROOT, rev: REV })
  const ROUTER_DIFF = JSON.stringify({
    status: 'ok',
    path: ROOT,
    rev: REV,
    file: 'src/Router.php',
    kind: 'changed',
    from: null,
    to: null,
    binary: false,
    diff: '@@ -3,3 +3,4 @@ final class Router\n {\n-    // old\n+    // new\n+    // more\n }\n',
    lines: 6,
    truncated: false,
    unreadable: false,
  })

  for (const surface of ['terminal', 'desktop'] as const) {
    test(`a changed file opened from Changes shows its change since the session began as the engine's own diff (${surface})`, async ($, on) => {
      const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: ROUTER_DIFF }] })
      await $.session.start(START)
      await w.clock.settle()
      // The commit is read once, at the start, before anything else.
      expect(w.headRuns()).toEqual([['sh', expect.stringMatching(/knossos-run\.sh$/), 'session-head', ROOT]])
      expect(w.calls[0]?.[2]).toBe('session-head')
      w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
      await w.clock.advance(100)
      const ui = await mountPane($, surface)
      await ui.press({ key: 'tab:changes' })
      await ui.press({ key: 'open' })
      // Loading first: the read runs on a timer, never in the render.
      expect(w.diffRuns()).toEqual([])
      expect((await ui.findAll({ type: 'Text' })).map(t => t.text).join('\n')).toContain('Reading the change')
      await w.clock.settle()
      expect(w.diffRuns()).toEqual([['sh', expect.stringMatching(/knossos-run\.sh$/), 'session-diff', ROOT, `--rev=${REV}`, '--file=src/Router.php']])
      const code = await ui.find({ type: 'Code' })
      expect(code?.props).toMatchObject({ format: 'diff', path: 'src/Router.php', wrap: 'truncate-end' })
      expect(code?.props.source).toBe('@@ -3,3 +3,4 @@ final class Router\n {\n-    // old\n+    // new\n+    // more\n }')
      const text = (await ui.findAll({ type: 'Text' })).map(t => t.text).join('\n')
      expect(text).toContain('Changed since the session began')
      expect(text).toMatch(/\+2[\s\S]*−1/)
      // Below the files that depend on it.
      const keys = (await ui.findAll({})).map(e => e.key).filter((k): k is string => k !== undefined)
      expect(keys.indexOf('diff-head')).toBeGreaterThan(keys.indexOf('deps-head'))
      // Read once for this graph: going back and opening it again reads nothing new.
      await ui.press({ key: 'back' })
      await ui.press({ key: 'row:0' })
      await w.clock.settle()
      expect(w.diffRuns()).toHaveLength(1)
      expect(await ui.find({ type: 'Code' })).toBeDefined()
      await ui.unmount()
    })
  }

  /** As much as session-diff sends: 200,000 bytes in 14 hunks of 70 lines, each line as long as a hunk keeps. */
  const FULL_DIFF = JSON.stringify({
    ...(JSON.parse(ROUTER_DIFF) as object),
    diff: `${Array.from({ length: 14 }, (_, h) => `@@ -${h * 100 + 1},0 +${h * 100 + 1},70 @@\n${Array.from({ length: 70 }, (_, i) => `+${`h${h} line ${i} `.padEnd(199, 'x')}`).join('\n')}`).join('\n')}\n`,
    lines: 994,
    truncated: true,
  })

  test('the detail of a file changed as much as session-diff can say stays within the bounds the engine sets every tree, at every size', { timeoutMs: 120_000 }, async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: FULL_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    for (const surface of ['terminal', 'desktop'] as const) {
      for (const [bodyColumns, bodyRows] of [[200, 60], [140, 120], [100, 60], [60, 60]] as const) {
        const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns, scroll: { offset: 0, bodyRows } } })
        await ui.press({ key: 'tab:changes' })
        await ui.press({ key: 'open' })
        await w.clock.settle()
        const size = treeSize(await ui.drawn())
        const at = `${surface} ${bodyColumns}x${bodyRows}: ${JSON.stringify(size)}`
        expect(await ui.find({ type: 'Code' }), at).toBeDefined()
        expect(size.nodes, at).toBeLessThan(10_000)
        expect(size.depth, at).toBeLessThan(16)
        expect(size.chars, at).toBeLessThan(72_000)
        await ui.press({ key: 'back' })
        await ui.unmount()
      }
    }
    // Never so large that it fell back to one line.
    expect(w.logs.filter(l => l.text.includes('too large to draw'))).toEqual([])
  })

  test('a full-size diff opens a hunk on its more-lines press and pages on its more-changes press, within the bounds the engine sets every tree', { timeoutMs: 120_000 }, async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: FULL_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 120, scroll: { offset: 0, bodyRows: 60 } } })
    // A hunk's element, by where FULL_DIFF starts it: hunk h at line h * 100 + 1.
    const hunk = async (h: number) => (await ui.findAll({ type: 'Code' })).map(c => c.props.source as string).find(source => source.startsWith(`@@ -${h * 100 + 1},`))
    const lines = async (h: number) => (await hunk(h))?.split('\n').length
    const within = async (at: string) => {
      const size = treeSize(await ui.drawn())
      expect(size.nodes, at).toBeLessThan(10_000)
      expect(size.depth, at).toBeLessThan(16)
      expect(size.chars, at).toBeLessThan(72_000)
    }
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    // Folded at 40 lines, under it a press, never keyed as its row is.
    expect(await lines(0)).toBe(41)
    expect((await ui.find({ key: 'diff-more:0' }))?.type).toBe('Button')
    expect(await ui.find({ key: 'diff-more-0' })).toBeDefined()
    await ui.press({ key: 'diff-more:0' })
    // Opened as far as the element takes: the rest is the file's to show, and the tree stays within its bounds.
    expect(await lines(0)).toBeGreaterThan(41)
    expect(await ui.find({ key: 'diff-more:0' })).toBeUndefined()
    expect((await ui.find({ key: 'diff-more-0' }))?.text).toMatch(/too long to draw here, e opens the file/)
    await within('opened')
    // The hunks past the page: a press that shows them, and one back.
    const next = (await ui.findAll({ type: 'Button' })).map(b => b.key).find(k => k?.startsWith('diff-from:'))
    expect(next).toMatch(/^diff-from:[1-9]\d*$/)
    await ui.press({ key: next! })
    expect(await hunk(Number(next!.slice('diff-from:'.length)))).toBeDefined()
    expect(await hunk(0)).toBeUndefined()
    expect(await ui.find({ key: 'diff-from:0' })).toBeDefined()
    await within('paged')
    // Opened anew, the detail shows its diff closed.
    await ui.press({ key: 'back' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(await lines(0)).toBe(41)
    expect(await ui.find({ key: 'diff-more:0' })).toBeDefined()
    await ui.unmount()
    expect(w.logs.filter(l => l.text.includes('too large to draw'))).toEqual([])
  })

  test('wide, the diff beside Changes ends in a press that opens the full detail, where the diff is the engine\'s own', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: ROUTER_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 200, scroll: { offset: 0, bodyRows: 40 } } })
      await ui.press({ key: 'tab:changes' })
      await w.clock.advance(500)
      await w.clock.settle()
      // Beside the list the diff is text, with no element of its own.
      expect(await ui.find({ type: 'Code' })).toBeUndefined()
      expect((await ui.find({ key: 'diff-open' }))?.props.label).toBe('open the full diff')
      await ui.press({ key: 'diff-open' })
      await w.clock.settle()
      expect((await ui.find({ type: 'Code' }))?.props.source).toMatch(/^@@ -3,3 \+3,4 @@/)
      await ui.press({ key: 'back' })
      await ui.unmount()
    }
  })

  test('a pane too large to draw at its fewest rows draws one line saying so, and logs it once', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    // So wide that the rows the pane cannot give back outweigh the budget on their own.
    for (let i = 0; i < 2; i++) {
      const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 9_000, scroll: { offset: 0, bodyRows: 60 } } })
      expect(treeSize(await ui.drawn()).chars).toBeLessThan(100_000)
      expect((await ui.findAll({ type: 'Text' })).map(t => t.text).join('\n')).toContain('Too large to draw here')
      await ui.unmount()
    }
    expect(w.logs.filter(l => l.text.includes('too large to draw'))).toHaveLength(1)
  })

  test('a session continued in a new process keeps the commit and the snapshot it began at; a new one starts fresh', async ($, on) => {
    const OLD = 'fedcba9876543210fedcba9876543210fedcba98'
    const kept = { sessionBaselines: { 'session-1': { rev: { status: 'ok', rev: OLD }, snapshot: 's0', startedAt: 5 } } }
    const since = LEDGER.replace('"since":"s1"', '"since":"s0"')
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: since }], head: [{ stdout: HEAD }], diff: [{ stdout: ROUTER_DIFF }], store: kept })
    await $.session.start(START)
    await w.clock.settle()
    // Its commit is known: the one read is the header's, of where the checkout stands, and its changes are read since the snapshot it first saw.
    expect(w.headRuns()).toHaveLength(1)
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    expect(w.ledgerRuns().at(-1)).toContain('--since=s0')
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect(w.diffRuns().at(-1)).toContain(`--rev=${OLD}`)
    await ui.unmount()
    // Its first start is kept as it was.
    expect(w.store.get('sessionBaselines')).toEqual(kept.sessionBaselines)
  })

  test('a new session records where it began under its own id, and a /clear starts another', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], head: [{ stdout: HEAD }], sessionId: 'session-2', store: { sessionBaselines: { 'session-1': { rev: null, snapshot: 's9', startedAt: 1 } } } })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.headRuns()).toHaveLength(1)
    const stored = w.store.get('sessionBaselines') as Record<string, { rev: unknown; snapshot: unknown }>
    expect(stored['session-2']).toMatchObject({ rev: { status: 'ok', rev: REV }, snapshot: 's1' })
    // The other session's is left alone.
    expect(stored['session-1']).toMatchObject({ snapshot: 's9' })
  })

  test('a file opened anywhere but the session changes shows no diff and reads none', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], head: [{ stdout: HEAD }], diff: [{ stdout: ROUTER_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect src/Router.php')
    await w.clock.settle()
    const ui = await mountPane($)
    expect(await ui.find({ key: 'diff-head' })).toBeUndefined()
    expect(w.diffRuns()).toEqual([])
    await ui.unmount()
  })

  for (const [head, says] of [
    [JSON.stringify({ status: 'no-git', path: ROOT, rev: null }), 'not in a git repository'],
    ['', 'was not recorded'],
  ] as const) {
    test(`without the session's commit the detail says why instead of a diff (${says})`, async ($, on) => {
      const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: head }], diff: [{ stdout: ROUTER_DIFF }] })
      await $.session.start(START)
      await w.clock.settle()
      w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
      await w.clock.advance(100)
      const ui = await mountPane($)
      await ui.press({ key: 'tab:changes' })
      await ui.press({ key: 'open' })
      await w.clock.settle()
      expect((await ui.findAll({ type: 'Text' })).map(t => t.text.trim()).join(' ')).toContain(says)
      expect(await ui.find({ type: 'Code' })).toBeUndefined()
      expect(w.diffRuns()).toEqual([])
      // A later read would name a commit made during the session: the start's silence stands.
      expect(w.headRuns()).toHaveLength(1)
      await ui.unmount()
    })
  }

  test("a deleted file's detail still shows its change, whole as removed, and a new snapshot reads it again", async ($, on) => {
    const gone = JSON.stringify({ ...(JSON.parse(ROUTER_DIFF) as object), kind: 'deleted', diff: '@@ -1,2 +0,0 @@\n-<?php\n-final class Router {}\n' })
    const notFound = JSON.stringify({ status: 'not-found', path: `${ROOT}/src/Router.php`, file: null })
    const later = paneDashboard({ snapshot_id: 's3' })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ snapshot_id: 's2' }) }, { stdout: later }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: gone }], file: [{ stdout: notFound }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect((await ui.find({ type: 'Code' }))?.props.source).toBe('@@ -1,2 +0,0 @@\n-<?php\n-final class Router {}')
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's3', parsed_files: 1 })
    await w.clock.advance(100)
    await w.clock.settle()
    expect(w.diffRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test('a hunk opened in the diff folds again once the diff is read at a new snapshot', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ snapshot_id: 's2' }) }, { stdout: paneDashboard({ snapshot_id: 's3' }) }], watch: [[READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }], diff: [{ stdout: FULL_DIFF }, { stdout: FULL_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 120, scroll: { offset: 0, bodyRows: 60 } } })
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    await ui.press({ key: 'diff-more:0' })
    expect(await ui.find({ key: 'diff-more:0' })).toBeUndefined()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's3', parsed_files: 1 })
    await w.clock.advance(100)
    await w.clock.settle()
    expect(w.diffRuns()).toHaveLength(2)
    // The same file, read again: folded as a diff first shows.
    expect(await ui.find({ key: 'diff-more:0' })).toBeDefined()
    await ui.unmount()
  })

  test('a /clear drops how far the diff was opened', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }, { stdout: paneDashboard({ snapshot_id: 's2' }) }], watch: [[READY], [READY]], ledger: [{ stdout: LEDGER }], head: [{ stdout: HEAD }, { stdout: HEAD }], diff: [{ stdout: FULL_DIFF }, { stdout: FULL_DIFF }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    const ui = await $.ui.mount({ plugin: 'knossos', surface: 'terminal', component: 'Pane', requestId: 'knossos', props: { ...PANE_PROPS, bodyColumns: 120, scroll: { offset: 0, bodyRows: 60 } } })
    await ui.press({ key: 'tab:changes' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    await ui.press({ key: 'diff-more:0' })
    expect(await ui.find({ key: 'diff-more:0' })).toBeUndefined()
    await $.session.end({ reason: 'clear', sessionId: 'session-1', resume: { id: 'session-1' } } as never)
    w.switchSession('session-2')
    await w.clock.settle()
    // The detail still on show reads its diff again for the new session, against the same commit and
    // snapshot, so only the /clear can have folded it; until then it says it is reading the change.
    expect(w.diffRuns()).toHaveLength(2)
    expect(await ui.find({ key: 'diff-more:0' })).toBeDefined()
    await ui.unmount()
  })

  test('after a /clear the commit the new session begins at is read again', async ($, on) => {
    const w = world(on, { watch: [[READY], [READY]], head: [{ stdout: HEAD }] })
    await $.session.start(START)
    await w.clock.settle()
    await $.session.end({ reason: 'clear', sessionId: 'session-1', resume: { id: 'session-1' } } as never)
    w.switchSession('session-2')
    await w.clock.settle()
    expect(w.headRuns()).toHaveLength(2)
    expect(Object.keys(w.store.get('sessionBaselines') as object).sort()).toEqual(['session-1', 'session-2'])
    // Resuming the first session in this process reads its own baseline back: no head is read for it.
    await $.session.end({ reason: 'resume', sessionId: 'session-2', resume: { id: 'session-1' } } as never)
    w.switchSession('session-1')
    await w.clock.settle()
    expect(w.headRuns()).toHaveLength(2)
  })
})

describe("the pane's header, marked rows, hover cards and heat map cells", () => {
  const REV = '0123456789abcdef0123456789abcdef01234567'
  const WIDE = { ...PANE_PROPS, bodyColumns: 140, scroll: { offset: 0, bodyRows: 40 } }
  const wide = ($: Engine, surface: 'terminal' | 'desktop' | 'mobile' = 'terminal') => $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: WIDE })

  test('the title row names the branch and short commit read once at start-up, never in a render, and the status as a pill', async ($, on) => {
    const head = JSON.stringify({ status: 'ok', path: ROOT, rev: REV, branch: 'feat/pane' })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], head: [{ stdout: head }] })
    await $.session.start(START)
    await w.clock.settle()
    // One read: the baseline's, which the header shares.
    expect(w.headRuns()).toHaveLength(1)
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await wide($, surface)
      const title = (await ui.find({ key: 'title' }))?.text ?? ''
      expect(title).toMatch(/^ {2}repo {2}feat\/pane · 0123456 {3}PHP {3}TS +● fresh 1s $/)
      const pill = (await ui.findAll({ type: 'Text' })).find(t => t.text === ' ● fresh 1s ')
      expect(pill?.props).toMatchObject({ backgroundColor: 'success', color: 'inverseText', bold: true })
      await ui.unmount()
    }
    expect(w.headRuns()).toHaveLength(1)
    // A turn may commit or switch branches: its end reads where the checkout stands again, on a timer.
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.headRuns()).toHaveLength(2)
  })

  test('the header has room around it, and the footer is a bar at the bottom of the pane, over its last rows when the pane scrolls', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      // Tall enough for everything: blank rows above the name, between it and the tabs, and before the rule; the bar the last row of the pane.
      const tall = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...WIDE, scroll: { offset: 0, bodyRows: 80 } } })
      // On Hubs, whose list gives rows back to a short pane.
      await tall.press({ key: 'tab:hubs' })
      const rows = ((await tall.find({ key: 'pane' }))!.children as { key?: unknown; props?: { key?: unknown; position?: unknown } }[]).filter(c => c.props?.position !== 'absolute').map(c => String(c.key ?? c.props?.key))
      expect(rows.slice(0, 6)).toEqual(['head-top', 'title', 'head-gap', 'tabs', 'head-end', 'head-rule'])
      expect(rows).toHaveLength(80)
      expect(rows.at(-1)).toBe('keys')
      expect(await tall.find({ key: 'bar' })).toBeUndefined()
      await tall.unmount()
      // Shorter than what it draws: the bar is drawn over the window's last rows, and says the graph's state once the header is out of view.
      const short = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...WIDE, bodyColumns: 60, scroll: { offset: 0, bodyRows: 16 } } })
      // The lists shrink to the height; the key list does not.
      expect(await short.find({ key: 'bar' })).toBeUndefined()
      await short.press({ key: 'keys' })
      const bar = await short.find({ key: 'bar' })
      // Hubs offers more keys than fit one row at 60 columns: the bar is three rows.
      expect(bar?.props).toMatchObject({ position: 'absolute', top: 13 })
      expect(bar?.text).not.toContain('● fresh')
      await short.unmount()
      const scrolled = await $.ui.mount({ plugin: 'knossos', surface, component: 'Pane', requestId: 'knossos', props: { ...WIDE, bodyColumns: 60, scroll: { offset: 6, bodyRows: 16 } } })
      // The key list is still open: the view outlives the mount.
      // Its state joins the bar's last row.
      expect((await scrolled.find({ key: 'bar' }))?.props).toMatchObject({ position: 'absolute', top: 19 })
      expect((await scrolled.find({ key: 'bar' }))?.text).toContain('● fresh 1s')
      await scrolled.press({ key: 'keys' })
      await scrolled.unmount()
    }
  })

  test('without git the title row names no branch, and a detail turns it into the way back', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], head: [{ stdout: JSON.stringify({ status: 'no-git', path: ROOT, rev: null }) }], detail: [{ stdout: fullDetailOf('Router') }] })
    await $.session.start(START)
    await w.clock.settle()
    const ui = await wide($)
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/^ +repo +● fresh 1s $/)
    await ui.press({ key: 'tab:hubs' })
    await ui.press({ key: 'open' })
    await w.clock.settle()
    expect((await ui.find({ key: 'title' }))?.text).toMatch(/^ +repo › Hubs › Router +● fresh 1s $/)
    await ui.unmount()
  })

  test('the marked row is tinted across: its Texts on the tint, its pressable name in a Box of it', async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await wide($, surface)
      await ui.press({ key: 'tab:hubs' })
      expect((await ui.find({ key: 'row:0-bg' }))?.props.backgroundColor).toBe('userMessageBackground')
      expect(await ui.find({ key: 'row:1-bg' })).toBeUndefined()
      await ui.press({ key: 'down' })
      expect(await ui.find({ key: 'row:0-bg' })).toBeUndefined()
      expect((await ui.find({ key: 'row:1-bg' }))?.props.backgroundColor).toBe('userMessageBackground')
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
  })

  test('a hub or file row hangs a hover card: hidden, revealed by its group, out of the flow; none on mobile', async ($, on) => {
    const hubs = [{ name: 'Router', canonical_name: 'App\\Router', kind: 'class', boundary: 'Http', in_degree: 41, out_degree: 3, cross_boundary_degree: 2, dependent_files: 12, top_dependents: ['src/Kernel.php', 'src/Cli.php'] }]
    const w = world(on, { dashboard: [{ stdout: paneDashboard({ hubs, hotspots: [] }) }] })
    await $.session.start(START)
    await w.clock.settle()
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await wide($, surface)
      await ui.press({ key: 'tab:hubs' })
      const card = await ui.find({ key: 'card-hub-0' })
      expect(card?.props).toMatchObject({ position: 'absolute', display: 'none' })
      const tree = await ui.drawn()
      expect(nodeOf(tree, 'card-hub-0')?.hover).toEqual({ scope: 'knossos:card-hub-0', display: 'flex' })
      expect(card?.text).toContain('Router')
      expect(card?.text).toContain('12 files depend on it')
      expect(card?.text).toContain('← src/Kernel.php')
      // The row's own segments join its group, so the pointer anywhere on them shows it.
      expect(nodeOf(tree, 'row:0')?.hover).toEqual({ scope: 'knossos:card-hub-0' })
      // The file most depended on hangs one too.
      expect(await ui.find({ key: 'card-files-0' })).toBeDefined()
      await ui.press({ key: 'tab:overview' })
      await ui.unmount()
    }
    const mobile = await $.ui.mount({ plugin: 'knossos', surface: 'mobile', component: 'Pane', requestId: 'knossos', props: WIDE })
    await mobile.press({ key: 'tab:hubs' })
    expect(await mobile.find({ key: 'card-hub-0' })).toBeUndefined()
    expect(nodeOf(await mobile.drawn(), 'row:0')?.hover).toBeUndefined()
    await mobile.unmount()
  })

  test('the marked heat map cell is read on a timer, spelled out, and l steps it to the next dependency', async ($, on) => {
    const couplings = (from: string, to: string, edges: number) =>
      JSON.stringify({
        status: 'ok',
        from,
        to,
        snapshot_id: 's1',
        edges,
        couplings: [{ source: { name: 'route', canonical_name: 'App\\Http\\Router::route', kind: 'method' }, target: { name: 'Kernel', canonical_name: 'App\\Core\\Kernel', kind: 'class' }, edges: 9 }],
        truncated: false,
        truncation_reasons: [],
      })
    // Http depends on Core most, then on the cli module.
    const matrix = { ...(JSON.parse(boundariesDashboard()) as { boundary_matrix: object }).boundary_matrix, cells: [[120, 14, 5], [3, 80, 0], [6, 0, 9]] }
    const w = world(on, { dashboard: [{ stdout: boundariesDashboard({ boundary_matrix: matrix }) }], couplings: [{ stdout: couplings('Http', 'Core', 14) }, { stdout: couplings('Http', 'module:cli (+composer:app/cli)', 0) }] })
    await $.session.start(START)
    await w.clock.settle()
    // Narrow: the cards stack, each row its own.
    const ui = await mountPane($)
    await ui.press({ key: 'tab:boundaries' })
    // Http depends on Core most: that cell is marked and read, never inside the render.
    expect(titled((await ui.find({ key: 'coupling-head' }))?.text)).toMatch(/^Http → Core +14 deps$/)
    await w.clock.settle()
    expect(w.couplingRuns()).toEqual([['sh', expect.stringMatching(/knossos-run\.sh$/), 'boundary-couplings', ROOT, '--from=Http', '--to=Core']])
    expect((await ui.find({ key: 'coupling-0' }))?.text).toMatch(/Router::route +──► Kernel +9$/)
    // Read once for this cell of this graph.
    await ui.press({ key: 'keys' })
    await w.clock.settle()
    expect(w.couplingRuns()).toHaveLength(1)
    // `l` moves the cell to what Http depends on next, and reads that one.
    expect((await ui.find({ key: 'target' }))?.props.hotkey).toBe('l')
    await ui.press({ key: 'target' })
    await w.clock.settle()
    expect(w.couplingRuns().at(-1)?.slice(-2)).toEqual(['--from=Http', '--to=module:cli (+composer:app/cli)'])
    expect(titled((await ui.find({ key: 'coupling-head' }))?.text)).toMatch(/^Http → cli/)
    // A press on a dependency in the marked boundary's card moves the cell there.
    await ui.press({ key: 'cell:1' })
    await w.clock.settle()
    expect(titled((await ui.find({ key: 'coupling-head' }))?.text)).toMatch(/^Http → Core/)
    // Marking another boundary starts on what it depends on most.
    await ui.press({ key: 'down' })
    await w.clock.settle()
    expect(w.couplingRuns().at(-1)?.slice(-2)).toEqual(['--from=Core', '--to=Http'])
    expect(w.prompts).toEqual([])
    await ui.unmount()
  })

  test("the Changes tab draws the session's scans as a timeline over the files", async ($, on) => {
    const ledger = JSON.stringify({
      status: 'ok',
      since: 's1',
      snapshot_id: 's3',
      complete: true,
      files: { 'src/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http'], boundary: 'Http', scans: ['s2'] } },
      files_truncated: false,
      tests: [],
      tests_truncated: false,
      scans: [
        { snapshot_id: 's2', at: 10, files: 1 },
        { snapshot_id: 's3', at: 20, files: 1 },
      ],
      scans_truncated: false,
    })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], watch: [[{ event: 'ready', project_id: 'p1', snapshot_id: 's1', files: 3, scanned: false }]], ledger: [{ stdout: ledger }] })
    await $.session.start(START)
    await w.clock.settle()
    w.watchSend({ event: 'scan_completed', mode: 'incremental', snapshot_id: 's2', parsed_files: 1 })
    await w.clock.advance(100)
    await w.clock.settle()
    const ui = await mountPane($)
    await ui.press({ key: 'tab:changes' })
    const row = (await ui.find({ key: 'changes-timeline' }))?.text ?? ''
    expect(row).toMatch(/^ {3}scans ●● {2}\d this session/)
    await ui.unmount()
  })
})

describe('round 13: churn, blast radius, routes, notifications and notes', () => {
  const ringItem = (name: string, tested: boolean) => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 3, boundary: 'Core', tested })
  const RINGS = JSON.stringify({
    status: 'ok',
    component: { name: 'Greeter', canonical_name: 'App\\Greeter', kind: 'class', boundary: 'Core' },
    truncated: false,
    rings: [
      { hop: 1, count: 2, tested: 1, items: [ringItem('Caller', false), ringItem('Router', true)], tests: { count: 1, items: [{ path: 'tests/RouterTest.php', hop: 2 }] } },
      { hop: 2, count: 1, tested: 1, items: [ringItem('Kernel', true)], tests: { count: 1, items: [{ path: 'tests/KernelTest.php', hop: 3 }] } },
      { hop: 3, count: 0, tested: 0, items: [], tests: { count: 0, items: [] } },
    ],
  })

  test('the Churn tab reads the history while open, once per commit the checkout is at, and opens what it ranks', async ($, on) => {
    const churned = JSON.stringify({ status: 'ok', days: 30, head: 'a'.repeat(40), commits: 12, truncated: false, files: [{ path: 'src/Http/Router.php', commits: 9, dependents: 12, score: 108, boundary: 'Http' }, { path: 'src/Core/Kernel.php', commits: 3, dependents: 2, score: 6, boundary: 'Core' }] })
    const head = (rev: string) => ({ stdout: JSON.stringify({ status: 'ok', rev, branch: 'main' }) })
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }], churn: [{ stdout: churned }], head: [head('a'.repeat(40)), head('a'.repeat(40)), head('b'.repeat(40))], file: [{ stdout: fileDetailOf('src/Http/Router.php') }] })
    await $.session.start(START)
    await w.clock.settle()
    // Never while the tab is not open.
    expect(w.churnRuns()).toHaveLength(0)
    const ui = await mountPane($, 'terminal', 120)
    expect((await ui.findAll({ key: 'tabkey:churn' })).map(b => b.props.hotkey)).toContain('8')
    await ui.press({ key: 'tab:churn' })
    await w.clock.settle()
    expect(w.churnRuns()).toHaveLength(1)
    const text = drawn((await ui.find({ key: 'pane' }))?.text ?? '')
    expect(titled(text)).toMatch(/Churn hotspots · commits × dependents +2 files/)
    expect(text).toMatch(/1 src\/Http\/\s*Router\.php/)
    // Kept for the commit: another visit, or a turn that left the checkout where it was, reads nothing.
    await ui.press({ key: 'tab:overview' })
    await ui.press({ key: 'tab:churn' })
    await w.clock.settle()
    expect(w.churnRuns()).toHaveLength(1)
    // The marked file opens as its detail.
    await ui.press({ key: 'row:0' })
    await w.clock.settle()
    expect(w.fileRuns().at(-1)?.at(-1)).toBe('src/Http/Router.php')
    await ui.press({ key: 'back' })
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.churnRuns()).toHaveLength(1)
    // A new commit reads it again.
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.churnRuns()).toHaveLength(2)
    await ui.unmount()
  })

  test("a component's detail draws its blast radius, read once per graph, each ring's components opening theirs", async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: fullDetailOf('Greeter') }], rings: [{ stdout: RINGS }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Greeter')
    await w.clock.settle()
    expect(w.ringRuns().map(r => r.slice(4))).toEqual([['--component=Greeter']])
    for (const surface of ['terminal', 'desktop'] as const) {
      const ui = await mountPane($, surface, 100)
      const text = drawn((await ui.find({ key: 'detail' }))?.text ?? '')
      expect(text).toMatch(/1 hop · 2 +─+ ▲ 1 untested/)
      expect(text).toMatch(/▲Caller {3}Router/)
      expect(text).toContain('✓ 1 test file: RouterTest.php')
      expect(text).toContain('◉ Greeter')
      await ui.unmount()
    }
    // The rings' components follow the detail's own lists: the first is the untested caller.
    const ui = await mountPane($, 'terminal', 100)
    // Two that use it and one it uses come first: the rings' own start at the fourth.
    await ui.press({ key: 'rel:3' })
    await w.clock.settle()
    expect(w.detailRuns().at(-1)?.at(-1)).toBe('App\\Caller')
    await ui.unmount()
    // Not read again for the same graph.
    expect(w.ringRuns().filter(r => r.at(-1) === '--component=Greeter')).toHaveLength(1)
  })

  test('p picks where a route ends in the finder and draws it; b goes back to the detail it began on', async ($, on) => {
    const found = JSON.stringify({ status: 'ok', query: 'kern', truncated: false, results: [{ type: 'file', name: 'src/Core/Kernel.php', canonical_name: 'src/Core/Kernel.php', kind: 'file', path: 'src/Core/Kernel.php', line: null, boundary: 'Core' }, { type: 'component', name: 'Kernel', canonical_name: 'App\\Core\\Kernel', kind: 'class', path: 'src/Core/Kernel.php', line: 4, boundary: 'Core' }] })
    const node = (name: string) => ({ name, canonical_name: `App\\${name}`, kind: 'class', boundary: 'Core' })
    const routed = JSON.stringify({ status: 'ok', from: node('Greeter'), to: node('Kernel'), reversed: false, truncated: false, routes: [{ nodes: [node('Greeter'), node('Router'), node('Kernel')], hops: [{ kind: 'calls', confidence: 'certain', path: 'src/Greeter.php', line: 7 }, { kind: 'constructs', confidence: 'certain', path: 'src/Router.php', line: 9 }] }] })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: fullDetailOf('Greeter') }], search: [{ stdout: found }], route: [{ stdout: routed }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Greeter')
    await w.clock.settle()
    const ui = await mountPane($, 'terminal', 100)
    expect((await ui.find({ key: 'route' }))?.props.hotkey).toBe('p')
    await ui.press({ key: 'route' })
    await w.clock.settle()
    expect((await ui.find({ type: 'Input', key: 'field:find' }))?.props.autoFocus).toBe(true)
    expect(drawn((await ui.find({ key: 'pane' }))?.text ?? (await ui.find({ key: 'detail' }))?.text ?? '')).toContain('Route from Greeter to…')
    await ui.input({ key: 'field:find', text: 'kern', kind: 'change' })
    await w.clock.advance(200)
    await w.clock.settle()
    // Only the component is offered: a route runs between components.
    expect((await ui.find({ key: 'found-0' }))?.text).toContain('Kernel')
    expect(await ui.find({ key: 'found-1' })).toBeUndefined()
    await ui.input({ key: 'field:find', text: 'kern' })
    await w.clock.settle()
    expect(w.routeRuns().map(r => r.slice(4))).toEqual([['--from=Greeter', '--to=App\\Core\\Kernel']])
    const text = drawn((await ui.find({ key: 'detail' }))?.text ?? (await ui.find({ key: 'pane' }))?.text ?? '')
    expect(text).toContain('Route › Greeter → Kernel')
    expect(text).toMatch(/calls · Greeter\.php:7/)
    // A box opens its component; back from the route is the detail it began on.
    await ui.press({ key: 'back' })
    await w.clock.settle()
    const back = drawn((await ui.find({ key: 'detail' }))?.text ?? '')
    expect(back).toMatch(/Overview › Greeter +●/)
    expect(back).not.toContain('Route ›')
    await ui.unmount()
  })

  test('a scan that brings a new cycle or violation says so once, as a toast, unless notifications are off', async ($, on) => {
    const fresh = (snapshot: string, members: string[][]) =>
      issuesDashboard({ snapshot_id: snapshot, cycles: { count: members.length, truncated: false, truncation_reasons: [], largest: members.map(m => ({ size: m.length, members: m })) } })
    const w = world(on, { dashboard: [{ stdout: fresh('s1', [['boot', 'route']]) }, { stdout: fresh('s2', [['boot', 'route'], ['App\\Store', 'App\\Cache']]) }, { stdout: fresh('s3', [['boot', 'route'], ['App\\Store', 'App\\Cache']]) }] })
    await $.session.start(START)
    await w.clock.settle()
    expect(w.toasts.filter(t => t.startsWith('knossos: a new'))).toEqual([])
    for (const n of [1, 2]) {
      await edit($, `${ROOT}/src/F${n}.php`)
      await $.turn.complete(TURN)
      await w.clock.settle()
    }
    expect(w.toasts.filter(t => t.startsWith('knossos: a new'))).toEqual(['knossos: a new dependency cycle of 2: Store → Cache → Store'])
  })

  test('notifications off: no toast for what a scan brings', { options: { notifications: false } }, async ($, on) => {
    const w = world(on, { dashboard: [{ stdout: issuesDashboard() }, { stdout: issuesDashboard({ snapshot_id: 's2', cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [{ size: 2, members: ['a', 'b'] }, { size: 2, members: ['c', 'd'] }] } }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/F.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.toasts.filter(t => t.startsWith('knossos: a new'))).toEqual([])
  })

  test('m adds a note: knossos checks it, the card asks, and only y records it; n drops it', async ($, on) => {
    const preview = JSON.stringify({ status: 'ok', component: 'App\\Greeter', kind: 'note', action: 'upsert', executed: false, value: 'keep it pure', previous: null })
    const recorded = JSON.stringify({ status: 'ok', component: 'App\\Greeter', kind: 'note', action: 'upsert', executed: true, value: 'keep it pure', previous: null })
    const w = world(on, { dashboard: [{ stdout: paneDashboard() }], detail: [{ stdout: fullDetailOf('Greeter') }], note: [{ stdout: preview }, { stdout: recorded }, { stdout: preview }] })
    await $.session.start(START)
    await w.clock.settle()
    await slash($, 'inspect Greeter')
    await w.clock.settle()
    const ui = await mountPane($, 'terminal', 100)
    expect((await ui.find({ key: 'note' }))?.props.hotkey).toBe('m')
    await ui.press({ key: 'note' })
    await w.clock.settle()
    // The field opens holding the note there is, and takes the focus.
    expect((await ui.find({ type: 'Input', key: 'field:note' }))?.props).toMatchObject({ autoFocus: true, value: 'The one way in.' })
    await ui.input({ key: 'field:note', text: 'keep it pure', kind: 'change' })
    // Typing writes nothing, nor asks anything.
    expect(w.noteRuns()).toHaveLength(0)
    await ui.input({ key: 'field:note', text: 'keep it pure' })
    await w.clock.settle()
    expect(w.noteRuns().map(r => r.slice(4))).toEqual([['--component=App\\Greeter', '--value=keep it pure']])
    expect(drawn((await ui.find({ key: 'detail' }))?.text ?? '')).toContain('Record this note on Greeter? "keep it pure"')
    expect((await ui.find({ key: 'note-yes' }))?.props.hotkey).toBe('y')
    const before = w.detailRuns().length
    await ui.press({ key: 'note-yes' })
    await w.clock.settle()
    expect(w.noteRuns().at(-1)?.slice(4)).toEqual(['--component=App\\Greeter', '--value=keep it pure', '--execute'])
    // Read again so the note shows; said in the footer.
    expect(w.detailRuns().length).toBe(before + 1)
    // Asked again and dropped: nothing more is written.
    await ui.press({ key: 'note' })
    await ui.input({ key: 'field:note', text: 'second thought' })
    await w.clock.settle()
    await ui.press({ key: 'note-no' })
    await w.clock.settle()
    expect(w.noteRuns().filter(r => r.includes('--execute'))).toHaveLength(1)
    expect(await ui.find({ key: 'note-yes' })).toBeUndefined()
    await ui.unmount()
  })
})
