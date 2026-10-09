# TypeScript and JavaScript

Knossos scans `.ts`, `.tsx`, `.mts`, `.cts`, `.js`, `.jsx`, `.mjs` and `.cjs`
files, plus `.vue`, `.svelte` and `.astro` components, with the TypeScript
compiler's own symbol resolution. Imports, calls, types, inheritance and project
references come from the compiler and are the primary facts. On top of them the
worker layers a bounded set of application conventions. Their facts carry
`framework_convention` provenance. Every edge is `certain`; the framework roles
Knossos derives from them are probable, and certain for NestJS.

No framework module is imported and no bundler or application is started.

## Framework and application conventions

- Next.js App Router `page` and `layout` exports, HTTP exports in `route` files,
  route-group path removal, and function-level `"use server"` actions.
- React function/class components in JSX files, `useX` hooks, and compiler-
  resolved `uses_hook` edges.
- Vue `defineComponent` variables and `useX` composables in Vue-oriented
  TypeScript modules.
- Pinia/Redux/Zustand-style `defineStore`, `createStore`, `configureStore`, and
  `create` factory declarations.
- Literal `fetch` and `axios.get`, `post`, `put`, `patch` and `delete` calls as
  `endpoint` nodes and `calls_endpoint` edges, including a static `method`
  option on `fetch`.
- NestJS, described below.
- A leading shebang marks the module `executable`, which keeps a script a shell
  runs (and that nothing therefore imports) off the dead-code report.
- A `.js` or `.cjs` file with no import, export, `require` or `module.exports`
  is a classic script, loaded by a `<script>` tag or run by Node, and is
  `executable` too: nothing can import anything from it.
- A module that exports nothing and calls something at its top level
  (`seed();`, `main().catch(...)`, `await start()`) is run rather than
  imported, by `node scripts/seed.js` or as a bundler's entry, and is
  `executable` too. Any `export`, or `module.exports` or `exports` named
  anywhere in the file, rules it out.
- A file no tsconfig includes is read under fallback options: the module
  resolution options of the config nearest to it, without its `strict`, `lib`
  or `types`. Which globals and type libraries the project loads is unknown
  there, so an error that a name or type library is missing (`Cannot find
name '__dirname'`, the hint to install `@types/node`, a `/// <reference
types>` that is not installed) is left out. A missing module, a type error
  and a grammar error are still reported. An option error that names no file is
  not reported for these files: the fallback options are partly made up by the
  scanner, so the error would not be about your config. The config's
  `ignoreDeprecations` comes along with its resolution options, so a deprecation
  you silenced stays silent here too.
- A module importing `k6` or `k6/*` is a k6 load-test script: it is
  `executable`, and its default export, `setup`, `teardown`, `handleSummary` and
  every function a scenario names as its `exec` are marked `runtime_invoked`,
  since k6 calls them and nothing imports them.

### NestJS

Imports from `@nestjs/*` switch the NestJS collector on for a file. Its roles
are certain.

- `@Controller`, `@Injectable` and `@Module` classes get the
  `nestjs.controller`, `nestjs.provider` and `nestjs.module` roles.
- `@Get`, `@Post`, `@Put`, `@Patch`, `@Delete`, `@Head`, `@Options` and `@All`
  methods on a controller become `route` nodes, with the controller's literal
  prefix joined to the method's path.
- The `imports`, `controllers`, `providers` and `exports` arrays of `@Module`
  become `depends_on`, `contains` and `exports` edges to the classes they name.
- Methods that Nest calls itself get the `nestjs.framework_handler` role: the
  lifecycle hooks (`onModuleInit`, `onApplicationBootstrap` and the other
  three), `canActivate`, `intercept`, `transform`, `catch`, `use`, the gateway
  methods (`handleConnection`, `handleDisconnect`, `afterInit`), a Passport
  strategy's `validate`, and methods decorated with `@Cron`, `@Interval`,
  `@Timeout`, `@OnEvent`, `@EventPattern`, `@MessagePattern`, `@Process`, the
  queue event decorators, `@OnWorkerEvent` or `@SubscribeMessage`.

### Edges

Repeated edges are collapsed to the persistence identity. Mixed type/value
imports retain `type_only_variants` so deduplication does not erase that
distinction.

An import of something outside the project targets a `package` node named after
the package: `lodash` for `lodash/fp`, `@scope/pkg` for `@scope/pkg/sub`. A Node
built-in is one package with or without its `node:` prefix (`node:fs` and `fs`
are both `fs`, and `_http_agent` too, though npm would not publish that name);
one Node only offers under the prefix, such as `node:test`, keeps it. Which
names are built-ins is Node 24's list, whatever Node runs the scan. A specifier
that resolves to nothing gets no edge when a tsconfig `paths` key or a bundler
alias covers it (`@app/missing` under `@app/*`; a catch-all `"*"` key does not
count, since it covers every name; an alias that resolves into a dependency,
such as `vue` aliased to `vue/dist/vue.esm-bundler.js`, is still that package),
or when it could not be an npm package name, such as an alias your tsconfig does
not know (`@/components`, `~/stores`): it names your own code, not a dependency,
and in a file it type-checks the compiler reports it as a missing module.

## Declarations

Besides classes, interfaces, enums, type aliases, namespaces, functions and
methods, a module-level binding whose value is an arrow or function expression
is a `function` node: `const f = () => {}`, `let f = function () {}`, through
parentheses, `as` and `satisfies`. It carries `binding` (`const`, `let`, `var`,
`using` or `await using`), is the source of the calls in its body, and is the
target of calls and references to it. A binding inside a function or block, or
one whose initializer wraps the function in a call (`memo(() => ...)`), is not a
node.

`exported` and `default` on any variable binding are read from its statement, so
`export const api = { ... }` is exported.

## Components

`.vue`, `.svelte` and `.astro` files are scanned as TypeScript. The worker reads
each one into position-preserving virtual source: script blocks (and Astro's
frontmatter and bundled `<script>` elements) keep their bytes, template
expressions and component tags are written back where they stand, and everything
else is blank. Line and column numbers are therefore the component's own, and an
import of `./Card.vue` resolves through relative paths, `paths` and `baseUrl`
like any other module.

- A helper, store or child component used only from a template has its edge.
- In an `.astro` file, `Astro.props` has the component's `Props` type, as
  Astro's own tooling gives it, so fields read from it are not typed from their
  destructuring defaults.
- In a `.svelte` file, the runes (`$state`, `$derived`, `$props`) are typed by
  the installed `svelte` package, as svelte-check types them, and in a SvelteKit
  `+page.svelte` or `+layout.svelte`, `$props()` gives `data` the type the
  route's generated `./$types` declares.
- A generic component's type parameters
  (`<script lang="ts" generics="T extends …">` in Svelte, `generic="T"` in Vue)
  are declared, each standing for its constraint.
- These declarations (the route's `$props`, the type parameters, Astro's
  `Astro`) are written nowhere in your file, so they are no nodes and nothing
  refers to them, and no fact of a component stands past its last line. What
  they imply stays: Astro reads `Props` by name, so the component still
  references it.
- A component's default export is the component its bundler compiles, which its
  source never writes (`<script setup>`, Svelte, Astro): importing it,
  re-exporting it or destructuring `default` from `await import('./X.vue')` is
  not reported as an error.
- A file no tsconfig `include` covers (a package's tests, a nested tools
  package) is read with its package's paths, aliases and project references,
  under the TypeScript and installed types of the package it sits in, and with a
  bundler's resolution as its test runner uses. Every such file of the package
  is in that program, whichever of them a scan or a batch happens to read, so
  an ambient `declare module 'x'` in the package's declaration files satisfies
  the import, a test sees the globals its setup file declares, and an
  incremental scan of one such file reads it as a full scan did.
- A template name that resolves to nothing (an Options API method reached
  through the component instance) is listed in the module's
  `unresolved_member_calls`, so a method by that name is only possibly dead.
- A Vue Options API component (`export default { … }`,
  `defineComponent({ … })`): the hooks Vue, vue-router, vue-meta and Nuxt call
  (`mounted`, `data`, `beforeRouteEnter`, `metaInfo`...), watchers and prop
  `default`/`validator` factories are marked as called by the runtime, and a
  method or computed property the component names through `this.x`, its template
  or a watcher string gets a reference.
- Under a `package.json` that depends on `vue`, an extensionless import
  (`./components/Card`) that resolves to nothing else resolves to `Card.vue`, as
  webpack and Vue CLI do.
- `require('./x')` in a component script without `lang="ts"` imports what it
  names, as it does in a `.js` file.
- Module aliases a bundler declares in `vite.config.*`, `webpack.config.*`,
  `webpack.mix.js`, `vue.config.js` or `svelte.config.*` (including SvelteKit's
  `$lib`) apply when no tsconfig `paths` maps the same name. Only targets that
  can be read without running the config count: a string,
  `path.join|resolve(__dirname, …)`, or
  `fileURLToPath(new URL('./x', import.meta.url))`.
- webpack's `require.context('./dir', recursive, /pattern/)` imports every
  module in the graph it matches. The match happens when the graph is assembled,
  so an incremental scan that re-reads only the loading file keeps every edge.
- Vite's `import.meta.glob('./Pages/**/*.vue')`, a single pattern or an array,
  imports every module its patterns match, the same way, reading a relative
  pattern from a literal `base` option and matching `*`, `**`, `?`, `{a,b}` and
  `[ab]`. A negated pattern (`'!./x.vue'`) is ignored, so it can keep a module
  it excludes live but never drops one.
- SvelteKit route components (`+page.svelte`, `+layout.svelte`, `+error.svelte`)
  and Astro pages (`src/pages/**/*.astro`) are entry points.
- A component that cannot be delimited, or whose script is `lang="tsx"` or
  `lang="jsx"`, keeps its module node and reports `COMPONENT_UNPARSED`.
- Every component is a module, with its compiled component as the default
  export, whatever its script declares.

Not handled: Nuxt auto-imported components and file-based routing, globally
registered components, Vue template type semantics (slot props, `$emit` names,
`v-model` modifiers), `.mdx` and Markdown pages.

## Limits

Dynamic route segments stay in their source spelling, dynamic request URLs are
omitted, and framework roles never override a language symbol kind. A NestJS
route whose decorator argument is not a literal gets an empty segment rather
than a guessed one.

## Compiler diagnostics

Compiler errors that name a file are attached to that file. A scan builds only
the programs whose files it reads, and type-checks each of them whole, in
program order, before reading any facts, so for the same bytes a file's facts
and diagnostics are the same whether a scan reads it alone or with the rest of
its program. An
option error that names no file, such as a deprecated
`moduleResolution` value, or a global type your `lib` lacks, appears once per
program, on the program's first file in path order, and its message says it
applies to the whole program. That file is the same however your files are
batched, so an incremental scan that does not touch it neither repeats nor
drops the diagnostic. If a deprecation is not worth acting on yet, you can silence it
with `"ignoreDeprecations"` in your `tsconfig.json`. A global type the compiler only
looks for while checking one file's code (`IterableIterator` for a generator
under an ES5 `lib`) is not reported: an edit to that file would not rebuild
the file the diagnostic sits on. A compiler budget running out (TS2589,
TS2590, TS2321, TS7056) is reported where the whole program's check runs out.

One limit follows from the compiler's instantiation cache, which the whole
program shares: an edit to one file can change whether a budget runs out in a
file that does not import it. Removing an instantiation from `a.ts` that warmed
the cache can make `b.ts` report TS2589 and resolve a type differently (a
`references` edge to `Array`, say). An incremental scan rescans `a.ts` but not
`b.ts`, so `b.ts` keeps its earlier facts and diagnostics until a full scan
(`--mode=full`) settles them. Rescanning every file on every edit would be a
full rebuild. A program the compiler
cannot build or check at all reports `TS_PROGRAM_FAILED` for its files and costs
no other program's facts.
