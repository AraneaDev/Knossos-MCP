//! Where a `mod` item places the module it declares.
//!
//! A `mod name;` names the module of the file Rust loads for it, which a
//! `#[path]` attribute, a binary root or a test target can move away from
//! the path the declaration is written at. Every pass that follows `mod`
//! items asks this one function, so they all agree on one module.

use crate::layout::Layout;
use crate::resolve::ident_name;

/// The module a `mod` item in `relative` (placed in `module`) declares, and
/// for a `mod name;` the file it loads.
///
/// `mod name { .. }` declares `container::name` in place. `mod name;` names
/// the module of the file Rust loads for it (see [`Layout::child_file`]), so
/// a binary root's `mod cli;` is `crate::cli` beside `src/cli.rs`, a test
/// target's `mod common;` is `tests::common`, and `#[path = "x.rs"]` is the
/// module of `x.rs`: the declaration and that file's contribution agree on
/// one node.
pub(super) fn mod_child(
    relative: &str,
    module: &str,
    container: &str,
    node: &syn::ItemMod,
    layout: &Layout,
) -> (String, Option<String>) {
    let name = ident_name(&node.ident);
    let in_place = format!("{container}::{name}");
    if node.content.is_some() {
        return (in_place, None);
    }
    let inline: Vec<String> = container
        .strip_prefix(module)
        .and_then(|rest| rest.strip_prefix("::"))
        .map(|rest| rest.split("::").map(str::to_owned).collect())
        .unwrap_or_default();
    let path = path_attribute(&node.attrs);
    match layout.child_file(relative, &inline, &name, path.as_deref()) {
        Some(file) => (layout.module_of(&file), Some(file)),
        None => (in_place, None),
    }
}

/// The value of a `#[path = "..."]` attribute.
fn path_attribute(attrs: &[syn::Attribute]) -> Option<String> {
    attrs.iter().find_map(|attr| match &attr.meta {
        syn::Meta::NameValue(pair) if pair.path.is_ident("path") => match &pair.value {
            syn::Expr::Lit(syn::ExprLit {
                lit: syn::Lit::Str(text),
                ..
            }) => Some(text.value()),
            _ => None,
        },
        _ => None,
    })
}
