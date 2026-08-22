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

use std::collections::BTreeMap;

/// The names one file brought into scope with `use`.
///
/// Rust resolves a bare `Foo` against this map, so it is the only thing that
/// makes a call target in one file nameable as a path in another. It is
/// per-file by construction: `use` has no effect outside the module it appears
/// in.
#[derive(Debug, Default)]
pub struct Aliases {
    /// Local name to fully qualified path.
    entries: BTreeMap<String, String>,
}

impl Aliases {
    /// Record one imported name.
    pub fn insert(&mut self, alias: String, full_path: String) {
        self.entries.insert(alias, full_path);
    }

    /// The full path a local name stands for.
    #[must_use]
    pub fn resolve(&self, path: &str) -> Option<&str> {
        self.entries.get(path).map(String::as_str)
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

/// Flatten one `use` tree into `(local name, full path)` pairs, one per leaf.
///
/// `use a::{b, c as d};` yields `("b", "a::b")` and `("d", "a::c")`. A glob
/// contributes nothing: it names no symbol, so there is no alias to record and
/// guessing one would produce edges to names the file never mentions.
pub fn flatten_use(tree: &syn::UseTree, prefix: &str, out: &mut Vec<(String, String)>) {
    let join = |segment: &str| {
        if prefix.is_empty() {
            segment.to_owned()
        } else {
            format!("{prefix}::{segment}")
        }
    };
    match tree {
        syn::UseTree::Path(path) => flatten_use(&path.tree, &join(&path.ident.to_string()), out),
        syn::UseTree::Name(name) => {
            let ident = name.ident.to_string();
            out.push((ident.clone(), join(&ident)));
        }
        syn::UseTree::Rename(rename) => {
            out.push((rename.rename.to_string(), join(&rename.ident.to_string())));
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
                ("Serialize".to_owned(), "serde::Serialize".to_owned()),
                ("De".to_owned(), "serde::Deserialize".to_owned()),
            ],
            out
        );
    }
}
