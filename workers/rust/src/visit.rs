//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use syn::spanned::Spanned;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::Facts;

/// Walk every item in a parsed file, attributing each to `module`.
pub fn walk(facts: &mut Facts, module: &str, file: &syn::File) {
    walk_items(facts, module, &file.items);
}

/// Walk one item list whose declarations belong to `container`.
fn walk_items(facts: &mut Facts, container: &str, items: &[Item]) {
    for item in items {
        walk_item(facts, container, item);
    }
}

/// Walk one item, emitting its node and recursing into anything it holds.
fn walk_item(facts: &mut Facts, container: &str, item: &Item) {
    match item {
        Item::Struct(node) => {
            declare(
                facts,
                container,
                &node.ident.to_string(),
                "class",
                item.span(),
            );
        }
        Item::Enum(node) => {
            declare(
                facts,
                container,
                &node.ident.to_string(),
                "class",
                item.span(),
            );
        }
        Item::Union(node) => {
            declare(
                facts,
                container,
                &node.ident.to_string(),
                "class",
                item.span(),
            );
        }
        Item::Fn(node) => {
            declare(
                facts,
                container,
                &node.sig.ident.to_string(),
                "function",
                item.span(),
            );
        }
        Item::Trait(node) => {
            let canonical = declare(
                facts,
                container,
                &node.ident.to_string(),
                "interface",
                item.span(),
            );
            for member in &node.items {
                if let TraitItem::Fn(method) = member {
                    declare(
                        facts,
                        &canonical,
                        &method.sig.ident.to_string(),
                        "method",
                        member.span(),
                    );
                }
            }
        }
        Item::Impl(node) => {
            // An `impl` block is not a node: it declares members of a type that
            // is declared elsewhere, possibly in another file. Its methods are
            // attached to the type's canonical path so both halves of a split
            // definition land on the same node.
            let Some(target) = type_path(container, &node.self_ty) else {
                return;
            };
            for member in &node.items {
                if let ImplItem::Fn(method) = member {
                    declare(
                        facts,
                        &target,
                        &method.sig.ident.to_string(),
                        "method",
                        member.span(),
                    );
                }
            }
        }
        Item::Mod(node) => {
            let canonical = declare(
                facts,
                container,
                &node.ident.to_string(),
                "module",
                item.span(),
            );
            if let Some((_, items)) = &node.content {
                walk_items(facts, &canonical, items);
            }
        }
        _ => {}
    }
}

/// Emit one node plus the `contains` edge from its container, returning its path.
///
/// A method separates from its container with `::` like every other Rust path,
/// so the canonical name a reader sees is the one they would type.
fn declare(
    facts: &mut Facts,
    container: &str,
    name: &str,
    kind: &str,
    span: proc_macro2::Span,
) -> String {
    let canonical = format!("{container}::{name}");
    facts.node(&canonical, kind, &canonical, name, span, span);
    facts.edge("contains", container, &canonical, "certain", span);

    canonical
}

/// The canonical path of an `impl` block's self type, when it names one.
///
/// Only a plain path is handled. `impl Trait for &T`, tuples, and slices name no
/// declared type in this file, so attributing methods to them would invent a node.
fn type_path(container: &str, ty: &Type) -> Option<String> {
    let Type::Path(path) = ty else {
        return None;
    };
    let last = path.path.segments.last()?;

    Some(format!("{container}::{}", last.ident))
}
