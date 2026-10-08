# Rust

Knossos scans Rust through the same out-of-process scanner protocol as the other
languages. The bundled worker parses `.rs` files with `syn` and never runs
`cargo` or `rustc` against your project.

Rust is optional on a native install: without `cargo` on the machine there is no
Rust worker, and `.rs` files are not scanned. The container image always has
one. `knossos doctor` tells you which workers you have.

## What the scanner reads

- `.rs` files
- `Cargo.toml`, recorded as a `cargo` unit that takes part in cache invalidation
- Cargo dependency tables, including target-scoped `*.dependencies` tables and a
  crate's own `[dependencies.<crate>]` sub-table
- `[[bin]]` entries, plus the targets Cargo discovers itself: `src/main.rs` and
  the binaries under `src/bin/`. Discovery follows Cargo's own rules, so
  `autobins = false` turns it off, and on the 2015 edition (the default when
  `edition` is absent) a hand-written `[[bin]]` turns it off too
- the package's build script: the file `build =` names, or a `build.rs` beside
  the manifest, which Cargo runs before it compiles the crate

The manifest's `[package] name` becomes a `package` node when the crate root is
part of the scan. A declared binary path becomes an exact manifest entry point
for classification. If that path is absent, no node is invented.

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

A name that a glob import (`use crate::components::*;`) brings in resolves
through it, after the names the enclosing module declares.

A node's `local_id` and both ends of an edge are written
`rust:<kind>:<canonical>`. `canonical_name` itself carries no prefix. A raw
identifier is named without its `r#`: `mod r#async;` is the module
`crate::async`, the module of `src/async.rs`. When a
target cannot be resolved, Knossos keeps no edge rather than a guess. Repeated
edges collapse to the persistence identity of kind, source and target within one
contribution, and the earliest evidence is kept.

### Code nothing calls by name

These get an attribute, so they stay off the
[dead-code candidates](../concepts/dead-code-candidates.md) list:

- A crate-root `fn main` marks its module `executable`. Manifest entry-point
  classification can additionally mark every node from an exact binary path with
  `application.entry_point`.
- A function exported to a foreign caller (`#[no_mangle]`, `#[export_name]`,
  `#[wasm_bindgen]` or an `extern` ABI) is `runtime_invoked`. So are the public
  methods of a `#[wasm_bindgen] impl`, and `drop` in an `impl Drop`.
- A method of a trait impl carries `overrides`, because the trait declares it
  and the trait may be a dependency's.
- Code compiled only in a test build and `#[test]` functions (including
  `#[tokio::test]`) are marked as test code. The `cfg` predicate is evaluated:
  `cfg(test)` and `cfg(all(test, feature = "x"))` mark an item and everything in
  it, while `cfg(not(test))` and `cfg(any(test, feature = "x"))` stay production
  code, since a production build compiles them too.

### Speculative edges

Some names could refer to a type the graph does not hold, such as `Vec` or
`String`. For those the worker emits a `speculative` edge, and the core keeps it
only if the target turns out to be a declared node. That is how a method call on
a receiver of known type reaches its method: `self`, a typed parameter, a `let`
with a type annotation, or a `let` assigned from a struct literal or an
associated call such as `Widget::make()`. A call on what another call returns
(`state.mode().label()`) resolves through the declared return type.

## Frameworks

The core reads your Cargo manifests and tells the worker which of `axum`,
`actix` and `rocket` to enrich. You can set the same hints under `frameworks` in
your [project configuration](../get-started/project-configuration.md). Route
enrichment only runs for the frameworks it is asked for.

- Axum: `Router::route("/path", get(handler))` calls produce `route` nodes and
  `routes_to` edges.
- Actix: route attributes such as `#[get("/path")]`, `#[post(...)]` and
  `#[route("/path", method = "PUT")]`, and the
  `web::resource(...).route(web::get().to(handler))` shape.
- Rocket: verb attributes such as `#[get("/path")]`.

A handler function gets the `rust_framework_roles` attribute with
`rust.route_handler`. A route path that is not a literal is skipped with the
`RS_DYNAMIC_ROUTE_PATH` warning. Literal framework markers such as `<id>`,
`{id}` and `:id` count as dynamic too.

## Cross-file resolution

Each scan request builds a declaration index before it walks the files. The
index lets a cross-file `impl` block attach its methods to a uniquely declared
type, and lets a call into a child module point at a declaration in another
file. An ambiguous declaration is dropped rather than guessed. The index holds
every discovered `.rs` file of the project, read from disk within the byte cap,
whichever files the request names, so a file rescanned on its own resolves the
same names a full scan resolves. A file that does not parse adds nothing to it.
The worker keeps what each file gave the index for as long as its process
lives, keyed by the file's module and the hash of its bytes, so a later batch
reads and hashes every file but parses only the ones that changed. While
indexing takes long, it sends `scan/heartbeat`.

A file's module path follows from where it sits, not from `mod` declarations,
so a module file no `mod` declares is indexed like any other, and `#[path]`
attributes are not followed.

### What an incremental scan rescans

Each contribution names the files its facts were read from, so an incremental
scan rescans a file only when one of those changes:

- every file of every module above a name the walk asked the index about,
  found or not, including the paths such a file could have and that do not
  exist yet (`src/engine/sign.rs` and `src/engine/sign/mod.rs`, a member's
  `src/lib.rs`);
- every file of every module above the file's own, since a
  `#[cfg(test)] mod name;` there decides whether the file is test code;
- the `src/lib.rs` and `src/main.rs` of the package whose `src/` holds the
  file, since whether they exist decides the file's module path.

Editing `src/engine/sign.rs` therefore rescans the files that looked up a name
in that module and the files below it, not unrelated modules. Every name is
looked up below the crate root, so editing `src/lib.rs`, or adding a
`src/main.rs` or `src/lib.rs`, rescans the whole crate: that follows from what
the files read, not from a rule. A file that does not parse has no facts and
reads nothing beyond itself, so it stays an ordinary attributed row and is
rescanned only when it changes. Editing a `Cargo.toml` rescans every Rust file.
A rebuilt file reaches its readers only when its own bytes changed: a `pub use`
adds nothing to the index, so no file's facts depend on what another file read.

## Limits

- Macro bodies are not expanded, so a call a macro generates is invisible.
- `cfg`-gated code is walked in full, because the worker resolves no features. A
  symbol behind a disabled feature still appears.
- Generic instantiation is not resolved.
- A method call on a receiver whose type the source does not state produces no
  edge. The method name is recorded in the module's `unresolved_member_calls`,
  so a method by that name is only possibly dead.
- A call through a qualified self, such as `<Widget>::default()`, produces no
  edge. The parser renders that callee as the bare word `default`, which names
  no path the worker can confirm. The same holds for a call through an `Fn`
  receiver, `self()`.
- A call target's kind follows Rust's naming convention: an uppercase segment
  before the final one means a method, anything else means a function. A crate
  that breaks the convention can produce a target that matches no declared node.
- An import name bound to two different paths in one file resolves to nothing.
- A bare `mod foo;` declaration emits only a containment edge. The module's own
  node comes from the file that defines it.
- A `use` leaf that already names a module resolves to the module's parent:
  `use core::fmt;` emits `imports` to `core`, and `use crate::token;` emits it
  to `crate`. The declaration index records types, traits and functions rather
  than `mod` declarations, and import collection does not consult it, so every
  multi-segment leaf other than an explicit `self` is cut the same way.
- An `impl` whose target type is not declared in the project keeps its method
  nodes but drops the `contains` and `implements` edges whose source cannot be
  vouched for. That is a deliberate false negative rather than a wrong fact.
- The route recognizers cover the forms listed above. Macro expansion, runtime
  router composition and arbitrary framework wrappers are out of reach.

## Checks on the worker

The worker is checked with `cargo fmt`, `cargo clippy`, `cargo test` and the
shared scanner-conformance check. The repository's Rust integration suite drives
the real worker process over NDJSON-RPC.
