#!/usr/bin/env node
/**
 * Dev-only: draws the Knossos pane as PNGs, the way a terminal would show it,
 * so the look can be judged without a live Claude Code session.
 *
 * Never installed with the plugin (the installer copies a fixed list of
 * files from hooks/) and never run by the quality gate. It needs Node with
 * TypeScript type stripping (22.18+) and `rsvg-convert` with a monospace font
 * (DejaVu Sans Mono).
 *
 * It lays the pane out with the mod's own pure functions (hooks/lib) over
 * this repository's real dashboard, read through the mod's wrapper, and draws
 * every tab plus a component's detail at 60 and 100 columns in Claude Code's
 * dark and light themes. Rows the terminal draws as a `Raster` are drawn
 * from the packed cells exactly as the engine would receive them.
 *
 * Not read-only. `dashboard` and `component-detail` never scan, but the
 * dashboard writes trend cache rows (`snapshot_metrics`) and brings an older
 * schema up to date. So the data dir is never defaulted: `--data-dir` is
 * required, and should hold a copy of the database made for the preview,
 * deleted after it.
 *
 * Usage:
 *   node tools/pane-preview.mjs --data-dir=<dir> [--out=<dir>] [--project=<dir>]
 *                               [--dashboard=<file.json>] [--columns=60,100] [--themes=dark,light]
 *                               [--only=<view,...>]
 *   node tools/pane-preview.mjs --readme --data-dir=<dir> [--out=<dir>]
 *
 * Defaults: --out=.superpowers/sdd/2026-10-02-claude-code-mod/preview, the
 * repository as the project.
 *
 * `--readme` draws the README's screenshots instead: a few views at one
 * width, each in a terminal window frame (a title bar, rounded corners, a
 * soft shadow on a transparent margin) at twice the size for sharp text, to
 * docs/images/claude-code-mod/, shrunk to a 256-colour palette when python3
 * with Pillow is there. The Last turn and Changes figures in them
 * come from a sample turn over this repository's real files.
 */
import { Buffer } from 'node:buffer'
import { spawnSync } from 'node:child_process'
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { basename as baseName, dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const args = Object.fromEntries(
  process.argv.slice(2).map(a => {
    const [k, ...v] = a.replace(/^--/, '').split('=')
    return [k, v.join('=')]
  }),
)
// Checked before anything loads or runs: the dashboard writes to the database it reads.
if (typeof args['data-dir'] !== 'string' || args['data-dir'] === '') {
  console.error('pane-preview: --data-dir=<dir> is required (a copy of the database: the dashboard writes trend cache rows to it)')
  process.exit(2)
}

// The mod's modules import each other without an extension, as the engine's bundler resolves them.
const { registerHooks } = await import('node:module')
registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context)
    } catch (error) {
      if (/^\.\.?\//.test(specifier) && !/\.[a-z]+$/.test(specifier)) return nextResolve(`${specifier}.ts`, context)
      throw error
    }
  },
})

const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const layout = await import(join(REPO, 'hooks/lib/layout.ts'))
const envelopes = await import(join(REPO, 'hooks/lib/envelopes.ts'))
const raster = await import(join(REPO, 'hooks/lib/raster.ts'))
const rows = await import(join(REPO, 'hooks/lib/rows.ts'))
const band = await import(join(REPO, 'hooks/lib/band.ts'))
const palette = await import(join(REPO, 'hooks/lib/palette.ts'))

const README = 'readme' in args
const OUT = resolve(args.out ?? join(REPO, README ? 'docs/images/claude-code-mod' : '.superpowers/sdd/2026-10-02-claude-code-mod/preview'))
const PROJECT = resolve(args.project ?? REPO)
const DATA_DIR = resolve(args['data-dir'])
const COLUMNS = (args.columns ?? '60,100').split(',').map(Number)
const THEME_NAMES = (args.themes ?? 'dark,light').split(',')

/** Claude Code 2.1.288's dark and light themes, as `#rrggbb` (from the binary's theme objects). */
const THEMES = {
  dark: {
    autoAccept: '#af87ff', autoAcceptShimmer: '#d0b4ff', skill: '#af87ff', bashBorder: '#fd5db1',
    claude: '#d77757', claudeShimmer: '#eb9f7f', claudeBlue_FOR_SYSTEM_SPINNER: '#93a5ff',
    claudeBlueShimmer_FOR_SYSTEM_SPINNER: '#b1c3ff', permission: '#b1b9f9', permissionShimmer: '#cfd7ff',
    planMode: '#48968c', ide: '#4782c8', promptBorder: '#888888', promptBorderShimmer: '#a6a6a6', text: '#ffffff',
    inverseText: '#000000', inactive: '#999999', inactiveShimmer: '#c1c1c1', subtle: '#505050',
    suggestion: '#b1b9f9', remember: '#b1b9f9', background: '#00cccc', success: '#4eba65', error: '#ff6b80',
    warning: '#ffc107', merged: '#af87ff', warningShimmer: '#ffdf39', diffAdded: '#225c2b',
    diffRemoved: '#7a2936', diffAddedDimmed: '#47584a', diffRemovedDimmed: '#69484d', diffAddedWord: '#38a660',
    diffRemovedWord: '#b3596b', red_FOR_SUBAGENTS_ONLY: '#dc2626', blue_FOR_SUBAGENTS_ONLY: '#6a9bcc',
    green_FOR_SUBAGENTS_ONLY: '#16a34a', yellow_FOR_SUBAGENTS_ONLY: '#ca8a04',
    purple_FOR_SUBAGENTS_ONLY: '#827dbd', orange_FOR_SUBAGENTS_ONLY: '#d97757',
    pink_FOR_SUBAGENTS_ONLY: '#c46686', cyan_FOR_SUBAGENTS_ONLY: '#0891b2', professionalBlue: '#6a9bcc',
    chromeYellow: '#fbbc04', clawd_body: '#d77757', clawd_background: '#000000', userMessageBackground: '#373737',
    userMessageBackgroundHover: '#464646', composerSidebarBackground: '#262626', selectionBg: '#264f78',
    bashMessageBackgroundColor: '#413c41', memoryBackgroundColor: '#374146', rate_limit_fill: '#b1b9f9',
    rate_limit_empty: '#505370', fastMode: '#ff7814', fastModeShimmer: '#ffa546', effortUltra: '#af87ff',
    briefLabelYou: '#7ab4e8', briefLabelClaude: '#d77757', rainbow_red: '#eb5f57', rainbow_orange: '#f58b57',
    rainbow_yellow: '#fac35f', rainbow_green: '#91c882', rainbow_blue: '#82aadc', rainbow_indigo: '#9b82c8',
    rainbow_violet: '#c882b4', rainbow_red_shimmer: '#fa9b93', rainbow_orange_shimmer: '#ffb989',
    rainbow_yellow_shimmer: '#ffe19b', rainbow_green_shimmer: '#b9e6b4', rainbow_blue_shimmer: '#b4cdf0',
    rainbow_indigo_shimmer: '#c3b4e6', rainbow_violet_shimmer: '#e6b4d2',
  },
  light: {
    autoAccept: '#8700ff', autoAcceptShimmer: '#d0b4ff', skill: '#8700ff', bashBorder: '#ff0087',
    claude: '#d77757', claudeShimmer: '#f59575', claudeBlue_FOR_SYSTEM_SPINNER: '#5769f7',
    claudeBlueShimmer_FOR_SYSTEM_SPINNER: '#7587ff', permission: '#5769f7', permissionShimmer: '#899bff',
    planMode: '#006666', ide: '#4782c8', promptBorder: '#8a8a8a', promptBorderShimmer: '#b7b7b7', text: '#000000',
    inverseText: '#ffffff', inactive: '#666666', inactiveShimmer: '#8e8e8e', subtle: '#afafaf',
    suggestion: '#5769f7', remember: '#0000ff', background: '#009999', success: '#2c7a39', error: '#ab2b3f',
    warning: '#966c1e', merged: '#8700ff', warningShimmer: '#c89e50', diffAdded: '#69db7c',
    diffRemoved: '#ffa8b4', diffAddedDimmed: '#c7e1cb', diffRemovedDimmed: '#fdd2d8', diffAddedWord: '#2f9d44',
    diffRemovedWord: '#d1454b', red_FOR_SUBAGENTS_ONLY: '#dc2626', blue_FOR_SUBAGENTS_ONLY: '#6a9bcc',
    green_FOR_SUBAGENTS_ONLY: '#16a34a', yellow_FOR_SUBAGENTS_ONLY: '#ca8a04',
    purple_FOR_SUBAGENTS_ONLY: '#827dbd', orange_FOR_SUBAGENTS_ONLY: '#d97757',
    pink_FOR_SUBAGENTS_ONLY: '#c46686', cyan_FOR_SUBAGENTS_ONLY: '#0891b2', professionalBlue: '#6a9bcc',
    chromeYellow: '#fbbc04', clawd_body: '#d77757', clawd_background: '#000000', userMessageBackground: '#f0f0f0',
    userMessageBackgroundHover: '#fcfcfc', composerSidebarBackground: '#f5f5f5', selectionBg: '#b4d5ff',
    bashMessageBackgroundColor: '#faf5fa', memoryBackgroundColor: '#e6f5fa', rate_limit_fill: '#5769f7',
    rate_limit_empty: '#272f6f', fastMode: '#ff6a00', fastModeShimmer: '#ff9632', effortUltra: '#8700ff',
    briefLabelYou: '#2563eb', briefLabelClaude: '#d77757', rainbow_red: '#eb5f57', rainbow_orange: '#f58b57',
    rainbow_yellow: '#fac35f', rainbow_green: '#91c882', rainbow_blue: '#82aadc', rainbow_indigo: '#9b82c8',
    rainbow_violet: '#c882b4', rainbow_red_shimmer: '#fa9b93', rainbow_orange_shimmer: '#ffb989',
    rainbow_yellow_shimmer: '#ffe19b', rainbow_green_shimmer: '#b9e6b4', rainbow_blue_shimmer: '#b4cdf0',
    rainbow_indigo_shimmer: '#c3b4e6', rainbow_violet_shimmer: '#e6b4d2',
  },
}

/** What the terminal itself draws with: its default text and background under each theme. */
const TERMINAL = {
  dark: { fg: '#d4d4d4', bg: '#1e1e1e' },
  light: { fg: '#1f1f1f', bg: '#ffffff' },
}

const CELL_W = 9
const CELL_H = 19
const FONT_SIZE = 15
const BASELINE = 14
const PAD_X = 2
const PAD_Y = 1

// ---------------------------------------------------------------- data

function wrapper(sub, ...rest) {
  const run = spawnSync('sh', [join(REPO, 'hooks/scripts/knossos-run.sh'), sub, PROJECT, ...rest], {
    env: { ...process.env, KNOSSOS_DATA_DIR: DATA_DIR },
    encoding: 'utf8',
    timeout: 60_000,
  })
  return run.stdout ?? ''
}

const dashboardText = args.dashboard ? readFileSync(args.dashboard, 'utf8') : wrapper('dashboard')
const dashboard = envelopes.parseDashboard(dashboardText)
if (dashboard === null || dashboard.status !== 'ok') {
  console.error(`no dashboard for ${PROJECT} (data dir ${DATA_DIR}): ${dashboardText.slice(0, 200)}`)
  process.exit(1)
}

/** A turn that edited the two files most depended on: real files, made-up turn. */
function sampleBrief(d) {
  const files = (d.fan_in ?? []).slice(0, 3)
  return {
    status: 'ok',
    project_root: d.project_root,
    project_id: d.project_id,
    snapshot_id: d.snapshot_id,
    scanned_at: 0,
    scan_ms: 40,
    reason: null,
    roots_file: null,
    refused_root: null,
    path: d.path,
    changed_files: files.map(f => f.path),
    added_files: [],
    deleted_files: [],
    impact: Object.fromEntries(files.map(f => [f.path, f])),
    tests: [{ path: 'tests/phpunit/Query/DashboardServiceTest.php', distance: 1 }],
    policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
  }
}

function detailOf(d) {
  const top = layout.mergeRanked(d)[0]
  if (top === undefined) return null
  const shown = { name: top.canonical, label: top.name }
  const answer = envelopes.parseComponentDetail(wrapper('component-detail', top.canonical))
  return layout.detailInput(shown, { snapshot_id: d.snapshot_id, name: top.canonical, detail: answer, phase: 'done' }, d.project_root)
}

/**
 * A session of three turns over real files (made-up turns): the sample turn,
 * then one that edits the next most depended on files, adds a file and deletes
 * one, with tests reaching the changes from more than one runner.
 */
function sampleSession(d, first) {
  const files = (d.fan_in ?? []).slice(3, 6)
  // The hooks directory's own boundary, by the name the dashboard gives it, so it takes the colour the other tabs give it.
  const hooks = (d.boundary_matrix?.boundaries ?? []).find(name => /^module:hooks\b/.test(name)) ?? 'module:hooks'
  const second = {
    ...first,
    changed_files: files.map(f => f.path),
    added_files: ['hooks/lib/changes.ts'],
    deleted_files: ['hooks/lib/legacy.ts'],
    impact: { ...Object.fromEntries(files.map(f => [f.path, f])), 'hooks/lib/changes.ts': { path: 'hooks/lib/changes.ts', dependent_files: 2, boundaries: [hooks], boundary: hooks } },
    tests: [
      { path: 'tests/phpunit/Query/DashboardServiceTest.php', distance: 2 },
      { path: 'tests/phpunit/Query/TurnBriefServiceTest.php', distance: 1 },
      { path: 'tests/phpunit/Store/StoreTest.php', distance: 3 },
      { path: 'hooks/lib/changes.spec.ts', distance: 1, js_runner: 'vitest' },
    ],
  }
  return [first, second, first].reduce((s, b) => layout.accumulate(s, b), layout.NO_CHANGES)
}

const NOW = Date.now()
const BASE_VIEW = { inspect: null, isBandHidden: false, tab: 'overview', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }
const IDLE = { phase: 'idle', reason: null }
const FETCHED = { fetchedAt: NOW, failed: false }
const detail = detailOf(dashboard)
const brief = sampleBrief(dashboard)
const session = sampleSession(dashboard, brief)
const root = dashboard.project_root ?? PROJECT
const refused = { ...brief, status: 'not-allowed', refused_root: root, roots_file: join(DATA_DIR, 'roots.json') }

/** The pane for a state: the view over BASE_VIEW, and what else the state holds. */
function pane(view, { turn = null, shown = null, changes = session, refresh = FETCHED, rescan = IDLE, allow = null } = {}) {
  const input = layout.paneInput(dashboard, turn, refresh, rescan, { ...BASE_VIEW, ...view }, NOW, true, shown, allow, changes)
  return columns => layout.paneRows(input, columns)
}

/** The band above the prompt as the mod draws it: the model's text in its tone, then its buttons. */
function bandRows(cases) {
  const tones = { alert: 'error', warn: 'warning', normal: 'inactive' }
  return columns =>
    cases.flatMap(([key, b, job], i) => {
      const model = band.bandModel(b, job, NOW, palette.declaredOf(dashboard))
      if (model === null) return []
      const segments = [{ text: `${model.text} `, color: tones[model.tone] }]
      if (model.showDetails) segments.push(rows.button('details', 'details'), { text: ' ' })
      segments.push(rows.button('hide', 'hide'))
      const row = { key, segments }
      const fitted = rows.rowWidth(row) <= columns ? row : { ...row, segments: rows.clip(segments, columns) }
      return i === 0 ? [fitted] : [{ key: `gap-${key}`, segments: [{ text: ' ' }] }, fitted]
    })
}

/** Claude Code's prompt box under the band, empty, in its border colour. */
function promptRows(columns) {
  const width = Math.max(4, columns)
  const edge = (l, r) => ({ key: `prompt-${l}`, segments: [{ text: `${l}${'─'.repeat(width - 2)}${r}`, color: 'promptBorder' }] })
  return [
    edge('╭', '╮'),
    { key: 'prompt-line', segments: [{ text: '│', color: 'promptBorder' }, { text: ' > ', dim: true }, { text: ' '.repeat(width - 5) }, { text: '│', color: 'promptBorder' }] },
    edge('╰', '╯'),
  ]
}

const scannedNow = { ...brief, scanned_at: Math.floor(NOW / 1000) - 42 }
const violated = {
  ...scannedNow,
  policy: { status: 'evaluated', total: 1, truncated: false, violations: [{ policy_id: 'core-does-not-reach-into-workers', source: 'Knossos\\Query\\PolicyScope', target: 'KnossosPhpScanner\\Worker' }] },
}
const JOB_IDLE = { phase: 'idle', lastAttemptAt: null }

/** Every view the preview draws, by name. */
const VIEWS = [
  ['overview', pane({ tab: 'overview' }, { turn: brief })],
  ['overview-fresh', pane({ tab: 'overview' }, { changes: layout.NO_CHANGES })],
  ['overview-keys', pane({ tab: 'overview', showKeys: true }, { turn: brief })],
  ['hubs', pane({ tab: 'hubs', selected: 1 })],
  ['hubs-filtering', pane({ tab: 'hubs', filtering: true, filter: 'query' })],
  ['hubs-filtered', pane({ tab: 'hubs', filter: 'query' })],
  ['hubs-no-match', pane({ tab: 'hubs', filter: 'zebra' })],
  ['hubs-sort-cross', pane({ tab: 'hubs', sort: 'cross' })],
  ['boundaries', pane({ tab: 'boundaries' })],
  // Marked on the first boundary a policy binds, so the spelled-out block shows what it may not use.
  ['boundaries-marked', pane({ tab: 'boundaries', selected: dashboard.boundary_matrix?.forbidden?.[0]?.[0] ?? 0 })],
  ['cycles', pane({ tab: 'cycles' })],
  ['issues', pane({ tab: 'issues' })],
  ['changes', pane({ tab: 'changes', selected: 1 }, { turn: brief })],
  ['changes-empty', pane({ tab: 'changes' }, { changes: layout.NO_CHANGES })],
  ...(detail === null ? [] : [['detail', pane({ tab: 'hubs' }, { shown: detail })]]),
  ['detail-loading', pane({ tab: 'hubs' }, { shown: { label: 'StableId', loading: true, messages: null, component: null } })],
  ['allow-offer', pane({ tab: 'overview' }, { turn: refused })],
  ['allow-confirm', pane({ tab: 'overview' }, { turn: refused, allow: { phase: 'confirming', root, reason: null } })],
  ['scanning', pane({ tab: 'overview' }, { turn: brief, rescan: { phase: 'scanning', reason: null } })],
  ['refresh-failed', pane({ tab: 'overview' }, { turn: brief, refresh: { fetchedAt: NOW - 600_000, failed: true } })],
  ['rescan-failed', pane({ tab: 'overview' }, { turn: brief, rescan: { phase: 'failed', reason: 'the scan timed out' } })],
  ['no-data', columns => layout.emptyRows(null, columns)],
  ['no-data-refused', columns => layout.emptyRows(layout.allowInput(refused, IDLE, null), columns)],
  ['band-prompt', columns => [...bandRows([['band-ok', scannedNow, JOB_IDLE]])(columns), ...promptRows(columns)]],
  [
    'band',
    bandRows([
      ['band-ok', scannedNow, JOB_IDLE],
      ['band-scanning', scannedNow, { phase: 'scanning', lastAttemptAt: NOW }],
      ['band-violation', violated, JOB_IDLE],
      ['band-failed', scannedNow, { phase: 'failed', lastAttemptAt: NOW }],
      ['band-refused', refused, JOB_IDLE],
    ]),
  ],
]

// ---------------------------------------------------------------- cells

const hex = n => `#${(n & 0xffffff).toString(16).padStart(6, '0')}`
const rgb = c => [1, 3, 5].map(i => parseInt(c.slice(i, i + 2), 16))
const mix = (a, b, t) => {
  const [x, y] = [rgb(a), rgb(b)]
  return `#${x.map((v, i) => Math.round(v + (y[i] - v) * t).toString(16).padStart(2, '0')).join('')}`
}

/** A Text colour as the terminal shows it: a theme key, a raw `#rrggbb`, or the terminal's default. */
function colourOf(colour, theme, term) {
  if (colour === undefined) return term.fg
  if (/^#[0-9a-f]{6}$/i.test(colour)) return colour
  return theme[colour] ?? term.fg
}

/** One cell per column: glyph, foreground, background (null for the terminal's), bold. */
function rowCells(row, theme, term) {
  const out = []
  for (const s of row.segments) {
    if (s.press !== undefined) {
      // A plain Button: the hotkey in the accent, a colon, the label; dimColor dims it all.
      const hot = s.press.hotkey === undefined ? 0 : [...`${s.press.hotkey}`].length
      ;[...s.text].forEach((ch, i) => {
        let fg = i < hot ? theme.suggestion : term.fg
        if (s.dim) fg = mix(fg, term.bg, 0.5)
        out.push({ ch, fg, bg: null, bold: false })
      })
      continue
    }
    const style = rows.textStyle(s)
    const fg = colourOf(style.color, theme, term)
    // A link is drawn underlined, as a terminal draws an OSC 8 hyperlink.
    for (const ch of s.text) out.push({ ch, fg, bg: null, bold: style.bold === true, link: s.link !== undefined })
  }
  return out
}

/** A run of raster rows as the engine receives them: the packed cells, decoded. */
function rasterCells(grid, themeName, term) {
  const packed = raster.rasterOf(grid, Math.max(1, ...grid.map(rows.rowWidth)), raster.rasterTheme(themeName))
  const bytes = Buffer.from(packed.cells, 'base64')
  const words = new Uint32Array(bytes.buffer, bytes.byteOffset, bytes.length / 4)
  const out = []
  for (let y = 0; y < packed.rows; y++) {
    const line = []
    for (let x = 0; x < packed.columns; x++) {
      const [cp, fg, bg] = words.slice((y * packed.columns + x) * 3, (y * packed.columns + x) * 3 + 3)
      line.push({
        ch: String.fromCodePoint(cp),
        fg: fg === raster.DEFAULT_COLOUR ? term.fg : hex(fg),
        bg: bg === raster.DEFAULT_COLOUR ? null : hex(bg),
        bold: false,
      })
    }
    out.push(line)
  }
  return out
}

/** The pane's rows as lines of cells, raster blocks drawn as the terminal's Raster would be. */
function screen(laidOut, themeName) {
  const theme = THEMES[themeName]
  const term = TERMINAL[themeName]
  const lines = []
  for (let i = 0; i < laidOut.length; i++) {
    const block = laidOut[i].raster
    if (block === undefined) {
      lines.push(rowCells(laidOut[i], theme, term))
      continue
    }
    let end = i
    while (end + 1 < laidOut.length && laidOut[end + 1].raster === block) end++
    lines.push(...rasterCells(laidOut.slice(i, end + 1), themeName, term))
    i = end
  }
  return lines
}

// ---------------------------------------------------------------- drawing

const EIGHTHS_LEFT = '▏▎▍▌▋▊▉'
const EIGHTHS_LOW = '▁▂▃▄▅▆▇'
const SHADE = { '░': 0.25, '▒': 0.5, '▓': 0.75 }
const esc = t => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

/** A block or rule glyph as the terminal draws it, edge to edge; null for an ordinary glyph. */
function blockShape(ch, x, y, fg) {
  const w = CELL_W
  const h = CELL_H
  if (ch === '█') return `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fg}"/>`
  const left = EIGHTHS_LEFT.indexOf(ch)
  if (left >= 0) return `<rect x="${x}" y="${y}" width="${((left + 1) * w) / 8}" height="${h}" fill="${fg}"/>`
  const low = EIGHTHS_LOW.indexOf(ch)
  if (low >= 0) return `<rect x="${x}" y="${y + h - ((low + 1) * h) / 8}" width="${w}" height="${((low + 1) * h) / 8}" fill="${fg}"/>`
  if (ch in SHADE) return `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fg}" fill-opacity="${SHADE[ch]}"/>`
  if (ch === '╸') return `<rect x="${x}" y="${y + h / 2 - 1.5}" width="${w / 2}" height="3" fill="${fg}"/>`
  if (ch === '━') return `<rect x="${x}" y="${y + h / 2 - 1.5}" width="${w}" height="3" fill="${fg}"/>`
  if (ch === '─') return `<rect x="${x}" y="${y + h / 2 - 0.5}" width="${w}" height="1" fill="${fg}"/>`
  if (ch === '│') return `<rect x="${x + w / 2 - 0.5}" y="${y}" width="1" height="${h}" fill="${fg}"/>`
  // Rounded corners as terminals draw them: a quarter circle joining the cell's middle lines.
  const cx = x + w / 2
  const cy = y + h / 2
  const r = w / 2
  const corner = {
    '╭': `M ${x + w} ${cy} H ${cx + r} A ${r} ${r} 0 0 0 ${cx} ${cy + r} V ${y + h}`,
    '╮': `M ${x} ${cy} H ${cx - r} A ${r} ${r} 0 0 1 ${cx} ${cy + r} V ${y + h}`,
    '╰': `M ${cx} ${y} V ${cy - r} A ${r} ${r} 0 0 0 ${cx + r} ${cy} H ${x + w}`,
    '╯': `M ${cx} ${y} V ${cy - r} A ${r} ${r} 0 0 1 ${cx - r} ${cy} H ${x}`,
  }[ch]
  if (corner !== undefined) return `<path d="${corner}" fill="none" stroke="${fg}" stroke-width="1"/>`
  return null
}

/** The cells as SVG, the first at (`ox`, `oy`) pixels. */
function cellLayer(lines, ox, oy) {
  const parts = []
  lines.forEach((line, row) => {
    const y = oy + row * CELL_H
    let run = null
    const flush = () => {
      if (run === null) return
      const xs = run.xs.join(' ')
      parts.push(
        `<text x="${xs}" y="${y + BASELINE}" fill="${run.fg}"${run.bold ? ' font-weight="bold"' : ''} xml:space="preserve">${esc(run.text)}</text>`,
      )
      if (run.link) parts.push(`<rect x="${run.xs[0]}" y="${y + BASELINE + 2}" width="${run.text.length * CELL_W}" height="1" fill="${run.fg}" fill-opacity="0.6"/>`)
      run = null
    }
    line.forEach((cell, col) => {
      const x = ox + col * CELL_W
      if (cell.bg !== null) parts.push(`<rect x="${x}" y="${y}" width="${CELL_W}" height="${CELL_H}" fill="${cell.bg}"/>`)
      const shape = blockShape(cell.ch, x, y, cell.fg)
      if (shape !== null) {
        flush()
        parts.push(shape)
        return
      }
      if (cell.ch === ' ') {
        flush()
        return
      }
      if (run !== null && (run.fg !== cell.fg || run.bold !== cell.bold || run.link !== (cell.link === true))) flush()
      run ??= { fg: cell.fg, bold: cell.bold, link: cell.link === true, text: '', xs: [] }
      run.text += cell.ch
      run.xs.push(x)
    })
    flush()
  })
  return parts
}

function svgOf(lines, columns, themeName) {
  const term = TERMINAL[themeName]
  const width = (columns + PAD_X * 2) * CELL_W
  const height = (lines.length + PAD_Y * 2) * CELL_H
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">`,
    `<rect width="100%" height="100%" fill="${term.bg}"/>`,
    `<g font-family="DejaVu Sans Mono" font-size="${FONT_SIZE}">`,
    ...cellLayer(lines, PAD_X * CELL_W, PAD_Y * CELL_H),
    '</g>',
    '</svg>',
  ].join('\n')
}

/** The README frame: room for the shadow, the window's corner radius, its title bar and the padding around the cells. */
const FRAME = { margin: 32, radius: 10, bar: 34, padX: 26, padY: 20 }

/**
 * The cells in a terminal window: a title bar with three quiet dots and the
 * title, rounded corners, a hairline edge and a soft shadow that falls on a
 * transparent margin, so the image sits on a light or a dark page alike.
 */
function framedSvg(lines, columns, themeName, title) {
  const term = TERMINAL[themeName]
  const dark = themeName.startsWith('dark')
  const { margin, radius, bar, padX, padY } = FRAME
  const w = columns * CELL_W + padX * 2
  const h = bar + lines.length * CELL_H + padY * 2
  const width = w + margin * 2
  const height = h + margin * 2
  const chrome = mix(term.bg, term.fg, dark ? 0.07 : 0.045)
  const dots = mix(term.bg, term.fg, dark ? 0.28 : 0.22)
  const edge = dark ? 'stroke="#ffffff" stroke-opacity="0.09"' : 'stroke="#000000" stroke-opacity="0.12"'
  const x0 = margin
  const y0 = margin
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">`,
    '<defs>',
    `<filter id="shadow" x="-10%" y="-10%" width="120%" height="130%"><feGaussianBlur in="SourceAlpha" stdDeviation="11"/><feOffset dy="7"/><feComponentTransfer><feFuncA type="linear" slope="${dark ? 0.42 : 0.2}"/></feComponentTransfer></filter>`,
    `<clipPath id="window"><rect x="${x0}" y="${y0}" width="${w}" height="${h}" rx="${radius}"/></clipPath>`,
    '</defs>',
    `<rect x="${x0}" y="${y0}" width="${w}" height="${h}" rx="${radius}" fill="#000000" filter="url(#shadow)"/>`,
    `<g clip-path="url(#window)">`,
    `<rect x="${x0}" y="${y0}" width="${w}" height="${h}" fill="${term.bg}"/>`,
    `<rect x="${x0}" y="${y0}" width="${w}" height="${bar}" fill="${chrome}"/>`,
    `<rect x="${x0}" y="${y0 + bar - 1}" width="${w}" height="1" fill="${mix(term.bg, term.fg, dark ? 0.12 : 0.1)}"/>`,
    '</g>',
    ...[0, 1, 2].map(i => `<circle cx="${x0 + 20 + i * 17}" cy="${y0 + bar / 2}" r="5.5" fill="${dots}"/>`),
    `<text x="${x0 + w / 2}" y="${y0 + bar / 2 + 4.5}" text-anchor="middle" font-family="DejaVu Sans" font-size="13" fill="${mix(term.bg, term.fg, 0.55)}">${esc(title)}</text>`,
    `<rect x="${x0 + 0.5}" y="${y0 + 0.5}" width="${w - 1}" height="${h - 1}" rx="${radius - 0.5}" fill="none" ${edge}/>`,
    `<g font-family="DejaVu Sans Mono" font-size="${FONT_SIZE}">`,
    ...cellLayer(lines, x0 + padX, y0 + bar + padY),
    '</g>',
    '</svg>',
  ].join('\n')
}

/** SVG to PNG through rsvg-convert, at `zoom`; exits on failure. */
function png(svg, file, zoom = 1) {
  const run = spawnSync('rsvg-convert', ['-z', String(zoom), '-o', file], { input: svg })
  if (run.status !== 0) {
    console.error(`rsvg-convert failed for ${file}: ${run.stderr}`)
    process.exit(1)
  }
}

// ---------------------------------------------------------------- main

/**
 * Shrinks a README PNG to a 256-colour palette through Python's Pillow: the
 * window's opaque pixels get 192 colours (text and flat fills need no more),
 * and the shadow, which is black at varying opacity, gets 62 steps of its
 * own and one fully clear entry, so it stays a soft fall-off. A quantizer
 * left to itself spends too few entries on alpha and draws the shadow as a
 * flat grey band. Without Pillow the PNG stays as drawn, and says so.
 */
function shrink(file) {
  const script = [
    'import sys',
    'from PIL import Image',
    'path = sys.argv[1]',
    'image = Image.open(path).convert("RGBA")',
    'alpha = image.getchannel("A")',
    'window = image.convert("RGB").quantize(colors=192, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)',
    'steps = alpha.point(lambda a: 255 if a == 0 else 192 + min(61, a * 62 // 256))',
    'out = window.copy()',
    'out.paste(steps, mask=alpha.point(lambda a: 255 if a < 250 else 0))',
    'palette = window.getpalette()[: 192 * 3] + [0, 0, 0] * 64',
    'out.putpalette(palette)',
    'clear = [255] * 192 + [round((i + 0.5) * 256 / 62) for i in range(62)] + [0, 0]',
    'out.save(path, optimize=True, transparency=bytes(min(255, c) for c in clear))',
  ].join('\n')
  const run = spawnSync('python3', ['-c', script, file], { encoding: 'utf8' })
  if (run.status !== 0) console.error(`pane-preview: kept ${file} unshrunk (python3 with Pillow is needed to shrink it): ${run.stderr}`)
}

/** The README's screenshots: view, theme, and the window's title. One width for all of them. */
const README_COLUMNS = 100
const README_SHOTS = [
  ['overview', 'dark'],
  ['changes', 'dark'],
  ['boundaries-marked', 'dark'],
  ['cycles', 'dark'],
  ['detail', 'dark'],
  ['band-prompt', 'dark'],
  ['overview', 'light'],
]

const only = args.only ? new Set(args.only.split(',')) : null
mkdirSync(OUT, { recursive: true })
// A full run starts the directory over; `--only` redraws its views beside the rest.
if (only === null) for (const file of readdirSync(OUT)) if (file.endsWith('.png')) rmSync(join(OUT, file))
const written = []
if (README) {
  const draws = new Map(VIEWS)
  const project = baseName(dashboard.project_root ?? PROJECT)
  for (const [name, themeName] of README_SHOTS) {
    if (only !== null && !only.has(name)) continue
    const draw = draws.get(name)
    if (draw === undefined) continue
    const file = join(OUT, `${name}-${themeName}.png`)
    const title = name === 'band-prompt' ? `claude · ${project}` : `/knossos · ${project}`
    png(framedSvg(screen(draw(README_COLUMNS), themeName), README_COLUMNS, themeName, title), file, 2)
    shrink(file)
    written.push(file)
  }
} else {
  for (const [name, draw] of VIEWS) {
    if (only !== null && !only.has(name)) continue
    for (const columns of COLUMNS) {
      const laidOut = draw(columns)
      for (const themeName of THEME_NAMES) {
        const file = join(OUT, `${name}-${columns}-${themeName}.png`)
        png(svgOf(screen(laidOut, themeName), columns, themeName), file)
        written.push(file)
      }
    }
  }
  writeFileSync(join(OUT, 'index.txt'), `${written.map(p => p.slice(OUT.length + 1)).join('\n')}\n`)
}
console.log(`${written.length} PNGs in ${OUT}`)
