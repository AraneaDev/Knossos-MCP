import { describe, expect, it } from 'vitest'
import { countLabel, parseDashboard, parseTurnBrief } from './envelopes'

describe('envelopes', () => {
  it('empty stdout is no data', () => expect(parseTurnBrief('')).toBeNull())
  it('garbage is no data', () => expect(parseTurnBrief('PHP Warning: x')).toBeNull())
  it('an object without a known status is no data', () => expect(parseDashboard('{"x":1}')).toBeNull())
  it('a valid brief parses', () => expect(parseTurnBrief('{"status":"unscanned","path":"/r","changed_files":[],"added_files":[],"deleted_files":[],"impact":{},"tests":[],"policy":{"status":"not_evaluated","total":0,"violations":[]},"project_root":null,"scanned_at":null,"roots_file":null}')?.status).toBe('unscanned'))
  it('a valid dashboard parses', () => expect(parseDashboard('{"status":"ok","fan_in":[]}')?.status).toBe('ok'))
  it('a dashboard status is not a brief status', () => expect(parseDashboard('{"status":"missing"}')).toBeNull())
  it('a non-object is no data', () => expect(parseTurnBrief('[1]')).toBeNull())
  it('a bare null is no data', () => expect(parseTurnBrief('null')).toBeNull())
})

describe('countLabel', () => {
  it('marks a truncated count', () => expect(countLabel(50, true)).toBe('50+'))
  it('leaves an exact count alone', () => expect(countLabel(50, false)).toBe('50'))
})
