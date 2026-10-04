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
  export type EngineInterface = any
  export type Register = any
  export type RenderElement = any
  export type RenderNode = any
  export type RenderSurface = any
  export type PluginOptions = any
  export type PressedLink = any
  export type ProcessRunResult = any
  export type Timer = any
  export type UiPane = any
  export type UiPressArgument = any
  export function atom<T>(initial: T, options?: any): any
  export function read(...args: any[]): any
  export function update(...args: any[]): any
}

// The engine also declares these as globals: JSX compiles to bare `h(...)`
// and `Fragment` calls resolved by name (`jsxFactory`), never imported.
declare const h: (
  tag: string | ((props: never) => import('claude-code').RenderNode),
  props: Record<string, unknown> | null | undefined,
  ...children: unknown[]
) => import('claude-code').RenderNode
declare const Fragment: (props: { children?: import('claude-code').RenderNode[] }) => import('claude-code').RenderElement
