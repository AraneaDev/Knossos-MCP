# TypeScript application enrichment

Knossos layers bounded application conventions over TypeScript compiler symbol
resolution. Compiler-derived imports, calls, types, inheritance, and project
references remain the primary facts; framework roles use
`framework_convention` provenance and probable confidence.

## Supported signals

- Next.js App Router `page` and `layout` exports, HTTP exports in `route` files,
  route-group path removal, and function-level `"use server"` actions.
- React function/class components in JSX files, `useX` hooks, and compiler-
  resolved `uses_hook` edges.
- Vue `defineComponent` variables and `useX` composables in Vue-oriented
  TypeScript modules.
- Pinia/Redux/Zustand-style `defineStore`, `createStore`, `configureStore`, and
  `create` factory declarations.
- Literal `fetch` and common Axios calls as endpoint nodes and
  `calls_endpoint` edges, including a static `fetch` method option.
- A leading shebang marks the module `executable`, which keeps a script a shell
  runs (and that nothing therefore imports) off the dead-code report.
- A `.js` or `.cjs` file with no import, export, `require` or `module.exports` is a classic
  script, loaded by a `<script>` tag or run by Node, and is `executable` too: nothing can
  import anything from it.
- A module importing `k6` or `k6/*` is a k6 load-test script: it is `executable`, and its
  default export, `setup`, `teardown`, `handleSummary` and every function a scenario names as
  its `exec` are marked `runtime_invoked`, since k6 calls them and nothing imports them.

Repeated edges are collapsed to the persistence identity. Mixed type/value
imports retain `type_only_variants` so deduplication does not erase that
distinction.

## Declarations

Besides classes, interfaces, enums, type aliases, namespaces, functions and methods, a
module-level binding whose value is an arrow or function expression is a `function` node:
`const f = () => {}`, `let f = function () {}`, through parentheses, `as` and `satisfies`. It
carries `binding` (`const`, `let`, `var`, `using` or `await using`), is the source of the calls in its body, and is the
target of calls and references to it. A binding inside a function or block, or one whose
initializer wraps the function in a call (`memo(() => ...)`), is not a node.

`exported` and `default` on any variable binding are read from its statement, so
`export const api = { ... }` is exported.

## Components

`.vue`, `.svelte` and `.astro` files are scanned as TypeScript. The worker reads each one into
position-preserving virtual source: script blocks (and Astro's frontmatter and bundled
`<script>` elements) keep their bytes, template expressions and component tags are written back
where they stand, and everything else is blank. Line and column numbers are therefore the
component's own, and an import of `./Card.vue` resolves through relative paths, `paths` and
`baseUrl` like any other module.

- A helper, store or child component used only from a template has its edge.
- In an `.astro` file, `Astro.props` has the component's `Props` type, as Astro's own tooling
  gives it, so fields read from it are not typed from their destructuring defaults.
- As svelte-check reads them: the runes (`$state`, `$derived`, `$props`) are typed by the
  installed `svelte` package, and in a SvelteKit `+page.svelte` or `+layout.svelte`, `$props()`
  gives `data` the type the route's generated `./$types` declares.
- A generic component's type parameters (`<script lang="ts" generics="T extends …">` in
  Svelte, `generic="T"` in Vue) are declared, each standing for its constraint.
- A component's default export is the component its bundler compiles, which its source never
  writes (`<script setup>`, Svelte, Astro): importing it, re-exporting it or destructuring
  `default` from `await import('./X.vue')` is not reported as an error.
- A file no tsconfig `include` covers (a package's tests, a nested tools package) is read with
  its package's paths, aliases and project references, under the TypeScript and installed types
  of the package it sits in, and with a bundler's resolution as its test runner uses.
- A template name that resolves to nothing (an Options API method reached through the
  component instance) is listed in the module's `unresolved_member_calls`, so a method by that
  name is only possibly dead.
- A Vue Options API component (`export default { … }`, `defineComponent({ … })`): the hooks
  Vue, vue-router, vue-meta and Nuxt call (`mounted`, `data`, `beforeRouteEnter`,
  `metaInfo`...), watchers and prop `default`/`validator` factories are marked as called
  by the runtime, and a method or computed property the component names through `this.x`, its
  template or a watcher string gets a reference.
- Under a `package.json` that depends on `vue`, an extensionless import (`./components/Card`)
  that resolves to nothing else resolves to `Card.vue`, as webpack and Vue CLI do.
- `require('./x')` in a component script without `lang="ts"` imports what it names, as it
  does in a `.js` file.
- Module aliases a bundler declares in `vite.config.*`, `webpack.config.*`, `webpack.mix.js`,
  `vue.config.js` or `svelte.config.*` (including SvelteKit's `$lib`) apply when no tsconfig
  `paths` maps the same name. Only targets that can be read without running the config
  count: a string, `path.join|resolve(__dirname, …)`, or
  `fileURLToPath(new URL('./x', import.meta.url))`.
- webpack's `require.context('./dir', recursive, /pattern/)` imports every module in the
  graph it matches. The match happens when the graph is assembled, so an incremental scan
  that re-reads only the loading file keeps every edge.
- Vite's `import.meta.glob('./Pages/**/*.vue')`, a single pattern or an array, imports every
  module its patterns match, the same way. A negated pattern (`'!./x.vue'`) is ignored, so it
  can keep a module it excludes live but never drops one.
- SvelteKit route components (`+page.svelte`, `+layout.svelte`, `+error.svelte`) and Astro pages
  (`src/pages/**/*.astro`) are entry points.
- A component that cannot be delimited, or whose script is `lang="tsx"` or `lang="jsx"`, keeps
  its module node and reports `COMPONENT_UNPARSED`.
- Every component is a module, with its compiled component as the default
  export, whatever its script declares.

Not handled: Nuxt auto-imported components and file-based routing, globally registered
components, Vue template type semantics (slot props, `$emit` names, `v-model` modifiers), `.mdx`
and Markdown pages.

## Limits

No framework module is imported and no bundler or Next/Vue application is
started. Dynamic route segments stay in their source spelling, dynamic request
URLs are omitted, and framework roles do not override language symbol kinds.
