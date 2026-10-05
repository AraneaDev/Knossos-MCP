# Rust

Knossos scans Rust through the same out-of-process scanner protocol as the other languages. The bundled worker parses `.rs` files with `syn` and never runs `cargo` or `rustc` against your project.

Rust is optional on a native install: without `cargo` on the machine there is no Rust worker, and `.rs` files are not scanned. The container image always has one. `knossos doctor` tells you which workers you have.

## What the scanner reads

- `.rs` files
- `Cargo.toml`, recorded as a `cargo` unit that takes part in cache invalidation
- Cargo dependency tables, including target-scoped `*.dependencies` tables and a crate's own `[dependencies.<crate>]` sub-table
- `[[bin]]` entries, plus the targets Cargo discovers itself: `src/main.rs` and the binaries under `src/bin/`. Discovery follows Cargo's own rules, so `autobins = false` turns it off, and on the 2015 edition (the default when `edition` is absent) a hand-written `[[bin]]` turns it off too
- the package's build script: the file `build =` names, or a `build.rs` beside the manifest, which Cargo runs before it compiles the crate

The manifest's `[package] name` becomes a `package` node when the crate root is part of the scan. A declared binary path becomes an exact manifest entry point for classification. If that path is absent, no node is invented.

## What ends up in the graph

| Source construct             | Node kind   |
| ---------------------------- | ----------- |
| File, or a `mod` block       | `module`    |
| Crate manifest `[package]`   | `package`   |
| `struct`, `enum`, or `union` | `class`     |
| `trait`                      | `interface` |
| `fn` inside an `impl`        | `method`    |
| Free `fn`                    | `function`  |
| Framework route declaration  | `route`     |

| Edge         | Source                                               | Confidence             |
| ------------ | ---------------------------------------------------- | ---------------------- |
| `contains`   | nesting, and a `package` containing its crate module | certain                |
| `imports`    | `use`                                                | certain                |
| `implements` | `impl Trait for Type`                                | certain                |
| `extends`    | supertraits                                          | probable               |
| `calls`      | a resolved call expression                           | probable               |
| `routes_to`  | a route to its handler                               | certain                |
| `references` | a function or type named without being called        | speculative, see below |
| `returns`    | a method to the type its signature declares          | speculative, see below |

A name that a glob import (`use crate::components::*;`) brings in resolves through it, after the names the enclosing module declares.

A node's `local_id` and both ends of an edge are written `rust:<kind>:<canonical>`. `canonical_name` itself carries no prefix. When a target cannot be resolved, the worker emits no edge rather than a guess. Repeated edges collapse to the persistence identity of kind, source and target within one contribution, and the earliest evidence is kept.

### Code nothing calls by name

These get an attribute, so they stay off the [dead-code candidates](../concepts/dead-code-candidates.md) list:

- A crate-root `fn main` marks its module `executable`. Manifest entry-point classification can additionally mark every node from an exact binary path with `application.entry_point`.
- A function exported to a foreign caller (`#[no_mangle]`, `#[export_name]`, `#[wasm_bindgen]` or an `extern` ABI) is `runtime_invoked`. So are the public methods of a `#[wasm_bindgen] impl`, and `drop` in an `impl Drop`.
- A method of a trait impl carries `overrides`, because the trait declares it and the trait may be a dependency's.
- Code under `#[cfg(test)]` and `#[test]` functions (including `#[tokio::test]`) is marked as test code.

### Speculative edges

Some names could refer to a type the graph does not hold, such as `Vec` or `String`. For those the worker emits a `speculative` edge, and the core keeps it only if the target turns out to be a declared node. That is how a method call on a receiver of known type reaches its method: `self`, a typed parameter, a `let` with a type annotation, or a `let` assigned from a struct literal or an associated call such as `Widget::make()`. A call on what another call returns (`state.mode().label()`) resolves through the declared return type.

## Frameworks

The core reads your Cargo manifests and tells the worker which of `axum`, `actix` and `rocket` to enrich. You can set the same hints under `frameworks` in your [project configuration](../get-started/project-configuration.md). Route enrichment only runs for the frameworks it is asked for.

- Axum: `Router::route("/path", get(handler))` calls produce `route` nodes and `routes_to` edges.
- Actix: route attributes such as `#[get("/path")]`, `#[post(...)]` and `#[route("/path", method = "PUT")]`, and the `web::resource(...).route(web::get().to(handler))` shape.
- Rocket: verb attributes such as `#[get("/path")]`.

A handler function gets the `rust_framework_roles` attribute with `rust.route_handler`. A route path that is not a literal is skipped with the `RS_DYNAMIC_ROUTE_PATH` warning. Literal framework markers such as `<id>`, `{id}` and `:id` count as dynamic too.

## Cross-file resolution

Each scan batch builds a declaration index before it walks the files. The index lets a cross-file `impl` block attach its methods to a uniquely declared type, and lets a call into a child module point at a declaration in another file. An ambiguous declaration is dropped rather than guessed. The index is scoped to the request, so a file left out of the request, or served only from cache, never counts as evidence.

## Limits

- Macro bodies are not expanded, so a call a macro generates is invisible.
- `cfg`-gated code is walked in full, because the worker resolves no features. A symbol behind a disabled feature still appears.
- Generic instantiation is not resolved.
- A method call on a receiver whose type the source does not state produces no edge. The method name is recorded in the module's `unresolved_member_calls`, so a method by that name is only possibly dead.
- A call through a qualified self, such as `<Widget>::default()`, produces no edge. The parser renders that callee as the bare word `default`, which names no path the worker can confirm. The same holds for a call through an `Fn` receiver, `self()`.
- A call target's kind follows Rust's naming convention: an uppercase segment before the final one means a method, anything else means a function. A crate that breaks the convention can produce a target that matches no declared node.
- An import name bound to two different paths in one file resolves to nothing.
- A bare `mod foo;` declaration emits only a containment edge. The module's own node comes from the file that defines it.
- A `use` leaf that already names a module resolves to the module's parent: `use core::fmt;` emits `imports` to `core`, and `use crate::token;` emits it to `crate`. The declaration index records types, traits and functions rather than `mod` declarations, and import collection does not consult it, so every multi-segment leaf other than an explicit `self` is cut the same way.
- An `impl` whose target type is not declared in the scan batch keeps its method nodes but drops the `contains` and `implements` edges whose source cannot be vouched for. That is a deliberate false negative rather than a wrong fact.
- The route recognizers cover the forms listed above. Macro expansion, runtime router composition and arbitrary framework wrappers are out of reach.

## Checks on the worker

The worker is checked with `cargo fmt`, `cargo clippy`, `cargo test` and the shared scanner-conformance check. The repository's Rust integration suite drives the real worker process over NDJSON-RPC.
