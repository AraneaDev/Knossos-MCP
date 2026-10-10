import { describe, expect, it } from 'vitest'
import { parsePathBetween } from './envelopes'

const node = (name: string, kind = 'class') => ({ name, canonical_name: `App\\${name}`, kind })
const hop = (type = 'calls') => ({ type })
const route = (names: string[]) => ({ nodes: names.map(n => node(n)), hops: names.slice(1).map(() => hop()) })
const envelope = (over: Record<string, unknown> = {}) => ({
    status: 'ok',
    path: '/r',
    from: node('Controller'),
    to: node('Repository'),
    routes: [route(['Controller', 'Service', 'Repository']), route(['Controller', 'Repository'])],
    ...over,
})
const parse = (over: Record<string, unknown> = {}) => parsePathBetween(JSON.stringify(envelope(over)))

describe('parsePathBetween', () => {
    it('an ok answer with two valid routes keeps both, with reversed and truncated false when absent', () => {
        const parsed = parse()
        expect(parsed?.status).toBe('ok')
        expect(parsed?.from).toEqual(node('Controller'))
        expect(parsed?.to).toEqual(node('Repository'))
        expect(parsed?.routes.map(r => r.nodes.map(n => n.name))).toEqual([
            ['Controller', 'Service', 'Repository'],
            ['Controller', 'Repository'],
        ])
        expect(parsed?.reversed).toBe(false)
        expect(parsed?.truncated).toBe(false)
    })

    it('reversed and truncated are true only when the answer says true', () => {
        const yes = parse({ reversed: true, truncated: true })
        expect(yes?.reversed).toBe(true)
        expect(yes?.truncated).toBe(true)
        const loose = parse({ reversed: 'yes', truncated: 1 })
        expect(loose?.reversed).toBe(false)
        expect(loose?.truncated).toBe(false)
    })

    it.each(['not-found', 'ambiguous', 'unscanned', 'error', 'no-binary'])('a %s answer is kept with empty route fields and its own fields', status => {
        const parsed = parsePathBetween(JSON.stringify({ status, path: '/r', candidates: ['A', 'B'], from: node('X'), reversed: true, routes: [route(['X', 'Y'])], truncated: true }))
        expect(parsed).toEqual({ status, path: '/r', candidates: ['A', 'B'], from: null, to: null, reversed: false, routes: [], truncated: false })
    })

    it.each([
        ['empty stdout', ''],
        ['invalid JSON', '{"status":'],
        ['an unknown status', '{"status":"weird"}'],
    ])('%s is no data', (_label, stdout) => expect(parsePathBetween(stdout)).toBeNull())

    it('an ok answer whose from lacks a canonical name is no data', () => expect(parse({ from: { name: 'Controller', kind: 'class' } })).toBeNull())

    it('an ok answer with a null to is no data', () => expect(parse({ to: null })).toBeNull())

    it('an ok answer whose routes are not a list is no data', () => expect(parse({ routes: { 0: route(['A', 'B']) } })).toBeNull())

    it('an ok answer with no routes parses with none', () => expect(parse({ routes: [] })?.routes).toEqual([]))

    it('a route of a single node is no data', () => expect(parse({ routes: [{ nodes: [node('Controller')], hops: [] }] })).toBeNull())

    it('a route of exactly two nodes and one hop is accepted', () => expect(parse({ routes: [route(['Controller', 'Repository'])] })?.routes).toHaveLength(1))

    it('a route with as many hops as nodes is no data', () => expect(parse({ routes: [{ nodes: [node('A'), node('B')], hops: [hop(), hop()] }] })).toBeNull())

    it('a route whose hops are not a list is no data', () => expect(parse({ routes: [{ nodes: [node('A'), node('B')], hops: 'calls' }] })).toBeNull())

    it('a route with a node whose kind is a number is no data', () => expect(parse({ routes: [{ nodes: [node('A'), { name: 'B', canonical_name: 'App\\B', kind: 3 }], hops: [hop()] }] })).toBeNull())

    it('control characters in names are drawn inert', () => {
        const parsed = parsePathBetween(JSON.stringify(envelope({ from: { name: 'Ctrl\u001b[2J', canonical_name: 'App\\Ctrl\u0007', kind: 'class' } })))
        expect(parsed?.from?.name).toBe('Ctrl�[2J')
        expect(parsed?.from?.canonical_name).toBe('App\\Ctrl�')
    })
})
