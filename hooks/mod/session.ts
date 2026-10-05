/**
 * The session's life as the mod follows it: registering `/knossos` and the
 * model's tool, start-up, the age tick, the end of a turn and of a session,
 * and the `/knossos` command with the pane it opens.
 */
import type { ConfigRow, UiPane } from 'claude-code'

import { CONTEXT_DESCRIPTION, CONTEXT_SCHEMA, CONTEXT_TOOL } from '../lib/agent'
import { bandModel } from '../lib/band'
import { NO_CHANGES, noGraphOf, paneStatus } from '../lib/layout'
import { SingleFlight } from '../lib/scheduler'
import { declaredOf, huesOf } from '../lib/palette'
import { currentSession, peekKey, readGitHead, refreshDashboard, request, settleBaseline, showComponent } from './loaders'
import { endWatcher, placed } from './port'
import type { Port } from './port'
import { CONTEXT_TOOL_NAME, cyclesOf, mod, PANE } from './state'
import { ensureWatcher, scanSafely } from './watcher'

/** What `/knossos` answers to anything it does not take. */
const USAGE = 'Usage: /knossos to toggle the architecture pane; /knossos inspect <component> to open it on one component.'

/** formatAge's finest step is a second; the tick redraws only when the text would change. */
const AGE_TICK_MS = 1_000

/** How often the pane with no figures, after a load that failed, asks for the dashboard again. */
const EMPTY_RETRY_MS = 15_000

/**
 * Registers the model's `knossos_context` tool (one file's context in one
 * call); false when the engine refuses, as it does until a session is bound.
 * The first refusal leaves one debug line.
 */
async function registerTool(io: Port): Promise<boolean> {
  if (mod.contextTool !== null) return true
  try {
    const { tool } = await io.tool.register({ name: CONTEXT_TOOL, description: CONTEXT_DESCRIPTION, inputSchema: CONTEXT_SCHEMA as unknown as Record<string, unknown> })
    mod.contextTool = typeof tool === 'string' && tool !== '' ? tool : CONTEXT_TOOL_NAME
    return true
  } catch (err) {
    if (!mod.toolFailureLogged) {
      mod.toolFailureLogged = true
      io.ui.log(`knossos: could not register the knossos_context tool yet, retrying (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return false
  }
}

/** How long to wait before each retry of a refused registration, in milliseconds; after the last, turn ends retry. */
const REGISTER_RETRY_MS = [500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000] as const

/**
 * Registers `/knossos` and the model's `knossos_context` tool; false when
 * the engine refuses either, as it does when no session is bound in the
 * process yet (the moment after a hot reload). The first refusal leaves one
 * debug line.
 */
export async function registerCommand(io: Port): Promise<boolean> {
  // The model's tool rides on the same registration, and its retries: both need a session bound.
  const tool = await registerTool(io)
  if (mod.commandRegistered) return tool
  try {
    await io.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    mod.commandRegistered = true
    return tool
  } catch (err) {
    if (!mod.registerFailureLogged) {
      mod.registerFailureLogged = true
      io.ui.log(`knossos: could not register /knossos yet, retrying (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return false
  }
}

/** Retries a refused registration after the `attempt`-th delay, then the next; past the last, the end of a turn tries again. */
export function retryRegister(io: Port, attempt: number, gen = mod.registerGen): void {
  const delay = REGISTER_RETRY_MS[attempt]
  if (delay === undefined || (mod.commandRegistered && mod.contextTool !== null) || mod.disabled || gen !== mod.registerGen) return
  try {
    mod.registerTimer = io.clock.after(delay, () => {
      mod.registerTimer = null
      if (gen !== mod.registerGen) return
      void registerCommand(io)
        .then(done => (done ? undefined : retryRegister(io, attempt + 1, gen)))
        .catch(() => undefined)
    })
  } catch {
    mod.registerTimer = null
  }
}

/**
 * Redraws the band when the text it would draw now differs from what it drew:
 * a render answer is reused until state changes, so without this an age such
 * as "as of 12s ago" would stand still while the figures grow old.
 */
async function tickAge(io: Port): Promise<void> {
  // After a /clear or a resume the process may name the new session only later: its baseline is settled once it does.
  if (mod.baselineOf === null && !mod.disabled && (await currentSession(io)) !== null) await settleBaseline(io)
  // The watcher is kept running from here, never from what its own events start: no call loops back on itself.
  await ensureWatcher(io)
  await retryEmpty(io)
  // The footer's word after an action fades once its time is up: a change of state, so the pane redraws without it.
  const said = await io.state.feedback.read()
  if (said !== null && (await io.clock.now()) >= said.until) await io.state.feedback.update(() => null)
  // The rows a scan lit go back once their moment is over.
  const lit = await io.state.flash.read()
  if (lit !== null && (await io.clock.now()) >= lit.until) await io.state.flash.update(() => null)
  // A pane drawn wide shows the marked row's detail beside the tab: looked up from here the first time it is drawn so.
  // A row already found to have nothing to show is not looked at again each tick: that would lay the pane out every second.
  if (mod.paneWide && (await io.state.peek.read()) === null && mod.peekNone !== peekKey(await io.state.view.read(), (await io.state.dashboard.read())?.snapshot_id ?? null)) await request(io, 'peek')
  // A pane closed by any means (the command, its own close key) stops drawing; stop ticking for it, and it is no longer wide.
  if (mod.paneText !== null && !(await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    mod.paneText = null
    mod.paneWide = false
  }
  if (mod.bandText === null && mod.paneText === null) return
  const now = await io.clock.now()
  const d = await io.state.dashboard.read()
  const model = bandModel(await io.state.brief.read(), await io.state.job.read(), now, d?.status === 'ok' ? declaredOf(d) : undefined, d?.status === 'ok' ? huesOf(d) : undefined)
  const pane = d?.status === 'ok' ? paneStatus(d, await io.state.refresh.read(), await io.state.rescan.read(), now, await io.state.live.read()).text : null
  const bandStale = mod.bandText !== null && (model?.text ?? null) !== mod.bandText
  const paneStale = mod.paneText !== null && pane !== mod.paneText
  if (bandStale || paneStale) io.ui.invalidate('ui.render')
}

/**
 * While the pane shows no figures because a load failed (silent, or an
 * error), asks for the dashboard again every {@link EMPTY_RETRY_MS}: the
 * pane says it is retrying, so it does.
 */
async function retryEmpty(io: Port): Promise<void> {
  if (!mod.emptyShown || mod.disabled || mod.dashboardFlight?.isRunning === true) return
  if (!(await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    mod.emptyShown = false
    return
  }
  const d = await io.state.dashboard.read()
  if (d?.status === 'ok' || noGraphOf(d, await io.state.refresh.read()) !== 'unreadable') return
  if ((await io.clock.now()) - mod.dashboardTriedAt < EMPTY_RETRY_MS) return
  void refreshDashboard(io).catch(() => undefined)
}

/**
 * Stores the person's theme from `/config`, for the colours a Raster cannot
 * take as theme keys. A config that cannot be read leaves the dark default.
 */
async function readTheme(io: Port): Promise<void> {
  const row = (await io.config.list().catch(() => [])).find((r: ConfigRow) => r.key === 'theme')
  if (typeof row?.value === 'string') await io.state.theme.update(() => row.value as string)
}

/**
 * The first dashboard. A silent one is not a reason to stop: the next dirty
 * turn asks again. Only the wrapper's `no-binary` turns the mod off.
 */
export async function startUp(io: Port, openOnStart: boolean): Promise<void> {
  // First: where the session began, read back for a session continued here, else the commit it begins at, before anything of it can be committed.
  await settleBaseline(io)
  // Where the checkout stands, for the header: the baseline's own head read, when there was one, said it already.
  if (!mod.gitAsked) await readGitHead(io)
  await readTheme(io)
  const root = await io.session.root().then((r: string) => placed(io, r)).catch(() => null)
  await io.state.sessionRoot.update(() => root)
  const stored = await refreshDashboard(io)
  if (mod.disabled) return
  mod.ticker ??= io.clock.every(AGE_TICK_MS, () => void tickAge(io).catch(() => undefined))
  await ensureWatcher(io)
  // A refused or unplaced pane is the person's layout, not a failure of the mod.
  if (openOnStart) await openPane(io, !stored)
}

/**
 * Opens the pane and, unless the dashboard was just loaded, refreshes it on a
 * timer so the pane never presents old figures as current. A refused or
 * unplaced pane is the person's layout, not a failure of the mod.
 */
export async function openPane(io: Port, refreshFirst = true): Promise<void> {
  if (refreshFirst) io.clock.after(0, () => void refreshDashboard(io).catch(() => undefined))
  await io.ui.open({ id: PANE, title: 'Knossos' }).catch(() => undefined)
}

/** `/knossos` toggles the pane, `/knossos inspect <component>` opens it on one component. */
export async function runCommand(io: Port, args: string): Promise<string> {
  const verb = args.trim().split(/\s+/)[0] ?? ''
  if (verb === 'inspect') {
    const name = args.trim().slice('inspect'.length).trim()
    if (name === '') return USAGE
    // The snapshot first, so the lookup is keyed to the graph it reads.
    const loaded = (await io.state.dashboard.read()) === null && (await refreshDashboard(io))
    await showComponent(io, { name, label: name })
    await openPane(io, !loaded)
    return 'Knossos pane opened.'
  }
  if (verb !== '') return USAGE
  if ((await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    await io.ui.close({ id: PANE }).catch(() => undefined)
    mod.paneText = null
    mod.paneWide = false
    return 'Knossos pane closed.'
  }
  await io.state.view.update(v => ({ ...v, inspect: null }))
  await openPane(io)
  return 'Knossos pane opened.'
}

/** Session ends after which the process goes no further with this project: no watcher is started again. */
const FINAL_ENDS: readonly string[] = ['prompt_input_exit', 'logout', 'other']

/**
 * The session ends (exit, /clear, resume): its watcher ends with it. After a
 * /clear or an in-process resume the process goes on (no session.start
 * fires), so the next tick starts a watcher again; after an exit, a logout or
 * a signal, none.
 */
export async function endSession(io: Port, reason: string, sessionId: string): Promise<void> {
  await endWatcher(io).catch(() => undefined)
  if (FINAL_ENDS.includes(reason)) {
    mod.watchRetryAt = Number.POSITIVE_INFINITY
    return
  }
  mod.watchFailures = 0
  mod.watchRetryAt = 0
  // A new session in the same process: its changes start now, at the graph as it stands, and its cycles too.
  await io.state.sessionStart.update(() => mod.snapshot)
  const now = await io.state.dashboard.read()
  await io.state.startCycles.update(() => (now?.status === 'ok' ? cyclesOf(now) : null))
  mod.commitNoted = new Set()
  mod.toasted = new Set()
  // What the notes told the session that ended: the next one's model never read it, so it is news again.
  mod.noted = new Set()
  mod.ruled = new Set()
  mod.testsNamed = new Set()
  mod.violationsSeen = new Set()
  mod.truncationSaid = false
  await io.state.sessionBegan.update(() => null)
  await io.state.sessionEdits.update(() => [])
  await io.state.sessionScans.update(() => [])
  // Its diffs are taken against the commit it begins at.
  await io.state.sessionRev.update(() => null)
  await io.state.fileDiff.update(() => null)
  await io.state.diffFold.update(() => null)
  // The session that ended keeps its baseline; the one that follows reads its own back (a resume) or records one.
  mod.endedSession = sessionId
  mod.baselineOf = null
  mod.headAsked = false
  io.clock.after(0, () => void settleBaseline(io).catch(() => undefined))
  await io.state.sessionLedger.update(() => null)
  await io.state.changes.update(() => NO_CHANGES)
}

/**
 * A turn of `agentId`'s loop has ended (the main loop's when undefined): its
 * notes are counted afresh, and after the main loop's turn the registration
 * is asked for again if it is still refused, the header's checkout and an
 * absent graph are read again, and the turn's scan is scheduled when it
 * edited anything.
 */
export async function endTurn(io: Port, agentId: string | undefined): Promise<void> {
  mod.turnNotes.delete(agentId ?? '')
  // A subagent's turn ends inside the main one; its edits and commands ride the main turn's scan.
  if (agentId !== undefined) return
  mod.turnRan = mod.ranCommands
  mod.ranCommands = []
  // Still refused once the timed retries ran out: each turn's end asks again, a session being bound by now.
  if (!mod.disabled && !(mod.commandRegistered && mod.contextTool !== null) && mod.registerTimer === null) await registerCommand(io)
  // The turn may have committed or switched branches: the header reads where the checkout stands again.
  if (!mod.disabled) io.clock.after(0, () => void readGitHead(io).catch(() => undefined))
  // No graph to draw yet (the person may just have asked Claude to scan): look again, once the turn is over.
  if (!mod.disabled && (await io.state.dashboard.read())?.status !== 'ok') io.clock.after(0, () => void refreshDashboard(io).catch(() => undefined))
  // Nothing to brief, now or held for later: no turn start may carry over to a later turn.
  if (!mod.dirty && mod.edited.size === 0) mod.turnBase = null
  if (mod.disabled || !mod.dirty) return
  mod.dirty = false
  const current = (mod.flight ??= new SingleFlight(() => scanSafely(io)))
  // Never inside the hook's budget: the job starts once this dispatch has resolved.
  io.clock.after(0, () => void current.request())
}
