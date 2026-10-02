import { atom, read, update } from 'claude-code'
import type { EngineInterface, Register, Timer } from 'claude-code'

import { bandModel } from './lib/band'
import type { JobState } from './lib/band'
import { parseDashboard, parseTurnBrief } from './lib/envelopes'
import type { TurnBrief } from './lib/envelopes'
import { editNote, fanInIndex, violationNote } from './lib/notes'
import { relativise } from './lib/paths'
import { SingleFlight } from './lib/scheduler'
import type { KnossosView } from '../types'

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
/** formatAge's finest step is a second; the tick redraws only when the text would change. */
const AGE_TICK_MS = 1_000
const DEFAULT_THRESHOLD = 20

const brief = atom({ plugin: 'knossos', key: 'brief' } as const, null)
const dashboard = atom({ plugin: 'knossos', key: 'dashboard' } as const, null)
const job = atom({ plugin: 'knossos', key: 'job' } as const, { phase: 'idle', lastAttemptAt: null } as JobState)
const view = atom({ plugin: 'knossos', key: 'view' } as const, { inspect: null, isBandHidden: false } as KnossosView)

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
  return true
}

/** The first dashboard, retried once; two silences turn the mod off for the session. */
async function startUp($: EngineInterface, openPane: boolean): Promise<void> {
  if (!(await refreshDashboard($)) && !(await refreshDashboard($))) {
    mod.disabled = true
    mod.ticker?.cancel()
    mod.ticker = null
    $.ui.log('knossos: no data from the knossos binary; the band is off for this session.')
    return
  }
  mod.ticker ??= $.clock.every(AGE_TICK_MS, () => void tickAge($).catch(() => undefined))
  // A refused or unplaced pane is the person's layout, not a failure of the mod.
  if (openPane) await $.ui.open({ id: PANE, title: 'Knossos' }).catch(() => undefined)
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
  const openPane = options.openPaneOnStart === true

  on('session.start', async ($, e, next) => {
    await $.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    $.clock.after(0, () => void startUp($, openPane))
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
          <Button key="details" label="details" onPress={() => void $.ui.open({ id: PANE, title: 'Knossos' }).catch(() => undefined)} />
        )}
        <Button key="hide" label="hide" onPress={() => update($, view, v => ({ ...v, isBandHidden: true }))} />
      </Box>
    )
  })
}
