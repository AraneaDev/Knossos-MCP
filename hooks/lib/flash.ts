/**
 * Which rows changed in the latest scan, so the pane can light them for a
 * moment after it lands (about three seconds), then let them go back.
 *
 * Pure: two dashboards (or two reads of the session's changes) in, the keys
 * of what differs out. A key names a row by what it shows, so it survives
 * the list being sorted again: `hub:<canonical name>` for a ranked
 * component whose degrees moved (or that is newly ranked), `file:<path>`
 * for a file whose dependents moved, `tile:<key>` for a stat tile whose
 * figure moved, `boundary:<name>` for a boundary whose members moved, and
 * `change:<path>` for a changed file a newer scan took in again.
 */
import type { Dashboard, SessionLedger } from '../../types'

/** How long the changed rows stay lit after the scan lands. */
export const FLASH_MS = 3_000

export { FLASH_BG } from './palette'

/** What the pane lights, until when (mod clock, ms). */
export type Flash = { keys: string[]; until: number }

/** The most keys one scan lights: past it, a scan that moved everything lights nothing in particular. */
const KEYS_MAX = 200

/** The keys whose figures differ between the dashboard before a scan and the one after. */
export function flashKeys(before: Dashboard | null, after: Dashboard): string[] {
  if (before === null || before.status !== 'ok' || after.status !== 'ok' || before.snapshot_id === after.snapshot_id) return []
  const keys: string[] = []
  const ranked = (d: Dashboard) => new Map([...d.hubs, ...d.hotspots].map(r => [r.canonical_name, `${r.in_degree ?? 0}/${r.out_degree ?? 0}/${r.cross_boundary_degree ?? 0}/${r.dependent_files ?? 0}`]))
  const was = ranked(before)
  for (const [name, figures] of ranked(after)) if (was.get(name) !== figures) keys.push(`hub:${name}`)
  const files = new Map(before.fan_in.map(f => [f.path, f.dependent_files]))
  for (const f of after.fan_in) if (files.get(f.path) !== f.dependent_files) keys.push(`file:${f.path}`)
  const members = new Map((before.boundaries?.items ?? []).map(b => [b.name, b.members]))
  for (const b of after.boundaries?.items ?? []) if (members.get(b.name) !== b.members) keys.push(`boundary:${b.name}`)
  for (const [key, figure] of Object.entries(tileFigures(after))) if (tileFigures(before)[key] !== figure) keys.push(`tile:${key}`)
  return keys.length > KEYS_MAX ? [] : keys
}

/** The stat tiles' figures by tile key, as `statsOf` keys them. */
function tileFigures(d: Dashboard): Record<string, number | null> {
  return {
    components: d.summary?.components ?? null,
    cycles: d.cycles.count,
    degree: d.trend.at(-1)?.max_degree ?? null,
    dead: d.dead_code_candidates,
    diagnostics: d.diagnostics === undefined ? null : d.diagnostics.errors + d.diagnostics.warnings,
    policy: d.policy?.total ?? null,
    drifted: d.freshness.drift_files,
  }
}

/** The changed files a newer read of the session's changes holds anew or differently: added, moved in reach, or taken in by a newer scan. */
export function ledgerFlashKeys(before: SessionLedger | null, after: SessionLedger): string[] {
  if (before === null || before.status !== 'ok' || after.status !== 'ok' || before.since !== after.since) return []
  const keys: string[] = []
  for (const [path, f] of Object.entries(after.files)) {
    const old = before.files[path]
    if (old === undefined || old.status !== f.status || old.dependents !== f.dependents || (old.scans ?? []).at(-1) !== (f.scans ?? []).at(-1)) keys.push(`change:${path}`)
  }
  return keys.length > KEYS_MAX ? [] : keys
}

/** The keys lit at `now`, or none once the flash is over. */
export const litAt = (flash: Flash | null, now: number): ReadonlySet<string> => new Set(flash !== null && now < flash.until ? flash.keys : [])
