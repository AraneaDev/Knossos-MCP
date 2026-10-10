//! The crate-wide declaration index every walk resolves names against.
//!
//! Each file contributes the paths it declares and the `mod` declarations
//! that place a module somewhere other than its declared path; a walk then
//! asks the index whether a name it could not resolve locally is declared
//! exactly once in the project.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet};

use syn::{Item, Type};

use super::mod_child;
use crate::layout::Layout;
use crate::resolve::ident_name;

/// Canonical name of every top-level and inline-module declaration of the
/// project's Rust files, mapped to how many files declared it. The crate-wide
/// view that lets an `impl` block attach to a type declared in another file
/// and a child-module call resolve to its real target. A count above one means
/// the name is ambiguous across the project and must not be trusted.
///
/// Every name looked up is remembered, found or not, until
/// [`Declarations::take_lookups`] hands the set over: a walk's answer depends
/// on each name it asked about, so the files that could declare those names
/// are the ones its facts were read from.
#[derive(Debug, Default)]
pub struct Declarations {
    /// Canonical name to the number of files declaring it.
    counts: BTreeMap<String, usize>,
    /// Every name asked about since the last [`Declarations::take_lookups`].
    lookups: RefCell<BTreeSet<String>>,
    /// Each `mod name;` whose module is not the path it is declared at
    /// (`#[path]`, a binary root's children), by that declared path; `None`
    /// when two declarations send one path to two modules. See
    /// [`declared_renames`].
    renames: BTreeMap<String, Option<String>>,
}

impl Declarations {
    /// An empty index.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// How many files declare `name`, remembering that it was asked about.
    pub fn get(&self, name: &str) -> Option<&usize> {
        self.lookups.borrow_mut().insert(name.to_owned());
        self.counts.get(name)
    }

    /// Count one file's declarations, as [`declaration_paths`] returned them.
    pub fn add_file(&mut self, paths: &BTreeSet<String>) {
        for path in paths {
            *self.counts.entry(path.clone()).or_insert(0) += 1;
        }
    }

    /// Record one file's renamed `mod` declarations, as [`declared_renames`]
    /// returned them; a path two declarations send apart resolves to nothing.
    pub fn add_renames(&mut self, renames: &BTreeMap<String, Option<String>>) {
        for (declared, placed) in renames {
            merge_rename(&mut self.renames, declared, placed.clone());
        }
    }

    /// `path` with its longest prefix that a `mod` declaration renamed
    /// rewritten onto the module that declaration loads, repeatedly, so
    /// `crate::a::x` under `#[path = "impl_a.rs"] mod a;` is
    /// `crate::impl_a::x`. `Some(None)` when a prefix is ambiguous, `None`
    /// when no prefix was renamed. Every path asked about is remembered as a
    /// lookup: the declaring file sits in a module above it.
    #[allow(clippy::option_option)]
    pub fn renamed(&self, path: &str) -> Option<Option<String>> {
        let mut current = path.to_owned();
        let mut changed = false;
        for _ in 0..=path.matches("::").count() {
            self.lookups.borrow_mut().insert(current.clone());
            let found = std::iter::successors(Some(current.as_str()), |prefix| {
                prefix.rsplit_once("::").map(|(head, _)| head)
            })
            .find_map(|prefix| Some((prefix.len(), self.renames.get(prefix)?)));
            match found {
                Some((_, None)) => return Some(None),
                Some((length, Some(placed))) => {
                    current = format!("{placed}{}", &current[length..]);
                    changed = true;
                }
                None => break,
            }
        }

        changed.then_some(Some(current))
    }

    /// Every name asked about since the last call, leaving the set empty.
    pub fn take_lookups(&self) -> BTreeSet<String> {
        std::mem::take(&mut *self.lookups.borrow_mut())
    }
}

/// Record that `declared` names `placed`, or that it is ambiguous when an
/// earlier declaration sent it elsewhere.
fn merge_rename(
    renames: &mut BTreeMap<String, Option<String>>,
    declared: &str,
    placed: Option<String>,
) {
    match renames.get(declared) {
        Some(existing) if *existing != placed => {
            renames.insert(declared.to_owned(), None);
        }
        Some(_) => {}
        None => {
            renames.insert(declared.to_owned(), placed);
        }
    }
}

/// Every `mod name;` in one file whose module (see [`mod_child`]) is not the
/// path it is declared at, by that path: what another file writing
/// `crate::name::x` must be rewritten through. `None` marks a path two
/// declarations of the file send to two modules.
#[must_use]
pub fn declared_renames(
    relative: &str,
    module: &str,
    items: &[Item],
    layout: &Layout,
) -> BTreeMap<String, Option<String>> {
    let mut out = BTreeMap::new();
    collect_renames(relative, module, module, items, layout, &mut out);

    out
}

/// [`declared_renames`] for the items of `container`.
fn collect_renames(
    relative: &str,
    module: &str,
    container: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut BTreeMap<String, Option<String>>,
) {
    let mut seen = BTreeMap::new();
    for item in items {
        let Item::Mod(node) = item else {
            continue;
        };
        let (child, _) = mod_child(relative, module, container, node, layout);
        let declared = format!("{container}::{}", ident_name(&node.ident));
        match &node.content {
            Some((_, inner)) => collect_renames(relative, module, &child, inner, layout, out),
            None => merge_rename(&mut seen, &declared, Some(child)),
        }
    }
    out.extend(
        seen.into_iter()
            .filter(|(declared, placed)| placed.as_deref() != Some(declared.as_str())),
    );
}

/// Every declaration a file can serve to the scan-wide index: the canonical
/// path of each struct, enum, union, trait, and top-level function, plus
/// inline modules recursed into. `mod foo;` file modules derive their own
/// entries when their defining file is indexed.
///
/// A file counts once per path: `#[cfg]` alternatives of one item are one
/// declaration, compiled in whichever form the target takes. Two files
/// declaring one path still make it ambiguous.
pub fn collect_declarations(module: &str, items: &[Item], out: &mut Declarations) {
    out.add_file(&declaration_paths(module, items));
}

/// The paths one file declares, as [`collect_declarations`] counts them, for
/// a caller that keeps them apart from the index (see [`Declarations::add_file`]).
#[must_use]
pub fn declaration_paths(module: &str, items: &[Item]) -> BTreeSet<String> {
    let mut paths = BTreeSet::new();
    collect_declaration_paths(module, items, &mut paths);

    paths
}

/// The paths [`collect_declarations`] indexes for one file, each once.
fn collect_declaration_paths(module: &str, items: &[Item], out: &mut BTreeSet<String>) {
    for item in items {
        match item {
            Item::Struct(node) => record(out, module, &ident_name(&node.ident)),
            Item::Enum(node) => record(out, module, &ident_name(&node.ident)),
            Item::Union(node) => record(out, module, &ident_name(&node.ident)),
            Item::Trait(node) => record(out, module, &ident_name(&node.ident)),
            Item::Fn(node) => record(out, module, &ident_name(&node.sig.ident)),
            Item::Impl(node) => {
                // A method is declared in an `impl` block rather than beside
                // its type, so indexing only top-level items left every method
                // unresolvable: a call to one could never be vouched for, and
                // the method read as uncalled however many callers it had.
                //
                // Only an unqualified `impl Type` is indexed. A qualified
                // target (`impl crate::facts::Facts`) names a type this module
                // may not own, and an index hit is trusted outright — so
                // guessing `{module}::Facts` there would vouch for a path that
                // does not exist. The walk resolves those through `use`
                // aliases, which this collector does not have.
                if let Some(name) = impl_target_name(&node.self_ty) {
                    let owner = format!("{module}::{name}");
                    for member in &node.items {
                        if let syn::ImplItem::Fn(method) = member {
                            record(out, &owner, &ident_name(&method.sig.ident));
                        }
                    }
                }
            }
            Item::Mod(node) => {
                if let Some((_, inner)) = &node.content {
                    let nested = format!("{module}::{}", ident_name(&node.ident));
                    collect_declaration_paths(&nested, inner, out);
                }
            }
            _ => {}
        }
    }
}

/// The bare type name of an `impl` block's target, or `None` when the target
/// is anything this collector cannot place from the module path alone.
///
/// Deliberately narrow: one path segment, no leading `::`. Generic arguments
/// on that segment are fine (`impl Walk<'_>` is still `Walk`), because they do
/// not change which type is being implemented.
fn impl_target_name(ty: &Type) -> Option<String> {
    let Type::Path(path) = ty else {
        return None;
    };
    if path.qself.is_some() || path.path.leading_colon.is_some() || path.path.segments.len() != 1 {
        return None;
    }

    path.path
        .segments
        .last()
        .map(|segment| ident_name(&segment.ident))
}

/// Note one canonical path one file declares.
fn record(out: &mut BTreeSet<String>, module: &str, name: &str) {
    out.insert(format!("{module}::{name}"));
}
