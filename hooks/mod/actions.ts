/**
 * What the person's presses and keystrokes on the pane do: the press table,
 * and the flows behind it (the finder, a note on a component, a route
 * between two, allowing a refused root, the editor and the clipboard).
 */
import type { AllowState, DiffFold, Inspected, KnossosView, NoteState, PaneTab, RescanState, SearchState } from '../../types'
import { diffPage, diffView } from '../lib/diff'
import { parseAllowRoot, parseAnnotate, parseGraphSearch } from '../lib/envelopes'
import { askPrompt, editTarget, locOf, locText, nextTarget, noGraphOf, peekList, refusedRoot, SCAN_PROMPT, SORTS, subjectOf, TABS } from '../lib/layout'
import type { Loc } from '../lib/layout'
import { SingleFlight } from '../lib/scheduler'
import { request, requestDetail, showComponent } from './loaders'
import { disable, say, wrapper } from './port'
import type { Port } from './port'
import { currentInput, currentList } from './render'
import type { Handlers } from './render'
import { fieldKey, mod, PANE } from './state'
import { requestRescan, scanSafely } from './watcher'
import type { ProcessRunResult, RenderSurface } from 'claude-code'

/** The wrapper bounds allow-root at 15 s. */
const ALLOW_TIMEOUT_MS = 20_000

/** How long the editor's command may take to hand a file to the editor. */
const EDITOR_TIMEOUT_MS = 10_000

/**
 * The command that opens `path:line` in the person's editor: VS Code's
 * `code -g`, which Cursor and the other forks also answer to. A terminal
 * editor (`$EDITOR`) needs a terminal of its own, which a press cannot give.
 */
const EDITOR = ['code', '-g'] as const

/** How long the finder waits after a keystroke before it asks: a word typed fast is one search. */
const SEARCH_DEBOUNCE_MS = 150

/** The wrapper bounds graph-search and annotate at 15 s. */
const SEARCH_TIMEOUT_MS = 20_000
const NOTE_TIMEOUT_MS = 20_000

/** Moves the selection marker by `by` rows, kept inside the list. */
async function moveSelection(io: Port, by: number): Promise<void> {
  const length = (await currentList(io)).length
  // A boundary marked anew starts on what it depends on most.
  await io.state.view.update(v => ({ ...v, selected: Math.min(Math.max(0, v.selected + by), Math.max(0, length - 1)), target: undefined }))
}

/** Opens the row at `index` (the marker's when absent) and leaves the marker there: a component or a file as its detail. */
async function openRow(io: Port, index?: number): Promise<void> {
  const at = index ?? (await io.state.view.read()).selected
  const item = (await currentList(io))[at]
  if (item === undefined) return
  // A match opened from the finder closes it: the detail opens over the tab, whose marker stays where it stood.
  if ((await io.state.view.read()).finding === true) {
    // Picking where a route ends: the match is that end, and the route is drawn.
    if (((await io.state.view.read()).picking ?? null) !== null) return showRoute(io, { name: item.canonical, label: item.name })
    mod.openWhenFound = false
    await io.state.view.update((v): KnossosView => ({ ...v, finding: false, selected: mod.findFrom }))
    return showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
  }
  await io.state.view.update(v => ({ ...v, selected: at }))
  // An Overview chart's bar opens the tab it counts: Hubs narrowed to a bucket, a flow's cell on Boundaries, Changes.
  const jump = item.jump
  if (jump !== undefined) {
    await io.state.view.update((v): KnossosView => ({ ...v, tab: jump.tab, selected: jump.selected ?? 0, degree: jump.degree ?? null, filtering: false, drift: false, target: jump.target }))
    return
  }
  // A boundary opens nothing: marking it is what spells it out.
  if (item.inert === true) return
  await showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) })
}

/**
 * Opens a place in the person's editor (see {@link EDITOR}). Where no editor
 * command answers, `path:line` goes onto the clipboard of the surface the
 * press came from instead, and a toast says which happened.
 */
async function openLocation(io: Port, loc: Loc, surface?: RenderSurface): Promise<void> {
  const target = locText(loc)
  const opened = await io.process
    .run([...EDITOR, target], { timeoutMs: EDITOR_TIMEOUT_MS })
    .then(
      (r: ProcessRunResult) => r.exitCode === 0,
      () => false,
    )
  if (opened) return say(io, '✓ opened in editor', 'ok')
  const copied = await io.ui.copy({ text: target, ...(surface === undefined ? {} : { surface }) })
  await say(io, copied.isCopied ? '✗ no editor · path copied' : '✗ no editor', 'alert')
}

/** A press on a `file:` link the pane drew: opens its place. */
async function openLink(io: Port, href: string, surface?: RenderSurface): Promise<void> {
  const loc = locOf(href)
  if (loc !== null) await openLocation(io, loc, surface)
}

/** `e`: opens the file of what the detail shows, else of the marked row, whatever list the marker is in. */
async function openEditTarget(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const loc = input === null ? null : editTarget(input)
  if (loc !== null) await openLocation(io, loc, surface)
}

/** Copies a command the band offers (the allow-root command it could only name part of). */
export async function copyCommand(io: Port, command: string, surface?: RenderSurface): Promise<void> {
  const copied = await io.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  io.ui.toast(copied.isCopied ? `Copied ${command}` : `Could not copy the command: ${copied.reason ?? 'the surface refused'}`)
}

/** Copies the command for the tests that reach this session's changes. */
async function copyTestCommand(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const command = input?.changes.command ?? null
  if (command === null) return
  const copied = await io.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  const tests = input?.changes.tests.length ?? 0
  await say(io, copied.isCopied ? `✓ copied the command for ${tests === 1 ? '1 test' : `${tests} tests`}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
}

/** Opens a component the detail lists (who uses it, what it uses): the detail moves to that one. */
async function openRelated(io: Port, index: number): Promise<void> {
  const item = (await currentList(io))[index]
  if (item !== undefined) await showComponent(io, { name: item.canonical, label: item.name })
}

/**
 * Moves the focus into the field `id` once it is drawn, under its own key
 * ({@link fieldKey}): the call waits for the drawing that brings the field,
 * so it runs on a timer, never inside the press or a render.
 */
function focusField(io: Port, id: string): void {
  io.clock.after(0, () => void io.ui.focus({ requestId: PANE, key: fieldKey(id) }).catch(() => undefined))
}

/** Opens the hubs filter's field and moves the focus into it once it is drawn. */
async function openFilter(io: Port): Promise<void> {
  await io.state.view.update((v): KnossosView => ({ ...v, tab: 'hubs', filtering: true }))
  focusField(io, 'filter')
}

/** The filter as typed, applied at once; the marker goes back to the top of the shorter list. */
async function typeFilter(io: Port, text: string): Promise<void> {
  await io.state.view.update(v => ({ ...v, filter: text, selected: 0 }))
}

/** Enter in the filter field: keep the text (an empty one clears the filter) and close the field. */
async function submitFilter(io: Port, text: string): Promise<void> {
  await io.state.view.update(v => ({ ...v, filter: text.trim(), filtering: false, selected: 0 }))
}

/**
 * Opens the finder over the pane and moves the focus into its field once it
 * is drawn. What was typed last is kept, so a second look picks up where the
 * first left off.
 */
async function openFinder(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.finding !== true) mod.findFrom = v.selected
  await io.state.view.update((w): KnossosView => ({ ...w, finding: true, filtering: false, selected: 0 }))
  focusField(io, 'find')
}

/** Closes the finder: the tab (or the detail) under it comes back with its marker where it stood. */
async function closeFinder(io: Port): Promise<void> {
  mod.openWhenFound = false
  await io.state.view.update((v): KnossosView => (v.finding === true ? { ...v, finding: false, picking: null, selected: mod.findFrom } : v))
}

/** What was typed into the finder, kept at once; the search for it runs after a short pause in the typing. */
async function typeQuery(io: Port, text: string): Promise<void> {
  await io.state.search.update((f): SearchState => ({ ...f, query: text }))
  await io.state.view.update(v => ({ ...v, selected: 0 }))
  requestSearch(io)
}

/** Enter in the finder: opens the marked match once the answer for what is typed is in; an empty field closes the finder. */
async function submitQuery(io: Port, text: string): Promise<void> {
  if (text.trim() === '') return closeFinder(io)
  const found = await io.state.search.read()
  if (found.query !== text) await io.state.search.update((f): SearchState => ({ ...f, query: text }))
  if (found.for === text.trim() && found.phase === 'idle') return openRow(io)
  mod.openWhenFound = true
  requestSearch(io)
}

/** Asks for the matches of what is typed after {@link SEARCH_DEBOUNCE_MS}, one search at a time. */
function requestSearch(io: Port): void {
  if (mod.disabled) return
  mod.searchTimer?.cancel()
  mod.searchTimer = io.clock.after(SEARCH_DEBOUNCE_MS, () => {
    mod.searchTimer = null
    void (mod.searchFlight ??= new SingleFlight(() => loadSearch(io))).request().catch(() => undefined)
  })
}

/** One search for what is typed now; stored with the query it answers, so a stale answer is never shown as current. */
async function loadSearch(io: Port): Promise<void> {
  if (mod.disabled) return
  const query = (await io.state.search.read()).query.trim()
  if (query === '') {
    await io.state.search.update((f): SearchState => ({ ...f, for: '', phase: 'idle', answer: null }))
    return
  }
  await io.state.search.update((f): SearchState => ({ ...f, phase: 'searching' }))
  const parsed = parseGraphSearch(await wrapper(io, 'graph-search', [`--query=${query}`], SEARCH_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.search.update((f): SearchState => ({ ...f, for: query, phase: 'idle', answer: parsed }))
  // Enter was pressed before this answer landed: its first match opens now, if the field still says the same.
  if (mod.openWhenFound && (await io.state.search.read()).query.trim() === query) {
    mod.openWhenFound = false
    if (parsed?.status === 'ok' && parsed.results.length > 0) await openRow(io, 0)
  }
}

/** Copies the marked row's canonical name (a file's path) onto the clipboard of the surface the press came from. */
async function copySubject(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject === null) return
  // A cycle copies its chain, said by its name in the toast; anything else its full name.
  const text = subject.copy ?? subject.canonical
  const copied = await io.ui.copy({ text, ...(surface === undefined ? {} : { surface }) })
  await say(io, copied.isCopied ? `✓ copied ${subject.name}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
}

/**
 * "Ask Claude" about the marked component (a cycle: how to break it; a
 * boundary: what its dependencies are for). One of the two prompts the mod
 * submits, both only from the person's press (the other is the no-data
 * pane's scan, {@link askScan}): the person asked for it, so it does not
 * start a turn on the mod's own account. One press, one prompt.
 */
async function askClaude(io: Port): Promise<void> {
  const input = await currentInput(io, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject !== null) await io.prompt.submit({ text: subject.ask ?? askPrompt(subject.canonical) })
}

/**
 * The no-data pane's `q`: asks Claude to scan the project, as "Ask Claude"
 * does, only on the person's press, and only while knossos says the
 * project was never scanned. A press that lands after the pane moved on (a
 * load started, or one failed) sends nothing.
 */
async function askScan(io: Port): Promise<void> {
  const d = await io.state.dashboard.read()
  if (d?.status === 'unscanned' && noGraphOf(d, await io.state.refresh.read()) === 'unscanned') await io.prompt.submit({ text: SCAN_PROMPT })
}

/** `a`: asks the person to confirm allowing the refused root. Runs nothing. */
async function offerAllow(io: Port): Promise<void> {
  const refused = refusedRoot(await io.state.brief.read(), await io.state.rescan.read())
  const current = await io.state.allow.read()
  const root = refused?.root ?? (current.phase === 'failed' ? current.root : null)
  if (root === null || current.phase === 'running') return
  await io.state.allow.update((): AllowState => ({ phase: 'confirming', root, reason: null }))
}

/**
 * `y` on the question: allows the root it asked about, once the press has
 * resolved. Nothing runs unless the pane is asking; a second press while a
 * run is queued or under way adds nothing.
 */
async function confirmAllow(io: Port): Promise<void> {
  const asked = await io.state.allow.read()
  if (asked.phase !== 'confirming' || asked.root === null || mod.allowing) return
  mod.allowing = true
  const root = asked.root
  await io.state.allow.update((): AllowState => ({ phase: 'running', root, reason: null }))
  io.clock.after(0, () => {
    void runAllow(io, root)
      .catch(() => undefined)
      .finally(() => {
        mod.allowing = false
      })
  })
}

/**
 * Runs `knossos allow-root <root> --execute` through the wrapper, which
 * writes the roots file baked in at install. Allowed, the turn's scan runs
 * at once so the band and pane pick the project up.
 */
async function runAllow(io: Port, root: string): Promise<void> {
  const parsed = parseAllowRoot(await wrapper(io, 'allow-root', [], ALLOW_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') {
    await io.state.allow.update((): AllowState => ({ phase: 'idle', root: null, reason: null }))
    await disable(io)
    return
  }
  if (parsed === null) {
    await io.state.allow.update((): AllowState => ({ phase: 'failed', root, reason: 'knossos did not allow it' }))
    return
  }
  await io.state.allow.update((): AllowState => ({ phase: 'done', root, reason: null }))
  await io.state.rescan.update((r): RescanState => ({ ...r, refusedRoot: null }))
  // A watcher refused for this root may watch it now, once the scan below lands a dashboard.
  mod.watchRefused = false
  // The edits that were refused are still unscanned: the turn's scan picks them up now.
  void (mod.flight ??= new SingleFlight(() => scanSafely(io))).request()
}

/**
 * Where a focus ring headed for a tab's hidden hotkey twin (`tabkey:<id>`)
 * goes instead: onto its visible tab, or, when it comes from that tab (a
 * backward walk, as the twin sits just before it), onto the tab before; null
 * keeps it where it is (backward past the first tab). Undefined for any
 * other element.
 */
export function twinTarget(element: string): string | null | undefined {
  if (!element.startsWith('tabkey:')) return undefined
  const id = element.slice('tabkey:'.length)
  if (mod.focused !== `tab:${id}`) return `tab:${id}`
  const before = TABS[TABS.findIndex(t => t.id === id) - 1]
  return before === undefined ? null : `tab:${before.id}`
}

/** What a press on the pane does, then the couplings of the cell it leaves marked, when it marks one. */
async function pressPane(io: Port, id: string, surface?: RenderSurface): Promise<void> {
  await pressAction(io, id, surface)
  await request(io, 'couplings')
  await request(io, 'branch')
  await request(io, 'peek')
  await request(io, 'churn')
}

/** A press in the detail beside the tab (`peek:N`): opens what it lists as the detail itself. */
async function openPeeked(io: Port, index: number): Promise<void> {
  const input = await currentInput(io, true)
  const item = input?.peek === null || input?.peek === undefined ? undefined : peekList(input.peek)[index]
  if (item !== undefined) await showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
}

/**
 * `p` on a component's detail: the finder opens to pick where a route from
 * it ends; the component it opens on is the other end. Only components are
 * offered.
 */
async function startRoute(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.inspect === null || v.inspect.file === true || (v.route ?? null) !== null) return
  const from = v.inspect
  await io.state.view.update((w): KnossosView => ({ ...w, picking: from }))
  await openFinder(io)
}

/** The route from the component picked with `p` to `to`, drawn instead of the detail; `b` goes back to it. */
async function showRoute(io: Port, to: Inspected): Promise<void> {
  const v = await io.state.view.read()
  const from = v.picking ?? null
  if (from === null) return
  mod.openWhenFound = false
  await io.state.view.update((w): KnossosView => ({ ...w, finding: false, picking: null, selected: 0, route: { from, to, index: 0, back: w.inspect } }))
  await request(io, 'route')
}

/**
 * `m` on a component's detail: a field opens in its notes card, holding the
 * component's note when it has one, and takes the focus once it is drawn.
 * Nothing is written by this, nor by typing.
 */
async function startNote(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.inspect === null || v.inspect.file === true) return
  const stored = await io.state.detail.read()
  const component = stored?.detail?.component?.name ?? v.inspect.name
  const existing = stored?.detail?.component?.annotations?.find(a => a.kind === 'note')?.value ?? ''
  await io.state.note.update((): NoteState => ({ component, phase: 'editing', value: existing, previous: null, reason: null }))
  focusField(io, 'note')
}

/** What was typed into the note's field, kept as it is typed. */
async function typeNote(io: Port, text: string): Promise<void> {
  await io.state.note.update((n): NoteState | null => (n !== null && n.phase === 'editing' ? { ...n, value: text } : n))
}

/**
 * Enter in the note's field: knossos checks the note (a preview, nothing
 * written) and the card asks before recording it. An empty field drops it.
 */
async function submitNote(io: Port, text: string): Promise<void> {
  const asked = await io.state.note.read()
  if (asked === null || asked.phase !== 'editing') return
  const value = text.trim()
  if (value === '') {
    await io.state.note.update(() => null)
    return
  }
  await io.state.note.update((): NoteState => ({ ...asked, phase: 'previewing', value }))
  io.clock.after(0, () => void previewNote(io, asked.component, value).catch(() => undefined))
}

/** The preview of a note: what it would replace, or why knossos refuses it. Writes nothing. */
async function previewNote(io: Port, component: string, value: string): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper(io, 'annotate', [`--component=${component}`, `--value=${value}`], NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.note.update((n): NoteState | null => {
    if (n === null || n.component !== component || n.value !== value || n.phase !== 'previewing') return n
    if (parsed?.status === 'ok' && parsed.executed === false) return { ...n, phase: 'confirming', previous: parsed.previous ?? null }
    return { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' }
  })
}

/**
 * `y` on the card's question: records the note, then reads the detail
 * again so it shows it. Only from the question: a press at any other moment
 * writes nothing.
 */
async function confirmNote(io: Port): Promise<void> {
  const asked = await io.state.note.read()
  if (asked === null || asked.phase !== 'confirming') return
  await io.state.note.update((): NoteState => ({ ...asked, phase: 'saving' }))
  io.clock.after(0, () => void recordNote(io, asked.component, asked.value).catch(() => undefined))
}

/** Records a confirmed note; the detail is read again once it is. */
async function recordNote(io: Port, component: string, value: string): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper(io, 'annotate', [`--component=${component}`, `--value=${value}`, '--execute'], NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  if (parsed?.status !== 'ok' || parsed.executed !== true) {
    await io.state.note.update((n): NoteState | null => (n !== null && n.component === component ? { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' } : n))
    return
  }
  await io.state.note.update(() => null)
  await say(io, `✓ noted on ${component.slice(component.lastIndexOf('\\') + 1)}`, 'ok')
  const shown = (await io.state.view.read()).inspect
  if (shown === null) return
  // The stored detail predates the note: read it again.
  await io.state.detail.update(() => null)
  await requestDetail(io, shown)
}

/** `l`, or a press on one of what the marked boundary depends on: the heat map cell moves to that column. */
async function moveCell(io: Port, to: number | null): Promise<void> {
  const input = await currentInput(io, true)
  const boundaries = input?.boundaries ?? null
  if (input === null || boundaries === null) return
  const marked = Math.min(Math.max(0, input.selected), boundaries.boundaries.length - 1)
  const target = to === null ? nextTarget(boundaries, marked, input.target) : (boundaries.boundaries[to]?.name ?? null)
  if (target !== null) await io.state.view.update(v => ({ ...v, target }))
}

/** `o` on a cycle's fold (`unfold:<cycle>:<row>`), or a press on its box (`fold:…`): the cycle shows every member, the marker on the first it hid. */
async function unfoldCycle(io: Port, id: string): Promise<void> {
  const [cycle, row] = id.slice(id.indexOf(':') + 1).split(':').map(Number)
  if (!Number.isInteger(cycle) || !Number.isInteger(row)) return
  await io.state.view.update(v => ({ ...v, unfolded: [...new Set([...(v.unfolded ?? []), cycle!])], selected: row! }))
}

/**
 * A press on the detail's diff: `change` given how far it is opened now,
 * for the diff on show. Opened on another diff (another file, commit or
 * snapshot), it starts closed. Nothing without a diff that has landed.
 */
async function foldDiff(io: Port, change: (fold: DiffFold) => DiffFold): Promise<void> {
  const shown = (await io.state.view.read()).inspect
  const state = await io.state.fileDiff.read()
  if (shown === null || shown.file !== true || state === null || state.name !== shown.name || state.phase !== 'done') return
  const fresh: DiffFold = { name: state.name, rev: state.rev, snapshot: state.snapshot, open: [], from: 0 }
  await io.state.diffFold.update(f => change(f !== null && f.name === fresh.name && f.rev === fresh.rev && f.snapshot === fresh.snapshot ? f : fresh))
}

/**
 * "N more lines" under a folded hunk: the hunk shows whole. Opened, it may
 * no longer fit the page after the hunks before it; the page then starts
 * at it, so the press never hides what it opened.
 */
async function openHunk(io: Port, index: number): Promise<void> {
  if (!Number.isInteger(index) || index < 0) return
  const shown = (await io.state.view.read()).inspect
  const state = await io.state.fileDiff.read()
  const rev = await io.state.sessionRev.read()
  await foldDiff(io, fold => {
    const opened: DiffFold = { ...fold, open: fold.open.includes(index) ? fold.open : [...fold.open, index] }
    const view = shown === null ? null : diffView(shown, state, rev, opened)
    if (view?.phase !== 'diff' || index >= view.hunks.length) return fold
    return diffPage(view).drawn.some(d => d.index === index) ? opened : { ...opened, from: index }
  })
}

/** "N more changes" or "N earlier changes": the page starts at the hunk named. */
async function pageDiff(io: Port, from: number): Promise<void> {
  if (!Number.isInteger(from) || from < 0) return
  await foldDiff(io, fold => ({ ...fold, from }))
}

/**
 * The count of changed files no test reaches, or `u`: on Changes it lists
 * only those files, the marker on the first, and pressed again lists every
 * file; anywhere else (the Overview's session card) it opens Changes with
 * the list narrowed so.
 */
async function toggleUntested(io: Port): Promise<void> {
  const v = await io.state.view.read()
  const onChanges = v.tab === 'changes' && v.inspect === null && (v.route ?? null) === null && v.finding !== true
  if (onChanges && (await io.state.untestedOnly.read())) return showAllFiles(io)
  await io.state.untestedOnly.update(() => true)
  await io.state.view.update((w): KnossosView => ({ ...w, tab: 'changes', inspect: null, route: null, selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
}

/** Every changed file listed again, the marker kept on the file it was on. */
async function showAllFiles(io: Port): Promise<void> {
  if (!(await io.state.untestedOnly.read())) return
  const marked = (await currentList(io))[(await io.state.view.read()).selected]?.canonical
  await io.state.untestedOnly.update(() => false)
  const at = marked === undefined ? -1 : (await currentList(io)).findIndex(item => item.canonical === marked)
  await io.state.view.update(v => ({ ...v, selected: Math.max(0, at) }))
}

/** Where each figure that counts a set is listed: its tab, and the place of its first item there (null: the top). */
const STAT_LISTS: Readonly<Record<string, PaneTab>> = { cycles: 'cycles', components: 'hubs', boundaries: 'boundaries', boundary: 'boundaries', dead: 'issues', policy: 'issues', diagnostics: 'issues' }

/**
 * A figure that counts a set (an Overview tile, the violations a session
 * introduced): opens the tab that lists it, the marker on its first item.
 * On Issues the dead code follows the policy violations in the walk.
 */
async function openStat(io: Port, key: string): Promise<void> {
  if (!Object.hasOwn(STAT_LISTS, key)) return
  const tab = STAT_LISTS[key]!
  const d = await io.state.dashboard.read()
  const selected = key === 'dead' && d?.status === 'ok' ? (d.policy?.items?.length ?? 0) : 0
  await io.state.view.update((v): KnossosView => ({ ...v, tab, inspect: null, route: null, selected, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
}

/** What one press does: `rest` is what follows the colon of a prefixed id (`row:3` gives `3`), `id` the whole id. */
type Press = (io: Port, rest: string, surface: RenderSurface | undefined, id: string) => unknown

/** A view change a press makes, as a press. */
const viewing = (change: (v: KnossosView, rest: string) => KnossosView): Press => (io, rest) => io.state.view.update(v => change(v, rest))

/**
 * What a press on the pane does, by the pressed element's id: an id that
 * names an action outright (`find`), or the part of one before its first
 * colon, the colon kept (`row:`), for an id that carries what it acts on.
 * No outright id holds a colon, so the two never meet.
 */
export const PRESSES: ReadonlyMap<string, Press> = new Map<string, Press>([
  [
    'tab:',
    async (io, rest) => {
      const tab = rest as PaneTab
      if (TABS.some(t => t.id === tab)) await io.state.view.update(v => ({ ...v, tab, selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
    },
  ],
  ['find', io => openFinder(io)],
  ['find-close', io => closeFinder(io)],
  ['target', io => moveCell(io, null)],
  ['cell:', (io, rest) => moveCell(io, Number(rest))],
  ['drift', viewing(v => ({ ...v, drift: v.drift !== true, selected: 0 }))],
  [
    'row:',
    async (io, rest) => {
      // A boundary has nothing to open: pressing one marks it, starting on what it depends on most.
      if ((await io.state.view.read()).tab === 'boundaries') await io.state.view.update(v => ({ ...v, target: undefined }))
      return openRow(io, Number(rest))
    },
  ],
  ['rel:', (io, rest) => openRelated(io, Number(rest))],
  ['route', io => startRoute(io)],
  ['route-pick:', viewing((v, rest) => (v.route === null || v.route === undefined ? v : { ...v, route: { ...v.route, index: Math.max(0, Number(rest) || 0) }, selected: 0 }))],
  ['note', io => startNote(io)],
  ['note-yes', io => confirmNote(io)],
  ['note-no', io => io.state.note.update(() => null)],
  ['peek:', (io, rest) => openPeeked(io, Number(rest))],
  // On Cycles the layout names the row `j` and `k` (and a cycle's line in the list) move to: what the diagram shows depends on its width.
  ['next:', viewing((v, rest) => ({ ...v, selected: Math.max(0, Number(rest) || 0) }))],
  ['unfold:', (io, _, __, id) => unfoldCycle(io, id)],
  ['down', io => moveSelection(io, 1)],
  ['up', io => moveSelection(io, -1)],
  ['open', io => openRow(io)],
  ['edit', (io, _, surface) => openEditTarget(io, surface)],
  ['tests', (io, _, surface) => copyTestCommand(io, surface)],
  // The detail's diff: a folded hunk shown whole, the page of hunks moved, and from the panel beside a tab the full detail.
  ['diff-more:', (io, rest) => openHunk(io, Number(rest))],
  ['diff-from:', (io, rest) => pageDiff(io, Number(rest))],
  ['diff-open', io => openRow(io)],
  // The changed files no test reaches: listed alone (`u`, or a press on their count), and every file again.
  ['untested', io => toggleUntested(io)],
  ['untested-all', io => showAllFiles(io)],
  ['stat:', (io, rest) => openStat(io, rest)],
  // The line under a cut list: the marker onto the item it names, and the window with it. A boundary marked so starts on what it depends on most.
  ['more:', viewing((v, rest) => (Number.isInteger(Number(rest)) && rest !== '' && Number(rest) >= 0 ? { ...v, selected: Number(rest), target: undefined } : v))],
  // Back from a route goes to the detail it was picked from; from a detail, to the tab.
  ['back', viewing(v => ((v.route ?? null) !== null ? { ...v, route: null, selected: 0 } : { ...v, inspect: null, selected: v.opened ?? 0 }))],
  ['keys', viewing(v => ({ ...v, showKeys: !v.showKeys }))],
  ['filter', io => openFilter(io)],
  ['clear', viewing(v => ({ ...v, filter: '', filtering: false, degree: null, selected: 0 }))],
  ['sort', viewing(v => ({ ...v, sort: SORTS[(SORTS.indexOf(v.sort) + 1) % SORTS.length] ?? 'in', selected: 0 }))],
  ['rescan', io => requestRescan(io)],
  ['copy', (io, _, surface) => copySubject(io, surface)],
  ['ask', io => askClaude(io)],
  // The no-data pane's one action: the person's press sends it, as "Ask Claude" does.
  ['scan-ask', io => askScan(io)],
  ['allow', io => offerAllow(io)],
  ['allow-yes', io => confirmAllow(io)],
  ['allow-no', io => io.state.allow.update((a): AllowState => (a.phase === 'confirming' ? { phase: 'idle', root: null, reason: null } : a))],
])

/** Ids that do what another does: a tab's hidden hotkey twin, the drift line's second button, the Cycles moves and folds, and the untested count beside `u`. */
const SAME_AS: Readonly<Record<string, string>> = { 'tabkey:': 'tab:', drifted: 'drift', 'prev:': 'next:', 'mark:': 'next:', 'fold:': 'unfold:', 'untested-row': 'untested', 'untested-none': 'untested' }

/** Which entry of {@link PRESSES} a press on `id` runs, and what follows the colon; null for an id that does nothing. */
export function pressOf(id: string): { key: string; rest: string } | null {
  const cut = id.indexOf(':')
  const named = cut < 0 ? id : id.slice(0, cut + 1)
  const key = Object.hasOwn(SAME_AS, named) ? SAME_AS[named]! : named
  return PRESSES.has(key) ? { key, rest: cut < 0 ? '' : id.slice(cut + 1) } : null
}

/** What a press on the pane does, by the pressed element's id; `surface` is where the press came from. */
async function pressAction(io: Port, id: string, surface?: RenderSurface): Promise<unknown> {
  const press = pressOf(id)
  return press === null ? undefined : PRESSES.get(press.key)!(io, press.rest, surface, id)
}

/** Into the field `field` (the finder's, a note's, the hubs filter's): what was typed, kept at once. */
function typeInto(io: Port, field: string, text: string): Promise<void> {
  return field === 'find' ? typeQuery(io, text) : field === 'note' ? typeNote(io, text) : typeFilter(io, text)
}

/** Enter in the field `field`. */
function submitFrom(io: Port, field: string, text: string): Promise<void> {
  return field === 'find' ? submitQuery(io, text) : field === 'note' ? submitNote(io, text) : submitFilter(io, text)
}

/** What the pane's elements do, for the pane drawn through `io`: each action's failure (a teardown under it) is swallowed. */
export function handlersOf(io: Port): Handlers {
  return {
    press: (id, surface) => void pressPane(io, id, surface).catch(() => undefined),
    type: (field, value) => void typeInto(io, field, value).catch(() => undefined),
    submit: (field, value) => void submitFrom(io, field, value).catch(() => undefined),
    link: (href, surface) => void openLink(io, href, surface).catch(() => undefined),
  }
}
