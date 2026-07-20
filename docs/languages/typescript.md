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

Repeated edges are collapsed to the persistence identity. Mixed type/value
imports retain `type_only_variants` so deduplication does not erase that
distinction.

## Limits

No framework module is imported and no bundler or Next/Vue application is
started. Dynamic route segments stay in their source spelling, dynamic request
URLs are omitted, and framework roles do not override language symbol kinds.
Single-file Vue components are not parsed until an isolated SFC parser is added;
Vue TypeScript modules remain supported.
