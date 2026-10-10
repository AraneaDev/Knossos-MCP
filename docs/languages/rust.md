# Rust

Knossos scans Rust through the same out-of-process scanner protocol as the other
languages. The bundled worker parses `.rs` files with `syn` and never runs
`cargo` or `rustc` against your project.

Rust is optional on a native install: without `cargo` on the machine there is no
Rust worker, and `.rs` files are not scanned. The container image always has
one. `knossos doctor` tells you which workers you have, and warns when the Rust
worker binary was built from other source than your checkout, for example after
a `git pull`. Run `tools/install` to rebuild it.

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
| `calls`      | a resolved call expression                           | probable, see below    |
| `routes_to`  | a route to its handler                               | certain                |
| `references` | a function or type named without being called        | speculative, see below |
| `returns`    | a method to the type its signature declares          | speculative, see below |

A name that a glob import (`use crate::components::*;`) brings in resolves
through it, after the names the enclosing module declares. A type the module
declares shadows the glob for a path below it too (`Widget::new()`), and a
generic type parameter (`struct Gen<Thing>`) shadows any glob-imported type
of its name. A glob source provides a name it declares or re-exports with a
visible `use`, and the name resolves to where it is declared. A glob of a type
(`use Kind::*;`) brings in its variants, never its associated functions. A name two glob sources provide as
different items is ambiguous and resolves to nothing. A glob source that
provides no such name adds nothing, so `Vec` through `use crate::prelude::*;`
stays the standard library's.

A `use` inside a function body, or inside any block in it, imports for that
block only. Its names are in scope in the whole block, as Rust has it, over a
module-level `use` of the same name, and nothing after the block sees them.
Its path may start from a name the module or the block imports
(`use Kind::{Big, Small};` under `use crate::model::Kind;`). A path starting
from an item the block declares itself names nothing the graph holds, so the
name it binds resolves to nothing in that block. Paths, constructors and
receiver types inside the block resolve through it, and it emits the same
`imports` edge a module-level `use` does.

The same holds for a module-level `use`: its path may start from a name
another `use` of the same module imports, whichever line comes first
(`use m::W;` beside `use crate::m;` imports `crate::m::W`, and
`use collections::HashMap;` beside `use std::collections;` imports
`std::collections::HashMap`), or from an item the module declares
(`use Kind::A;` beside `enum Kind`). A name a module both declares and imports
belongs to `#[cfg]` alternatives (`pub use LazyCell as LazyLock;` beside a
`struct LazyLock` under another `cfg`), so it keeps naming the declared item and
re-exports nothing.

A call whose callee is named in UpperCamelCase builds a value: `Wrapper(1)`
constructs a tuple struct and `Error::Io(e)` an enum variant. Neither is a
`calls` edge; each is a `references` edge to the type it builds. A call into
your own crates is speculative, since its kind (function or method) is read
from the name: the edge is kept when the graph declares that target and
dropped otherwise, rather than becoming an external symbol. A call into
another crate keeps its external target.

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
  `cfg(test)` and `cfg(all(test, feature = "x"))` mark an item (a module, a
  function, an `impl`, or a single method) and everything in it, while `cfg(not(test))` and `cfg(any(test, feature = "x"))` stay production
  code, since a production build compiles them too.

### Speculative edges

Some names could refer to a type the graph does not hold, such as `Vec` or
`String`. For those the worker emits a `speculative` edge, and the core keeps it
only if the target turns out to be a declared node. That is how a method call on
a receiver of known type reaches its method: `self`, a typed parameter or
closure parameter (`|s: &Store|`), a `let` with a type annotation, a `let`
assigned from a struct literal or an associated call such as `Widget::make()`,
or a binding a struct pattern takes from a field
(`let Index { store, .. } = index;`), also in a `match` arm, an `if let` or
`while let` chain or a `for` pattern. Each binding lasts as long as its scope,
a plain block included. A type behind `&`, `&mut`, `Box<T>`, `Rc<T>` or
`Arc<T>` is `T`, except for a method the pointer itself has (`clone`, `as_ref`,
`downgrade` and the like), which is left untyped. A call on what another call
returns (`state.mode().label()`) resolves through the declared return type. A
call through a field (`self.walk.facts.edge()`) resolves through the field's
declared type, also when the struct, its `impl` block and the field's type sit
in three different files: the declaration index holds every struct's field
types as the declaring file's own imports resolve them. A field type that file
names only through a glob import (`use crate::prelude::*;`, `use super::*;`) is
kept as the bare name with the glob's source modules and resolved through the
whole index when a call looks it up, so the caller's file reads the glob
sources it consulted. `#[cfg]` alternatives of one struct keep only the field
types they agree on.

A path that reaches an item through a re-export (`crate::visit::collect()`
under `pub use cfg::collect;` in `visit`) names the item where it is declared,
`crate::visit::cfg::collect`, so the edge lands on the node the graph holds.
Any visible `use` re-exports, `pub(crate)` and `pub(super)` included, and a
chain of them is followed to its end, also through a module that re-exports an
item under its own name (`pub use parse::parse;` beside `mod parse;`). A longer
path through such a name (`parse::helper()`) goes through the module. The
`imports` edge still names the module the source wrote.

A prelude trait named bare (`impl From<u8> for Str`, `trait Named: Clone`)
that the module neither declares nor imports is the standard library's
(`std::convert::From`), never a trait of the enclosing module or the crate
root. A trait outside the prelude (`Display`, `Hash`, `FromStr`) is in scope
only through an import, which is how it resolves. Likewise a trait impl for a
primitive or prelude type named bare (`impl PartialEq<u8> for String`,
`impl Shout for str`) is a block of its own, `<impl PartialEq for String>`,
since the crate does not own the type, never a `String` of the enclosing
module.

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
so a module file no `mod` declares is indexed like any other. `src/lib.rs` and
`src/main.rs` are the crate root (`src/main.rs` is `crate::main` beside a
library); any other `lib.rs` or `main.rs` is a module of its own, so
`src/net/lib.rs` is `crate::net::lib` and `src/bin/tool/main.rs` is
`crate::bin::tool::main`. Outside `src/`, a file keeps its directory chain
(`tests/smoke.rs` is `tests::smoke`), and only files under `tests/`,
`examples/` and `benches/` enter the index, since Rust reaches no other file
there without `#[path]`. A file elsewhere outside `src/`, such as
`crate/engine.rs`, keeps its own nodes but is not indexed, and its directory
chain can give it the same id as a file under `src/` (`crate::engine`, the
module of `src/engine.rs`).

The package at the project root is rooted at `crate`, and a workspace member
at its crate name (`crates/core-lib/src/app.rs` is `core_lib::app`). Code
outside a library names it by its crate name: the `[lib] name` in the
manifest, else the package name with dashes as underscores. Such a path is
placed on the library's root, so `my_demo::run()` in `src/main.rs`,
`src/bin/`, `tests/`, `examples/` or `benches/` calls `crate::run`, the
function `src/lib.rs` declares.

A `mod name;` declaration names the module of the file Rust loads for it, as
that file is placed. A crate root and a `mod.rs` keep their children beside
them, so `mod cli;` in `src/main.rs` is `crate::cli` (`src/cli.rs`), and
`mod common;` in `tests/it.rs` is `tests::common`. `#[path = "x.rs"]` is
followed: the declaration names the module of `x.rs`, and a path through the
declared name reaches it, in that file and in any other (`crate::a::x` under
`#[path = "impl_a.rs"] mod a;` is `crate::impl_a::x`). Two declarations of one
name that load different files (under different `cfg`s) make paths through
that name resolve to nothing.

Each binary in `src/bin/`, integration test, example and benchmark is a crate
of its own, and its `crate::` names that crate, whose root is the file itself:
`crate::own` in `src/bin/tool.rs` is the `crate::bin::tool::own` it declares,
and through its `mod helper;`, `crate::helper` is `crate::bin::helper`
(`src/bin/helper.rs`); `crate::common` in `tests/it.rs` is `tests::common`.
Only the target's root file knows this; a module file below `src/bin/<name>/`
could belong to that binary or to another, so its own `crate::` paths still
start at the package's `crate`. An out-of-line `#[cfg(test)] mod name;` marks
the file it loads as test code, its module node included, when the declaring
file sits in a module above it, or is the crate root; a `#[path]` that sends a
test module to a sibling (`src/net.rs` loading `src/net_tests.rs`) is not
marked.

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
  file, or a file one of its `mod` declarations loads, since whether they
  exist decides those module paths.

Editing `src/engine/sign.rs` therefore rescans the files that looked up a name
in that module and the files below it, not unrelated modules. Every name is
looked up below the crate root, so editing `src/lib.rs`, or adding a
`src/main.rs` or `src/lib.rs`, rescans the whole crate: that follows from what
the files read, not from a rule. A file that does not parse has no facts and
reads nothing beyond itself, so it stays an ordinary attributed row and is
rescanned only when it changes. Editing a `Cargo.toml` rescans every Rust file.
A rebuilt file reaches its readers only when its own bytes changed: what a file
gives the index (its declarations, the names its `pub use` items re-export and
its structs' field types) follows from its own bytes and the package layout, so
no file's facts depend on what another file read. A name followed through a
re-export or a field type is a lookup like any other, so the file declaring it
is read.

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
  before the final one means a method, anything else means a function, and an
  uppercase final segment means a constructor. A crate that breaks the
  convention can produce a target that matches no declared node; inside your
  own crates that edge is dropped.
- An import name bound to two different paths in one file resolves to nothing.
  So does a name two `#[cfg]` alternatives re-export from different paths.
- A private `use` re-exports nothing to the index, so a child module reaching
  an imported name through `super::name` is not followed to its declaration.
  A glob re-export (`pub use inner::*;`) is not followed either.
- Re-exports are resolved through the declaring file's own `use` items, its
  own declarations and rooted paths only. A glob source provides only what it
  declares or re-exports by name: a name it brings in through a glob of its
  own, or through a private `use`, is not followed.
- A type named bare that nothing in scope declares or imports (`Vec`,
  `Option`, `String`, `char`, `u8`) types no receiver, so a method called on
  one produces no edge rather than one to a made-up type of the module. A
  generic other than `Box`, `Rc` and `Arc` (`Option<T>`, `Vec<T>`,
  `Mutex<T>`) is not unwrapped, so a method called on what it holds
  (`self.items[0].run()`, `self.lock.lock().run()`) is not resolved.
- Visibility is not checked. A glob source provides every item it declares,
  private ones included, so a private item of a sibling module reached through
  its glob can win over the prelude or make a name ambiguous where Rust would
  not import it.
- A glob `use` inside a function body (`use Kind::*;` before a `match`) is not
  read, so a name it brings into scope resolves as if it were absent.
- A bare `mod foo;` declaration emits only a containment edge. The module's own
  node comes from the file that defines it.
- A `use` leaf whose parent is a type (`use crate::errors::Error::Io;`, or
  `use Error::*;`) references that type instead of importing a module.
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
