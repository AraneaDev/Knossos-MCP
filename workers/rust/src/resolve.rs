//! Turning a file path into the Rust module path it declares.
//!
//! This is a path convention, not a resolution of `mod` declarations: the worker
//! sees one file per call and has no crate-wide view. The convention is Rust's
//! own, so it agrees with the compiler for every layout that follows it.

/// The canonical module path a source file declares.
///
/// `src/` is the crate root, so `src/lib.rs` and `src/main.rs` are `crate` itself
/// and `src/net/http.rs` is `crate::net::http`. A `mod.rs` collapses into the
/// directory that holds it. A path outside `src/` keeps its own directory chain,
/// which is what `tests/` and `benches/` want: each of those files is its own
/// crate root to the compiler, and pretending otherwise would collide their
/// symbols with the library's.
#[must_use]
pub fn module_path(relative: &str) -> String {
    let trimmed = relative.strip_suffix(".rs").unwrap_or(relative);
    let mut segments: Vec<&str> = trimmed.split('/').filter(|part| !part.is_empty()).collect();
    let in_crate = segments.first() == Some(&"src");
    if in_crate {
        segments.remove(0);
    }
    if matches!(segments.last(), Some(&"mod")) {
        segments.pop();
    }
    if in_crate && matches!(segments.last(), Some(&"lib") | Some(&"main")) {
        segments.pop();
    }
    if in_crate {
        segments.insert(0, "crate");
    }
    if segments.is_empty() {
        return "crate".to_owned();
    }

    segments.join("::")
}

/// Rebase a `self`- or `super`-rooted path against `module`, the module the
/// path was written in.
///
/// `self::foo` names `foo` in `module` itself: `crate::net`, `self::foo` is
/// `crate::net::foo`. `super::foo` names `foo` in `module`'s parent, and each
/// leading `super` segment strips one more segment from `module` before the
/// rest is appended: `crate::net::http`, `super::foo` is `crate::net::foo`,
/// and `super::super::foo` is `crate::foo`. A path with neither prefix is
/// returned unchanged.
///
/// `module` always starts with a `crate` segment (see [`module_path`]), and
/// that segment has no parent of its own, so a `super` that would strip it —
/// directly, or through a chain longer than the module has segments to give
/// up — escapes the crate root and names nothing resolvable. `None` marks
/// that case rather than inventing a path.
#[must_use]
pub fn rebase(module: &str, path: &str) -> Option<String> {
    if path == "self" {
        return Some(module.to_owned());
    }
    if let Some(rest) = path.strip_prefix("self::") {
        return Some(format!("{module}::{rest}"));
    }

    let mut base: Vec<&str> = module.split("::").collect();
    let mut rest = path;
    let mut stripped_any = false;
    while let Some(tail) =
        rest.strip_prefix("super::")
            .or(if rest == "super" { Some("") } else { None })
    {
        if base.len() <= 1 {
            return None;
        }
        base.pop();
        stripped_any = true;
        rest = tail;
        if rest.is_empty() {
            break;
        }
    }

    if !stripped_any {
        return Some(path.to_owned());
    }
    if rest.is_empty() {
        Some(base.join("::"))
    } else {
        Some(format!("{}::{rest}", base.join("::")))
    }
}

/// The module path that contains `path`, the enclosing scope a `use` line
/// imports its symbol *from*.
///
/// `use a::b::C;` names the symbol `C` living in the module `a::b`, so the
/// module is everything before the final segment. A single-segment path names
/// a module in its own right (`use serde;`) and has no prefix to strip, so it
/// is returned unchanged rather than collapsed to nothing.
#[must_use]
pub fn parent_module(path: &str) -> &str {
    match path.rsplit_once("::") {
        Some((parent, _)) => parent,
        None => path,
    }
}

use std::collections::BTreeMap;

/// The names one file brought into scope with `use`.
///
/// Rust resolves a bare `Foo` against this map, so it is the only thing that
/// makes a call target in one file nameable as a path in another. It is
/// per-file by construction: `use` has no effect outside the module it appears
/// in.
///
/// The map is flat across the whole file rather than scoped per `mod` block
/// (see `Walk::collect_uses`), so two `use` lines in different modules that
/// import different full paths under the same local name collide. Letting
/// the later insert silently win would misattribute a call resolved against
/// the earlier module's alias to the later module's import — a wrong edge,
/// not a missing one. This worker's stated principle is that an unresolved
/// target is dropped rather than guessed, and a colliding alias is exactly
/// that: unresolved. So a collision poisons the key instead: `resolve`
/// returns `None` for it from then on, permanently, even if a later insert
/// would have resolved unambiguously on its own. Losing an edge is
/// recoverable; a wrong edge actively misleads whoever reads the graph.
/// Re-inserting the *same* full path under a key is not a collision — that
/// happens legitimately when the same `use` line is collected from two
/// nested modules — so it stays resolvable.
#[derive(Debug, Default)]
pub struct Aliases {
    /// Local name to fully qualified path, or `None` when the local name is
    /// poisoned: two different full paths were imported under it.
    entries: BTreeMap<String, Option<String>>,
}

impl Aliases {
    /// Record one imported name.
    ///
    /// Inserting a full path that differs from what this alias already
    /// resolved to poisons it (see the struct docs). Inserting the same full
    /// path again, or inserting into an alias that is already poisoned, is a
    /// no-op.
    pub fn insert(&mut self, alias: String, full_path: String) {
        match self.entries.get(&alias) {
            Some(Some(existing)) if existing != &full_path => {
                self.entries.insert(alias, None);
            }
            Some(_) => {}
            None => {
                self.entries.insert(alias, Some(full_path));
            }
        }
    }

    /// The full path a local name stands for.
    ///
    /// Returns `None` both when the name was never imported and when it was
    /// imported ambiguously — two different full paths under the same local
    /// name — since neither case names a full path this worker can vouch for.
    #[must_use]
    pub fn resolve(&self, path: &str) -> Option<&str> {
        self.entries.get(path).and_then(|full| full.as_deref())
    }

    /// Whether `head` names a local alias that was imported ambiguously —
    /// two different full paths under the same local name — as opposed to
    /// simply never having been imported at all.
    ///
    /// `resolve` and `expand` collapse both cases to `None`, which is right
    /// for them: neither can vouch for a full path either way. A caller that
    /// falls back to guessing when a name is *unknown* must not take that
    /// same fallback when the name is *known and poisoned* — see
    /// `Walk::path_target`, whose container-relative guess is only ever
    /// correct for a name nobody imported, never for one two conflicting
    /// imports fought over.
    #[must_use]
    pub fn is_ambiguous(&self, head: &str) -> bool {
        matches!(self.entries.get(head), Some(None))
    }

    /// A path with its leading segment expanded through the map.
    ///
    /// `http::get` becomes `crate::net::http::get` when `http` was imported.
    /// A path whose head is unknown returns `None` rather than a guess: an
    /// unresolved target is dropped, never invented.
    #[must_use]
    pub fn expand(&self, path: &str) -> Option<String> {
        let (head, rest) = match path.split_once("::") {
            Some((head, rest)) => (head, Some(rest)),
            None => (path, None),
        };
        let full = self.resolve(head)?;

        Some(match rest {
            Some(rest) => format!("{full}::{rest}"),
            None => full.to_owned(),
        })
    }
}

/// One leaf of a flattened `use` tree: a single name the line brings into scope.
#[derive(Debug, PartialEq, Eq)]
pub struct UseLeaf {
    /// The local name this file can write to mean [`UseLeaf::full`].
    pub alias: String,
    /// The fully qualified path the local name stands for.
    pub full: String,
    /// Whether `full` already names a module rather than a symbol inside one.
    ///
    /// Only a `self` leaf sets this: `use crate::foo::{self, bar};` binds `foo`
    /// to the module `crate::foo` itself, where every other leaf binds a name
    /// declared *inside* a module. The distinction is invisible in `full`
    /// alone — `use crate::foo;` produces exactly the same pair — and it
    /// decides which module an `imports` edge should point at, so the leaf
    /// carries it rather than leaving the caller to guess.
    pub names_module: bool,
}

/// Flatten one `use` tree into one [`UseLeaf`] per leaf.
///
/// `use a::{b, c as d};` yields `b` bound to `a::b` and `d` bound to `a::c`. A
/// glob contributes nothing: it names no symbol, so there is no alias to record
/// and guessing one would produce edges to names the file never mentions. A
/// `self` leaf names the prefix itself rather than a `prefix::self` path that
/// does not exist: `use crate::foo::{self, bar};` yields `foo` bound to
/// `crate::foo` (the prefix, under its own last segment as the local name)
/// alongside `bar` bound to `crate::foo::bar`. A bare `use self;` with no
/// prefix names nothing and is skipped rather than emitting an empty path.
pub fn flatten_use(tree: &syn::UseTree, prefix: &str, out: &mut Vec<UseLeaf>) {
    let join = |segment: &str| {
        if prefix.is_empty() {
            segment.to_owned()
        } else {
            format!("{prefix}::{segment}")
        }
    };
    match tree {
        syn::UseTree::Path(path) => flatten_use(&path.tree, &join(&path.ident.to_string()), out),
        syn::UseTree::Name(name) if name.ident == "self" => {
            if let Some(local) = prefix
                .rsplit("::")
                .next()
                .filter(|segment| !segment.is_empty())
            {
                out.push(UseLeaf {
                    alias: local.to_owned(),
                    full: prefix.to_owned(),
                    names_module: true,
                });
            }
        }
        syn::UseTree::Name(name) => {
            let ident = name.ident.to_string();
            out.push(UseLeaf {
                alias: ident.clone(),
                full: join(&ident),
                names_module: false,
            });
        }
        syn::UseTree::Rename(rename) => {
            out.push(UseLeaf {
                alias: rename.rename.to_string(),
                full: join(&rename.ident.to_string()),
                names_module: false,
            });
        }
        syn::UseTree::Group(group) => {
            for item in &group.items {
                flatten_use(item, prefix, out);
            }
        }
        syn::UseTree::Glob(_) => {}
    }
}

#[cfg(test)]
mod tests {
    use super::module_path;

    #[test]
    fn lib_and_main_collapse_to_the_crate_root() {
        assert_eq!("crate", module_path("src/lib.rs"));
        assert_eq!("crate", module_path("src/main.rs"));
    }

    #[test]
    fn a_plain_file_becomes_a_child_of_the_crate() {
        assert_eq!("crate::greeting", module_path("src/greeting.rs"));
        assert_eq!("crate::net::http", module_path("src/net/http.rs"));
    }

    #[test]
    fn mod_rs_collapses_into_its_directory() {
        assert_eq!("crate::net", module_path("src/net/mod.rs"));
    }

    #[test]
    fn a_path_outside_src_keeps_its_directory_chain() {
        assert_eq!("tests::integration", module_path("tests/integration.rs"));
    }

    #[test]
    fn an_alias_resolves_to_its_full_path() {
        let mut aliases = super::Aliases::default();
        aliases.insert("De".to_owned(), "serde::Deserialize".to_owned());

        assert_eq!(Some("serde::Deserialize"), aliases.resolve("De"));
        assert_eq!(None, aliases.resolve("Unknown"));
    }

    #[test]
    fn a_leading_alias_segment_is_expanded_in_place() {
        let mut aliases = super::Aliases::default();
        aliases.insert("http".to_owned(), "crate::net::http".to_owned());

        assert_eq!(
            Some("crate::net::http::get"),
            aliases.expand("http::get").as_deref()
        );
    }

    #[test]
    fn a_brace_group_flattens_to_one_entry_per_leaf() {
        let tree: syn::UseTree = syn::parse_str("serde::{Serialize, Deserialize as De}").unwrap();
        let mut out = Vec::new();
        super::flatten_use(&tree, "", &mut out);

        assert_eq!(
            vec![
                super::UseLeaf {
                    alias: "Serialize".to_owned(),
                    full: "serde::Serialize".to_owned(),
                    names_module: false,
                },
                super::UseLeaf {
                    alias: "De".to_owned(),
                    full: "serde::Deserialize".to_owned(),
                    names_module: false,
                },
            ],
            out
        );
    }

    #[test]
    fn a_self_leaf_names_its_prefix_under_the_prefixes_last_segment() {
        let tree: syn::UseTree = syn::parse_str("crate::foo::{self, bar}").unwrap();
        let mut out = Vec::new();
        super::flatten_use(&tree, "", &mut out);

        assert_eq!(
            vec![
                super::UseLeaf {
                    alias: "foo".to_owned(),
                    full: "crate::foo".to_owned(),
                    names_module: true,
                },
                super::UseLeaf {
                    alias: "bar".to_owned(),
                    full: "crate::foo::bar".to_owned(),
                    names_module: false,
                },
            ],
            out
        );
    }

    #[test]
    fn a_bare_use_self_with_no_prefix_names_nothing() {
        let tree: syn::UseTree = syn::parse_str("self").unwrap();
        let mut out = Vec::new();
        super::flatten_use(&tree, "", &mut out);

        assert!(out.is_empty());
    }

    #[test]
    fn colliding_aliases_for_different_paths_become_unresolvable() {
        let mut aliases = super::Aliases::default();
        aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());
        aliases.insert("Widget".to_owned(), "bar::Widget".to_owned());

        assert_eq!(None, aliases.resolve("Widget"));
    }

    #[test]
    fn repeated_inserts_of_the_same_path_stay_resolvable() {
        let mut aliases = super::Aliases::default();
        aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());
        aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());

        assert_eq!(Some("foo::Widget"), aliases.resolve("Widget"));
    }

    #[test]
    fn a_symbol_path_yields_the_module_that_declares_it() {
        assert_eq!("a::b", super::parent_module("a::b::C"));
        assert_eq!("crate::net", super::parent_module("crate::net::http"));
    }

    #[test]
    fn a_single_segment_path_is_its_own_module() {
        assert_eq!("serde", super::parent_module("serde"));
    }

    #[test]
    fn a_path_with_neither_prefix_is_returned_unchanged() {
        assert_eq!(
            Some("crate::net::Engine".to_owned()),
            super::rebase("crate::net", "crate::net::Engine")
        );
    }

    #[test]
    fn a_self_prefix_rebases_against_its_own_module() {
        assert_eq!(
            Some("crate::net::inner::Thing".to_owned()),
            super::rebase("crate::net", "self::inner::Thing")
        );
    }

    #[test]
    fn a_super_prefix_rebases_against_the_parent_module() {
        assert_eq!(
            Some("crate::net::Parent".to_owned()),
            super::rebase("crate::net::http", "super::Parent")
        );
    }

    #[test]
    fn a_super_chain_strips_one_segment_per_super() {
        assert_eq!(
            Some("crate::Parent".to_owned()),
            super::rebase("crate::net::http", "super::super::Parent")
        );
    }

    #[test]
    fn a_super_chain_escaping_the_crate_root_resolves_to_nothing() {
        assert_eq!(
            None,
            super::rebase("crate::net", "super::super::Unreachable")
        );
    }

    #[test]
    fn super_from_the_crate_root_itself_resolves_to_nothing() {
        assert_eq!(None, super::rebase("crate", "super::Unreachable"));
    }
}
