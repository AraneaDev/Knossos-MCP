// A stand-in for the module the Claude Code engine provides at run time, so a
// static scan of this repository resolves register.tsx's imports instead of
// reporting them as missing, and the specs' type-check (hooks/tsconfig.spec.json)
// runs where the engine's own declarations do not exist, as in CI. Every name
// here is `any`, so a module annotates a callback's parameter with the
// engine's type rather than leaving it to be inferred. The engine supplies the
// real declarations when it loads the mod; nothing here is shipped with the plugin.
/* eslint-disable @typescript-eslint/no-explicit-any -- the engine owns these shapes; a scan only needs the names to resolve */
declare module 'claude-code' {
  export type ConfigRow = any
  export type Elements = any
  type Call = (...args: any[]) => any
  type Noun = Record<string, Call>
  export type EngineInterface = {
    clock: Noun
    ui: Noun
    session: Noun
    process: Noun
    fs: Noun
    store: Noun
    config: Noun
    command: Noun
    prompt: Noun
    plugin: { root: string }
  }
  export type Handler = (...args: any[]) => any
  export type On = (event: string, optionsOrHandler: Handler | object, handler?: Handler) => void
  export type Register = (on: On, options: PluginOptions) => any
  export type RenderPropsOf = Record<string, any>
  export type RenderElement = any
  export type RenderNode = any
  export type RenderSurface = any
  export type PluginOptions = any
  export type PressedLink = any
  export type ProcessRunResult = any
  export type Timer = any
  export type UiPane = any
  export type UiPressArgument = any
  export type Cell<T> = { readonly value?: T }
  export function atom<T>(key: object, initial: T): Cell<T>
  export function read(...args: any[]): any
  export function update<T>(engine: unknown, cell: Cell<T>, change: (value: T) => T): any
}

declare module 'claude-code/testing' {
  export type TestNode = { children: unknown[]; [member: string]: any }
  export type Mounted = {
    find: (query?: object) => Promise<TestNode | undefined>
    findAll: (query?: object) => Promise<TestNode[]>
    [member: string]: any
  }
  export type Engine = {
    ui: { mount: (...args: any[]) => Promise<Mounted>; [member: string]: any }
    [member: string]: any
  }
  export type TestBody = (engine: Engine, on: import('claude-code').On) => any
  export const describe: (name: string, body: () => void) => void
  export const test: (name: string, optionsOrBody: object | TestBody, body?: TestBody) => void
  export const expect: any
  export const mock: any
}

// The engine also declares these as globals: JSX compiles to bare `h(...)`
// and `Fragment` calls resolved by name (`jsxFactory`), never imported.
declare const h: (
  tag: string | ((props: never) => import('claude-code').RenderNode),
  props: Record<string, unknown> | null | undefined,
  ...children: unknown[]
) => import('claude-code').RenderNode
declare const Fragment: (props: { children?: import('claude-code').RenderNode[] }) => import('claude-code').RenderElement
