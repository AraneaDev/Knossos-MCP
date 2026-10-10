//! Reading attributes: which items compile only under `test`, which
//! functions are tests, and the literal arguments a `mod` or route
//! attribute carries.
//!
//! A test marker removes code from the dead-code and hub budgets, so these
//! readers evaluate what an attribute says structurally instead of matching
//! its text.

use std::collections::BTreeSet;

use syn::Item;

use super::placement::mod_child;
use crate::layout::{modules_above, Layout};

/// Canonical paths of modules the crate declared `#[cfg(test)] mod name;`
/// without a body, so the module lives in its own file.
///
/// Rust compiles that file only under `cfg(test)`, but the file is scanned as
/// its own contribution and carries no attribute saying so. The declaring
/// file is the only place the fact exists, so it is collected there and
/// handed to the walk of every file in the request.
pub type TestModules = BTreeSet<String>;

/// Whether `module`, or any module above it, was declared `#[cfg(test)]`.
#[must_use]
pub fn is_test_module_path(module: &str, test_modules: &TestModules) -> bool {
    if test_modules.contains(module) {
        return true;
    }
    let mut prefix = module;
    while let Some(cut) = prefix.rfind("::") {
        prefix = &prefix[..cut];
        if test_modules.contains(prefix) {
            return true;
        }
    }

    false
}

/// Record every out-of-line `#[cfg(test)] mod name;` in one file's items, by
/// the module its file is placed in (see [`mod_child`]).
///
/// Bodiless modules only: one with a body is walked in place, where
/// `Walk::walk_mod` opens the scope directly. A declaration counts only when
/// `relative` is a file every file of that module already reads: one of the
/// paths a module above it could have (`src/net.rs` for `crate::net::tests`,
/// a binary root's `src/main.rs` for `crate::checks`). A `#[path]` that sends a test
/// module elsewhere (`src/net.rs` loading `src/net_tests.rs`) is not marked,
/// since adding or removing the attribute could not reach that file.
pub fn collect_test_modules(
    relative: &str,
    module: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut TestModules,
) {
    collect_test_modules_in(relative, module, module, items, layout, out);
}

/// [`collect_test_modules`] for the items of `container`, `module` itself or
/// an inline module in it.
fn collect_test_modules_in(
    relative: &str,
    module: &str,
    container: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut TestModules,
) {
    for item in items {
        let Item::Mod(node) = item else {
            continue;
        };
        let (canonical, _) = mod_child(relative, module, container, node, layout);
        match &node.content {
            Some((_, inner)) => {
                collect_test_modules_in(relative, module, &canonical, inner, layout, out);
            }
            None if is_cfg_test(&node.attrs) => {
                let read_by_it = modules_above(&canonical).iter().any(|parent| {
                    layout
                        .module_files(parent)
                        .iter()
                        .any(|file| file == relative)
                });
                if read_by_it {
                    out.insert(canonical);
                }
            }
            None => {}
        }
    }
}

/// The outer attributes of an item, or none for an item kind the walk never
/// declares anything for.
pub(super) fn item_attrs(item: &Item) -> &[syn::Attribute] {
    match item {
        Item::Const(node) => &node.attrs,
        Item::Enum(node) => &node.attrs,
        Item::Fn(node) => &node.attrs,
        Item::Impl(node) => &node.attrs,
        Item::Mod(node) => &node.attrs,
        Item::Static(node) => &node.attrs,
        Item::Struct(node) => &node.attrs,
        Item::Trait(node) => &node.attrs,
        Item::Union(node) => &node.attrs,
        Item::Use(node) => &node.attrs,
        _ => &[],
    }
}

/// Whether an attribute list compiles its item only under `test`.
///
/// The `cfg(..)` predicate is evaluated structurally (see [`requires_test`]):
/// `cfg(test)` and `cfg(all(test, feature = "x"))` hold only in a test build,
/// while `cfg(not(test))` is production code and `cfg(any(test, feature =
/// "x"))` also compiles outside tests. A false positive here silently removes
/// production code from the dead-code and hub budgets; a false negative puts
/// test code back in them.
pub(super) fn is_cfg_test(attrs: &[syn::Attribute]) -> bool {
    attrs.iter().any(|attr| {
        attr.path().is_ident("cfg")
            && attr
                .meta
                .require_list()
                .ok()
                .and_then(|list| list.parse_args::<syn::Meta>().ok())
                .is_some_and(|predicate| requires_test(&predicate))
    })
}

/// Whether a `cfg` predicate can hold only when `test` is set (see
/// [`test_gate`]).
fn requires_test(predicate: &syn::Meta) -> bool {
    test_gate(predicate).0
}

/// What a `cfg` predicate says about tests, as two answers: whether it can
/// hold only when `test` is set, and whether it holds in every build without
/// `test`.
///
/// `test` holds only under `test`. `all(..)` holds only under `test` when
/// any of its parts does, and outside tests when every part does; `any(..)`
/// holds only under `test` when every one of its (at least one) parts does,
/// and outside tests when any part does. `not(p)` swaps the two answers:
/// `not(test)` holds in every build without `test`, `not(not(test))` only
/// under it. Anything else, `unix` or `feature = "test"`, says neither.
fn test_gate(predicate: &syn::Meta) -> (bool, bool) {
    let syn::Meta::List(list) = predicate else {
        return (
            matches!(predicate, syn::Meta::Path(path) if path.is_ident("test")),
            false,
        );
    };
    let parts: Vec<(bool, bool)> = cfg_parts(list).iter().map(test_gate).collect();
    if list.path.is_ident("all") {
        (
            parts.iter().any(|part| part.0),
            parts.iter().all(|part| part.1),
        )
    } else if list.path.is_ident("any") {
        (
            !parts.is_empty() && parts.iter().all(|part| part.0),
            parts.iter().any(|part| part.1),
        )
    } else if let ([(requires, outside)], true) = (parts.as_slice(), list.path.is_ident("not")) {
        (*outside, *requires)
    } else {
        (false, false)
    }
}

/// The comma-separated predicates inside `all(..)`, `any(..)` or `not(..)`,
/// or none when the list does not parse as predicates.
fn cfg_parts(list: &syn::MetaList) -> Vec<syn::Meta> {
    list.parse_args_with(syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated)
        .map(|parts| parts.into_iter().collect())
        .unwrap_or_default()
}

/// Whether an attribute list marks a test function.
///
/// Covers the bare `#[test]` and the framework spellings that wrap it
/// (`#[tokio::test]`, `#[async_std::test]`), which all end in a `test` segment.
pub(super) fn is_test_attribute(attrs: &[syn::Attribute]) -> bool {
    attrs.iter().any(|attr| {
        attr.path()
            .segments
            .last()
            .is_some_and(|segment| segment.ident == "test")
    })
}

/// The first string-literal argument of an attribute, `"/path"` of
/// `#[get("/path", rank = 1)]`, or `None` when the list does not open with
/// one (a variable, a macro — anything the worker cannot prove static).
pub(super) fn attr_path(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Lit(lit) = arg {
            if let syn::Lit::Str(text) = lit.lit {
                return Some(text.value());
            }
        } else {
            break;
        }
    }

    None
}

/// The `method` value of actix's `#[route("/path", method = "PUT")]`.
pub(super) fn attr_method(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Assign(assign) = arg {
            if let syn::Expr::Path(left) = &*assign.left {
                if left.path.is_ident("method") {
                    if let syn::Expr::Lit(lit) = &*assign.right {
                        if let syn::Lit::Str(string) = &lit.lit {
                            return Some(string.value().to_uppercase());
                        }
                    }
                }
            }
        }
    }

    None
}

/// The parsed argument list of a list attribute, or `None` for an empty,
/// path-only, or unparsable attribute (`#[get]`, `#[inline]`).
fn attr_args(
    attr: &syn::Attribute,
) -> Option<syn::punctuated::Punctuated<syn::Expr, syn::Token![,]>> {
    let syn::Meta::List(list) = &attr.meta else {
        return None;
    };
    list.parse_args_with(syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated)
        .ok()
}

#[cfg(test)]
mod tests {
    /// `not(test)` and `any(test, ..)` hold in a production build too, so
    /// only a predicate no production build meets marks test code.
    #[test]
    fn a_cfg_predicate_requires_test_only_where_every_build_that_meets_it_is_a_test() {
        let requires = |predicate: &str| {
            super::requires_test(&syn::parse_str::<syn::Meta>(predicate).expect("parses"))
        };
        for test_only in [
            "test",
            "all(test, feature = \"x\")",
            "any(test, all(test, unix))",
            "not(not(test))",
            "not(any(not(test), unix))",
            "all(any(test, all(test, unix)), not(any(not(test), windows)))",
            "not(all(not(test), all()))",
        ] {
            assert!(requires(test_only), "{test_only}");
        }
        for production in [
            "not(test)",
            "any(test, feature = \"x\")",
            "all(not(test), unix)",
            "not(all(not(test), unix))",
            "any(not(not(test)), unix)",
            "not(test, unix)",
            "any()",
            "feature = \"test\"",
            "unix",
        ] {
            assert!(!requires(production), "{production}");
        }
    }
}
