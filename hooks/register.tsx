import { atom, read, update } from 'claude-code'
import type { EngineInterface, Register, Timer } from 'claude-code'

import { bandModel } from './lib/band'
import type { JobState } from './lib/band'
import { countLabel, detailLines, parseComponentDetail, parseDashboard, parseTurnBrief } from './lib/envelopes'
import type { TurnBrief } from './lib/envelopes'
import { editNote, fanInIndex, violationNote } from './lib/notes'
import { relativise } from './lib/paths'
import { SingleFlight } from './lib/scheduler'
import { sparkline } from './lib/sparkline'
import type { DetailState, KnossosView } from '../types'

const PANE = 'knossos'
/**
 * Whether a Bash call marks the turn dirty. Off: a no-change incremental scan
 * of a real project takes about four seconds, over the two-second budget that
 * made scanning after every shell command acceptable. One line to turn back on.
 */
const BASH_MARKS_DIRTY = false
const EDIT_TOOLS = ['Edit', 'Write', 'NotebookEdit'] as const
/** The wrapper's own limits (60 s and 15 s) plus room for it to exit on its own. */
const BRIEF_TIMEOUT_MS = 70_000
const DASHBOARD_TIMEOUT_MS = 20_000
const DETAIL_TIMEOUT_MS = 20_000
const USAGE = 'Usage: /knossos to toggle the architecture pane; /knossos inspect <component> to open it on one component.'
/** How many members of a cycle the pane names before it stops at an ellipsis. */
const CYCLE_MEMBERS = 4
/** formatAge's finest step is a second; the tick redraws only when the text would change. */
const AGE_TICK_MS = 1_000
const DEFAULT_THRESHOLD = 20

const brief = atom({ plugin: 'knossos', key: 'brief' } as const, null)
const dashboard = atom({ plugin: 'knossos', key: 'dashboard' } as const, null)
const job = atom({ plugin: 'knossos', key: 'job' } as const, { phase: 'idle', lastAttemptAt: null } as JobState)
const view = atom({ plugin: 'knossos', key: 'view' } as const, { inspect: null, isBandHidden: false } as KnossosView)
const detail = atom({ plugin: 'knossos', key: 'detail' } as const, null as DetailState | null)

/** The edited file's path from an edit tool's input: `notebook_path` for NotebookEdit. */
function editedPath(e: object): string | null {
  const { file_path: file, notebook_path: notebook } = e as { file_path?: unknown; notebook_path?: unknown }
  if (typeof file === 'string') return file
  return typeof notebook === 'string' ? notebook : null
}

/**
 * The module's own state for one load. The engine lets `$` reach only
 * functions declared at the top of the module, so the helpers below read
 * this rather than closing over `register`'s scope; `register` resets it,
 * as a reload starts the module's variables over.
 */
const mod = {
  threshold: 20,
  enforce: true,
  /** Set by an edit inside the project, cleared when a turn's scan is scheduled. */
  dirty: false,
  /** Paths edited since the last scan began: project-relative, or absolute before the first dashboard. */
  edited: new Set<string>(),
  /** Two silent dashboards at start: no usable binary, so the mod stays out of the way. */
  disabled: false,
  flight: null as SingleFlight | null,
  /** What the band last drew, or null when it drew nothing: the age tick compares against it. */
  bandText: null as string | null,
  /** Keeps the band's age current while the mod is on. */
  ticker: null as Timer | null,
  /**
   * Component lookups in flight, by snapshot and name: a press while one runs
   * starts no second. Checked and set with no await between, so two presses
   * in the same tick cannot both start one.
   */
  fetching: new Set<string>(),
}

/** The fan-in threshold from the options: a positive finite number, else the default. */
function thresholdOf(value: unknown): number {
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? n : DEFAULT_THRESHOLD
}

/**
 * Redraws the band when the text it would draw now differs from what it drew:
 * a render answer is reused until state changes, so without this an age such
 * as "as of 12s ago" would stand still while the figures grow old.
 */
async function tickAge($: EngineInterface): Promise<void> {
  if (mod.bandText === null) return
  const model = bandModel(await read($, brief), await read($, job), await $.clock.now())
  if ((model?.text ?? null) !== mod.bandText) $.ui.invalidate('ui.render')
}

/** Runs the wrapper; its stdout, or '' for any failure (the wrapper's contract is silence). */
async function wrapper($: EngineInterface, sub: string, args: string[], timeoutMs: number): Promise<string> {
  try {
    const root = await $.session.root()
    const script = `${$.plugin.root}/hooks/scripts/knossos-run.sh`
    const { stdout } = await $.process.run(['sh', script, sub, root, ...args], { timeoutMs })
    return stdout
  } catch {
    return ''
  }
}

/** Loads the dashboard into state; false when the wrapper said nothing usable. */
async function refreshDashboard($: EngineInterface): Promise<boolean> {
  const stdout = await wrapper($, 'dashboard', [`--fan-in-threshold=${mod.threshold}`], DASHBOARD_TIMEOUT_MS)
  const parsed = parseDashboard(stdout)
  if (parsed === null) return false
  await update($, dashboard, () => parsed)
  // The pane open on a component follows the graph: a new snapshot looks it up again.
  const shown = (await read($, view)).inspect
  if (shown !== null) await requestDetail($, shown)
  return true
}

/** The first dashboard, retried once; two silences turn the mod off for the session. */
async function startUp($: EngineInterface, openOnStart: boolean): Promise<void> {
  if (!(await refreshDashboard($)) && !(await refreshDashboard($))) {
    mod.disabled = true
    mod.ticker?.cancel()
    mod.ticker = null
    $.ui.log('knossos: no data from the knossos binary; the band is off for this session.')
    return
  }
  mod.ticker ??= $.clock.every(AGE_TICK_MS, () => void tickAge($).catch(() => undefined))
  // A refused or unplaced pane is the person's layout, not a failure of the mod.
  if (openOnStart) await openPane($)
}

/**
 * Shows one component on the pane and starts its lookup. The lookup runs on
 * a timer, never inside a render: the pane draws "Inspecting <name>…" from
 * state until the answer is stored.
 */
async function showComponent($: EngineInterface, name: string): Promise<void> {
  await update($, view, v => ({ ...v, inspect: name }))
  await requestDetail($, name)
}

/** Starts a lookup of `name` unless one is running or done for this snapshot; silence is asked again. */
async function requestDetail($: EngineInterface, name: string): Promise<void> {
  const snapshot = (await read($, dashboard))?.snapshot_id ?? null
  const key = `${snapshot ?? ''}\u0000${name}`
  if (mod.fetching.has(key)) return
  mod.fetching.add(key)
  const current = await read($, detail)
  if (current?.name === name && current.snapshot_id === snapshot && current.phase === 'done' && current.lines !== null) {
    mod.fetching.delete(key)
    return
  }
  await update($, detail, (): DetailState => ({ snapshot_id: snapshot, name, lines: null, phase: 'loading' }))
  $.clock.after(0, () => void loadDetail($, snapshot, name, key))
}

/** One lookup; its answer is stored only while the detail still asks for that component and snapshot. */
async function loadDetail($: EngineInterface, snapshot: string | null, name: string, key: string): Promise<void> {
  try {
    const parsed = parseComponentDetail(await wrapper($, 'component-detail', [name], DETAIL_TIMEOUT_MS))
    const lines = parsed === null ? null : detailLines(parsed, name)
    await update($, detail, (current): DetailState | null =>
      current?.name === name && current.snapshot_id === snapshot ? { ...current, lines, phase: 'done' } : current,
    )
  } catch {
    // A lost write leaves the loading line; the next press asks again.
  } finally {
    mod.fetching.delete(key)
  }
}

/** Opens the pane; a refused or unplaced pane is the person's layout, not a failure of the mod. */
async function openPane($: EngineInterface): Promise<void> {
  await $.ui.open({ id: PANE, title: 'Knossos' }).catch(() => undefined)
}

/** `/knossos` toggles the pane, `/knossos inspect <component>` opens it on one component. */
async function runCommand($: EngineInterface, args: string): Promise<string> {
  const verb = args.trim().split(/\s+/)[0] ?? ''
  if (verb === 'inspect') {
    const name = args.trim().slice('inspect'.length).trim()
    if (name === '') return USAGE
    // The snapshot first, so the lookup is keyed to the graph it reads.
    if ((await read($, dashboard)) === null) await refreshDashboard($)
    await showComponent($, name)
    await openPane($)
    return 'Knossos pane opened.'
  }
  if (verb !== '') return USAGE
  if ((await $.ui.panes()).some(pane => pane.id === PANE)) {
    await $.ui.close({ id: PANE }).catch(() => undefined)
    return 'Knossos pane closed.'
  }
  await update($, view, v => ({ ...v, inspect: null }))
  await refreshDashboard($)
  await openPane($)
  return 'Knossos pane opened.'
}

/** One trend row: its label, the glyphs oldest to newest, and the newest value. */
function trendRow(label: string, values: number[]): string {
  const newest = values.at(-1)
  return newest === undefined ? `${label}  no snapshots yet` : `${label}  ${sparkline(values)} ${newest}`
}

/** Stores what a turn brief says, by its status; resolves true when it is a fresh `ok`. */
async function settleBrief($: EngineInterface, parsed: TurnBrief | null, now: number): Promise<boolean> {
  if (parsed === null || parsed.status === 'error') {
    await update($, job, (): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  if (parsed.status === 'scan-failed') {
    // The first failure shows its reason; later ones keep the last good figures with their age.
    const previous = await read($, brief)
    if (previous?.status !== 'ok') await update($, brief, () => parsed)
    await update($, job, (): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  await update($, brief, () => parsed)
  await update($, job, (): JobState => ({ phase: 'idle', lastAttemptAt: now }))
  return parsed.status === 'ok'
}

/** One run of the scan job: the turn brief for every path edited since the last run. */
async function scan($: EngineInterface): Promise<void> {
  // Drained at the start of each run, so a coalesced rerun scans every path reported since.
  const files = [...mod.edited]
  mod.edited = new Set()
  await update($, job, (j): JobState => ({ ...j, phase: 'scanning' }))
  const args = [...files.map(f => `--files=${f}`), ...(mod.enforce ? [] : ['--no-policies'])]
  const parsed = parseTurnBrief(await wrapper($, 'turn-brief', args, BRIEF_TIMEOUT_MS))
  if (!(await settleBrief($, parsed, await $.clock.now())) || parsed === null) return
  const note = mod.enforce ? violationNote(parsed) : null
  if (note !== null) await deliverNote($, note)
  await refreshDashboard($)
}

/**
 * Appends the violation note as a user-role row the model reads. A refusal
 * (a plugin above, or a run no plugin may shape) leaves a debug line with the
 * note, so a lost note is never silent.
 */
async function deliverNote($: EngineInterface, note: string): Promise<void> {
  let reason: string | null
  try {
    const appended = await $.session.append({ message: { type: 'user', content: [{ type: 'text', text: note }] } })
    reason = appended.deny ?? null
  } catch (err) {
    reason = err instanceof Error ? err.message : String(err)
  }
  if (reason !== null) $.ui.log(`knossos: the policy note did not reach the model (${reason}): ${note}`, { to: 'debug' })
}

/** The scan job's body: a run that throws leaves the job marked failed instead of rejecting. */
async function scanSafely($: EngineInterface): Promise<void> {
  try {
    await scan($)
  } catch {
    const now = await $.clock.now().catch(() => null)
    await update($, job, (j): JobState => ({ phase: 'failed', lastAttemptAt: now ?? j.lastAttemptAt })).catch(
      () => undefined,
    )
  }
}

/** Records an edit; the fan-in note for the model, or null when the file is quiet or outside. */
async function noteEdit($: EngineInterface, path: string): Promise<string | null> {
  const current = await read($, dashboard)
  const root = current?.project_root ?? null
  if (root === null) {
    // No dashboard yet: the project root is unknown, so keep the absolute
    // path (the brief accepts those) when it lies under the session's root.
    if (relativise(await $.session.root(), path) === null) return null
    mod.dirty = true
    mod.edited.add(path)
    return null
  }
  const relative = relativise(root, path)
  if (relative === null) return null
  mod.dirty = true
  mod.edited.add(relative)
  const entry = fanInIndex(current).get(relative)
  return entry === undefined || entry.dependent_files < mod.threshold ? null : editNote(entry)
}

export const register: Register = (on, options) => {
  if (options.enabled === false) return
  mod.threshold = thresholdOf(options.fanInThreshold)
  mod.enforce = options.enforcePolicies !== false
  mod.dirty = false
  mod.edited = new Set()
  mod.disabled = false
  mod.flight = null
  mod.bandText = null
  mod.ticker?.cancel()
  mod.ticker = null
  mod.fetching = new Set()
  const openOnStart = options.openPaneOnStart === true

  on('session.start', async ($, e, next) => {
    await $.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    $.clock.after(0, () => void startUp($, openOnStart))
    return next(e)
  })

  on('tool.call', { tool: EDIT_TOOLS }, async ($, e, next) => {
    const ran = await next(e)
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    const path = editedPath(e)
    const note = path === null ? null : await noteEdit($, path)
    if (note === null) return ran
    $.ui.toast(note)
    return { ...ran, context: [...(ran.context ?? []), note] }
  })

  if (BASH_MARKS_DIRTY) {
    on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
      const ran = await next(e)
      mod.dirty = true
      return ran
    })
  }

  on('turn.complete', async ($, e, next) => {
    const result = await next(e)
    // A subagent's turn ends inside the main one; its edits ride the main turn's scan.
    if (mod.disabled || !mod.dirty || e.agentId !== undefined) return result
    mod.dirty = false
    const current = (mod.flight ??= new SingleFlight(() => scanSafely($)))
    // Never inside the hook's budget: the job starts once this dispatch has resolved.
    $.clock.after(0, () => void current.request())
    return result
  })

  on('ui.render', { component: 'AbovePrompt' }, async ($, e, next) => {
    if (mod.disabled || e.props.hasSurvey || (await read($, view)).isBandHidden) {
      mod.bandText = null
      return next(e)
    }
    const model = bandModel(await read($, brief), await read($, job), await $.clock.now())
    mod.bandText = model?.text ?? null
    if (model === null) return next(e)
    const { Box, Button, Text } = $.ui.resolve(e)
    const color = model.tone === 'alert' ? 'red' : model.tone === 'warn' ? 'yellow' : undefined
    return (
      <Box key="band">
        <Text color={color} dimColor={model.tone === 'normal'}>
          {model.text}{' '}
        </Text>
        {model.showDetails && (
          <Button key="details" label="details" onPress={() => openPane($)} />
        )}
        <Button key="hide" label="hide" onPress={() => update($, view, v => ({ ...v, isBandHidden: true }))} />
      </Box>
    )
  })

  on('command.run', { command: 'knossos' }, async ($, e) => ({ text: await runCommand($, e.args) }))

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const { Box, Button, Text } = $.ui.resolve(e)
    const d = await read($, dashboard)
    const v = await read($, view)
    if (d === null || d.status !== 'ok') {
      return (
        <Box key="empty">
          <Text dimColor>No Knossos data for this project. Scan it with knossos scan.</Text>
        </Box>
      )
    }
    const show = (name: string) => showComponent($, name)
    if (v.inspect !== null) {
      // State only: a detail for another component (an answer that came late) is not this one's.
      const shown = await read($, detail)
      const lines =
        shown === null || shown.name !== v.inspect || shown.phase === 'loading'
          ? [`Inspecting ${v.inspect}…`]
          : (shown.lines ?? [`No details for ${v.inspect}: knossos said nothing.`])
      return (
        <Box key="detail" flexDirection="column">
          <Text bold>{v.inspect}</Text>
          {lines.map((line, i) => (
            <Text key={`line-${i}`}>{line}</Text>
          ))}
          <Button key="back" label="back" onPress={() => update($, view, x => ({ ...x, inspect: null }))} />
        </Box>
      )
    }
    // No loop variable may be called `h`: JSX compiles to h(...), and a
    // parameter of that name shadows the element factory inside its callback.
    return (
      <Box flexDirection="column">
        <Text dimColor>
          snapshot {d.freshness.state} · {d.freshness.drift_files} files drifted
        </Text>
        <Text bold>Hubs</Text>
        <Box key="hubs" flexDirection="column">
          {d.hubs.length === 0 && <Text dimColor>none</Text>}
          {d.hubs.map((hub, i) => (
            <Button key={`hub-${i}`} plain label={`${hub.name} (${hub.kind}) in ${hub.in_degree}`} onPress={() => show(hub.name)} />
          ))}
        </Box>
        <Text bold>Hotspots</Text>
        <Box key="hotspots" flexDirection="column">
          {d.hotspots.length === 0 && <Text dimColor>none</Text>}
          {d.hotspots.map((spot, i) => (
            <Button key={`hot-${i}`} plain label={`${spot.name} (${spot.kind}) ${spot.score.toFixed(1)}`} onPress={() => show(spot.name)} />
          ))}
        </Box>
        <Box key="cycles" flexDirection="column">
          <Text bold>Cycles: {countLabel(d.cycles.count, d.cycles.truncated)}</Text>
          {d.cycles.largest.map((c, i) => (
            <Text key={`cycle-${i}`} dimColor>
              {c.size}: {c.members.slice(0, CYCLE_MEMBERS).join(' → ')}
              {c.members.length > CYCLE_MEMBERS ? ' …' : ''}
            </Text>
          ))}
        </Box>
        <Box key="dead-code">
          <Text>Dead-code candidates: {countLabel(d.dead_code_candidates, d.dead_code_truncated)}</Text>
        </Box>
        <Box key="trend" flexDirection="column">
          <Text>{trendRow('cycles per snapshot    ', d.trend.map(t => t.cycles))}</Text>
          <Text>{trendRow('max degree per snapshot', d.trend.map(t => t.max_degree))}</Text>
        </Box>
      </Box>
    )
  })
}
