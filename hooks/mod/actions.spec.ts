import { describe, expect, it } from 'vitest'
import { TABS } from '../lib/layout'
import { PRESSES, pressOf } from './actions'

describe('the press table', () => {
  it('runs an outright id as itself, and a prefixed one by its prefix with what follows the colon', () => {
    expect(pressOf('find')).toEqual({ key: 'find', rest: '' })
    expect(pressOf('route')).toEqual({ key: 'route', rest: '' })
    expect(pressOf('route-pick:2')).toEqual({ key: 'route-pick:', rest: '2' })
    expect(pressOf('row:12')).toEqual({ key: 'row:', rest: '12' })
    expect(pressOf('tab:hubs')).toEqual({ key: 'tab:', rest: 'hubs' })
    // Only the first colon divides: a fold's id carries the cycle and the row.
    expect(pressOf('unfold:3:7')).toEqual({ key: 'unfold:', rest: '3:7' })
  })

  it('runs a twin as the id it stands in for', () => {
    expect(pressOf('tabkey:churn')).toEqual({ key: 'tab:', rest: 'churn' })
    expect(pressOf('drifted')).toEqual({ key: 'drift', rest: '' })
    expect(pressOf('prev:4')).toEqual({ key: 'next:', rest: '4' })
    expect(pressOf('mark:0')).toEqual({ key: 'next:', rest: '0' })
    expect(pressOf('fold:1:2')).toEqual({ key: 'unfold:', rest: '1:2' })
  })

  it('does nothing for an id it does not know, a prefix without its colon, or an outright id given a colon', () => {
    for (const id of ['', 'row', 'tab', 'find:x', 'down:1', 'nope', 'constructor', 'toString', '__proto__', 'hasOwnProperty:1']) expect(pressOf(id), id).toBeNull()
  })

  it('no outright id holds a colon and every prefix ends in one, so the two kinds never meet', () => {
    for (const key of PRESSES.keys()) expect(key.indexOf(':') === -1 || key.indexOf(':') === key.length - 1, key).toBe(true)
  })

  it('every tab the pane draws has a press, by its id and by its hotkey twin', () => {
    for (const tab of TABS) {
      expect(pressOf(`tab:${tab.id}`)?.rest).toBe(tab.id)
      expect(pressOf(`tabkey:${tab.id}`)?.key).toBe('tab:')
    }
  })
})
