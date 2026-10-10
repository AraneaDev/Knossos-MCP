//! The state a walk carries: the [`Walk`] over one file, the [`Calls`]
//! visitor over one body, and the routes found along the way.
//!
//! Kept apart from the code that walks so every sibling module can extend
//! these types with its own `impl` blocks, while their fields stay private
//! to `visit`.

use std::collections::{BTreeMap, BTreeSet};

use super::declarations::{Declarations, FieldType};
use crate::facts::Facts;
use crate::layout::Layout;
use crate::resolve::Aliases;

/// A walk in progress: the facts being built plus the names in scope.
pub(super) struct Walk<'a> {
    /// Where facts accumulate.
    pub(super) facts: &'a mut Facts,
    /// The file's module path, which owns its imports.
    pub(super) module: String,
    /// Names this file brought into scope.
    pub(super) aliases: Aliases,
    /// The modules this file's glob imports (`use a::b::*;`) bring every
    /// public name of into scope, each with the module that declares it, in
    /// the order they were written.
    pub(super) globs: Vec<(String, String)>,
    /// Glob imports as written, with their module, whether the path is
    /// absolute (`::dep::*`), and span, until every `use` in the file is
    /// known: a glob's path may start with an alias declared later in the
    /// same module.
    pub(super) pending_globs: Vec<(String, String, bool, proc_macro2::Span)>,
    /// Each module's own imported names, for a glob's leading alias: a `use`
    /// is scoped to the module that declares it, so sibling modules may bind
    /// one alias to different paths. `None` marks a name bound twice.
    pub(super) module_aliases: BTreeMap<(String, String), Option<String>>,
    /// Each module's `mod` declarations, by module then name, mapped to the
    /// module each loads (see [`mod_child`](super::placement::mod_child)):
    /// what a `use` inside a body of that module reaches a child through.
    /// `None` marks a name two declarations send to two modules.
    pub(super) module_children: BTreeMap<String, BTreeMap<String, Option<String>>>,
    /// The generic type parameters in scope (`T` of `impl<T>`, `fn f<T>`,
    /// `struct S<T>`), which shadow any type of that name: a receiver of
    /// one has no type the walk can name.
    pub(super) type_params: BTreeSet<String>,
    /// Target type of the current impl block, for resolving `Self`.
    pub(super) current_impl_target: Option<String>,
    /// Frameworks the scan request asked this worker to enrich, by short name
    /// (`axum`, `actix`, `rocket`). Empty means none.
    pub(super) frameworks: &'a [String],
    /// Scan-wide declaration index, see [`Declarations`].
    pub(super) declarations: &'a Declarations,
    /// Route facts found during the walk, flushed in [`Walk::flush_routes`].
    pub(super) routes: Vec<RouteCandidate>,
    /// Framework roles discovered for handlers in this file, applied by
    /// canonical name once the walk is complete.
    pub(super) role_marks: Vec<String>,
    /// The field types of each struct this file declares, by struct then
    /// field name, so `self.walk.facts.edge()` resolves through the fields.
    pub(super) struct_fields: BTreeMap<String, BTreeMap<String, FieldType>>,
    /// Where the project's crates are, which names each `mod` declaration's
    /// module (see [`mod_child`](super::placement::mod_child)).
    pub(super) layout: &'a Layout,
    /// The file's project-relative path, which the `mod` declarations in it
    /// load their files beside.
    pub(super) relative: String,
    /// Each `mod name;` whose module is not `container::name` (a binary
    /// root's or a test target's children, `#[path]`), by its container and
    /// name: a path through `name` there names that module. `None` marks a
    /// name two declarations (under different `cfg`s) send to two modules.
    pub(super) renamed_children: BTreeMap<(String, String), Option<String>>,
    /// What `crate` names in this file: the package's crate root, or for a
    /// target root (a binary in `src/bin/`, a test, an example) the file's
    /// own module, so `crate::own` in `src/bin/tool.rs` is
    /// `crate::bin::tool::own`. Its `mod helper;` loads `src/bin/helper.rs`,
    /// and `crate::helper` reaches that through the renamed declaration (see
    /// [`Declarations::renamed`]).
    pub(super) crate_module: String,
    /// The files this file's `mod` declarations load, whose own placement
    /// decided the module each declaration names.
    pub(super) placed: BTreeSet<String>,
    /// Every name a visible `use` (`pub use`, `pub(crate) use`) makes a
    /// path of the module it is written in, mapped to the path it imports:
    /// what the declaration index follows a re-exported path through (see
    /// [`Declarations::exported`]).
    /// `None` marks a name two `use` items bind to different paths.
    pub(super) exports: BTreeMap<String, Option<String>>,
    /// Every path this file declares (see
    /// [`declaration_paths`](super::declarations::declaration_paths)), which
    /// confirms a type the file names unqualified in its own module whether
    /// or not the index answers for the file.
    pub(super) own_declarations: BTreeSet<String>,
}

/// A `syn` visitor that emits a `calls` edge for every resolvable call
/// expression it walks through.
///
/// Kept as a small, separate `Visit` implementation (rather than inline
/// closures in [`Walk::walk_body`]) so the call-resolution logic stays a
/// single, shallow method, [`Calls::visit_call`] in `calls.rs`, instead of
/// growing the cognitive complexity of a larger function.
pub(super) struct Calls<'a, 'b> {
    /// The walk collecting facts.
    pub(super) walk: &'a mut Walk<'b>,
    /// Full `rust:<kind>:<canonical>` reference of the function or method
    /// whose body this is; the source of every `calls` edge emitted while
    /// walking it.
    pub(super) enclosing: String,
    /// The module `enclosing` is declared in — never an `impl` block's type
    /// path, since free functions and modules, the only things an
    /// unqualified call can name, live in module scope. Passed to
    /// [`Walk::path_target`] as the container an unqualified or relative
    /// path resolves against.
    pub(super) container: String,
    /// Local names whose type the source states, mapped to that type's
    /// canonical path: `self`, typed parameters, and `let` bindings that are
    /// annotated or constructed through a path. What a method call on one of
    /// them resolves through.
    pub(super) receivers: BTreeMap<String, String>,
}

/// One route fact discovered while walking, emitted after the walk.
///
/// Unlike ordinary nodes, a route's handler may be declared later in the file
/// than the routing call that names it (Rust is order-agnostic), so routes are
/// deferred until the whole file's declarations are known.
pub(super) struct RouteCandidate {
    /// Semantic framework name (`axum`, `actix`, `rocket`) for the node's
    /// `framework` attribute.
    pub(super) framework: &'static str,
    /// HTTP method, uppercase.
    pub(super) method: String,
    /// The path as written, when it is a static literal.
    pub(super) path: String,
    /// Handler's resolved canonical path, or the container-relative guess
    /// when nothing resolved it.
    pub(super) handler: String,
    /// Whether `handler` is that guess — needs the same-file declarations to
    /// confirm it, like a call target.
    pub(super) handler_unconfirmed: bool,
    /// Where the routing statement is, for evidence.
    pub(super) span: proc_macro2::Span,
}
