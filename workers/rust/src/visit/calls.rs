//! What a body names: calls, references to types and functions, and the
//! receiver types a method call resolves through.
//!
//! Function bodies, field types, module-level initializers and macro
//! arguments are all walked by the one `syn` visitor, `Calls`. A target the
//! source does not vouch for is deferred until this file's declarations are
//! known, or emitted as speculative, never invented.

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::state::Calls;
use crate::facts::reference;
use crate::resolve::ident_name;

impl Calls<'_, '_> {
    /// Resolve and emit a call, deferring the ones that need confirming.
    ///
    /// A callee path resolves through [`Walk::resolve_path`] like every other
    /// path this worker sees. What the flag it returns decides is whether the
    /// resulting edge can be trusted on the spot.
    ///
    /// A multi-segment path the source states outright — rooted at `crate`, at
    /// `::`, at `self`/`super`, or headed by a name the import map expands — is
    /// emitted immediately. Its target is where the author said it is, and an
    /// external target is a genuine external symbol.
    ///
    /// Everything else must be confirmed against this file's declarations
    /// first. That is a path whose head is neither rooted nor imported, and a
    /// single-segment callee, which `syn` can render as a rooted path without
    /// it naming one (see [`Walk::resolve_path`]). Rust makes an unrooted head
    /// legal in exactly two ways: the name was imported, or it is declared in
    /// this same file. Nothing else is callable that way. So such a target is
    /// real only when it lands on a declaration this contribution makes, which
    /// cannot be known yet — the declaration may come later in the file, since
    /// `walk_items` proceeds in source order — and is checked in
    /// `Facts::finish` through [`Facts::conditional_edge`].
    ///
    /// That covers a bare `helper()`, which is indistinguishable at the syntax
    /// level from a call through a local closure or function-pointer binding;
    /// a qualified head such as `String::from()` or `serde_json::to_value()`;
    /// and the two one-segment shapes, `<Widget>::default()` and `self()`.
    /// Trusting a qualified head used to attribute it to the enclosing module,
    /// so `String::from` in `crate::net::http` became
    /// `crate::net::http::String::from`: a `crate::`-rooted name no crate
    /// member ever declares, which the reconciler then materialised as a
    /// fabricated external node.
    ///
    /// The check confirms; it does not prove the negative. It drops every
    /// target this contribution cannot vouch for, and one legitimate shape
    /// falls with them: a call qualified by a *child* module, such as
    /// `sign::any_supported_type(&key_der)` under a `pub mod sign;` declared in
    /// the same file. A bare `mod foo;` emits only a containment edge and no
    /// node (see [`Walk::walk_mod`]), so the child module's node belongs to the
    /// contribution of the file that defines it, and the per-contribution
    /// `declared` set here cannot match it. That is a known false negative: a
    /// missing edge, never a wrong one.
    ///
    /// A callee named in UpperCamelCase builds a value rather than calling
    /// anything: `Wrapper(1)` and `Self(x)` construct a tuple struct, which
    /// they reference, and `Error::Io(e)` an enum variant, whose enum the
    /// owner reference in [`Calls::visit_expr_call`] already names. Neither
    /// is a function or a method, so neither becomes a `calls` edge.
    ///
    /// A trusted target inside this project (rooted at `crate`, this file's
    /// crate root or a workspace member) carries a kind guessed from its
    /// spelling ([`call_kind`]) that no declaration here confirmed, so its
    /// edge is speculative: kept when the graph declares it, dropped rather
    /// than invented as an external symbol when it does not. A target in
    /// another crate stays an ordinary edge, its external node the
    /// dependency's symbol.
    ///
    /// [`Walk::resolve_path`]: super::state::Walk::resolve_path
    /// [`Walk::walk_mod`]: super::state::Walk::walk_mod
    /// [`Facts::conditional_edge`]: crate::facts::Facts::conditional_edge
    fn visit_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if builds_value(path) {
            if !names_variant(path) {
                self.type_reference(path, span);
            }
            return;
        }
        let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        let endpoint = reference(call_kind(&target), &target);
        if unconfirmed {
            self.walk
                .facts
                .conditional_edge(&self.enclosing, &endpoint, span);
            return;
        }
        let head = target.split("::").next().unwrap_or(&target);
        if head == self.walk.crate_root() || self.walk.layout.is_project_root(head) {
            self.walk
                .facts
                .speculative_edge("calls", &self.enclosing, &endpoint, span);
        } else {
            self.walk
                .facts
                .edge("calls", &self.enclosing, &endpoint, "probable", span);
        }
    }
}

impl Calls<'_, '_> {
    /// Speculative `references` edges to the type a path names, as a struct
    /// or enum and as a trait: which one it is, and whether it is declared
    /// here at all rather than being `Vec` or `String`, only the graph knows.
    fn type_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        let Some((target, _)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        for kind in ["class", "interface"] {
            let endpoint = reference(kind, &target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The type a multi-segment value or pattern path sits under:
    /// `HookState::Absent` names `HookState`, `Self::helper` the impl's type.
    fn owner_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if path.segments.len() < 2 {
            return;
        }
        let mut owner = path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        self.type_reference(&owner, span);
    }

    /// A function named as a value (`map(Self::helper)`, `.or_else(fallback)`),
    /// which no call expression marks. A bare name is usually a local binding,
    /// so an unconfirmed one counts only when this file declares a function
    /// by that name, the same deferral an unqualified call gets.
    fn value_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        if path.segments.len() < 2 {
            let endpoint = reference("function", &target);
            if unconfirmed {
                self.walk.facts.conditional_any_edge(
                    &self.enclosing,
                    &endpoint,
                    span,
                    "references",
                    "probable",
                );
            } else if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
            return;
        }
        for kind in ["function", "method"] {
            let endpoint = reference(kind, &target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The functions a `#[serde(...)]` attribute names by string:
    /// `default = "f"`, `serialize_with`, `deserialize_with`,
    /// `skip_serializing_if`, `getter`, and a `with = "module"`'s
    /// `serialize`/`deserialize`. Serde calls each; nothing else names them.
    pub(super) fn serde_references(&mut self, attrs: &[syn::Attribute]) {
        for attr in attrs {
            if !attr.path().is_ident("serde") {
                continue;
            }
            let mut named: Vec<(syn::Path, bool)> = Vec::new();
            let _ = attr.parse_nested_meta(|meta| {
                let key = meta.path.get_ident().map(ident_name);
                if meta.input.peek(syn::Token![=]) {
                    let value: syn::Expr = meta.value()?.parse()?;
                    if let (
                        Some(key),
                        syn::Expr::Lit(syn::ExprLit {
                            lit: syn::Lit::Str(literal),
                            ..
                        }),
                    ) = (key, value)
                    {
                        let with = key == "with";
                        if with
                            || matches!(
                                key.as_str(),
                                "default"
                                    | "serialize_with"
                                    | "deserialize_with"
                                    | "skip_serializing_if"
                                    | "getter"
                            )
                        {
                            if let Ok(path) = literal.parse::<syn::Path>() {
                                named.push((path, with));
                            }
                        }
                    }
                } else if meta.input.peek(syn::token::Paren) {
                    meta.parse_nested_meta(|nested| {
                        if nested.input.peek(syn::Token![=]) {
                            let _: syn::Expr = nested.value()?.parse()?;
                        }
                        Ok(())
                    })?;
                }
                Ok(())
            });
            for (path, with) in named {
                let span = attr.span();
                if with {
                    for function in ["serialize", "deserialize"] {
                        let mut member = path.clone();
                        member.segments.push(syn::PathSegment::from(syn::Ident::new(
                            function,
                            proc_macro2::Span::call_site(),
                        )));
                        self.function_reference(&member, span);
                    }
                } else {
                    self.function_reference(&path, span);
                }
            }
        }
    }

    /// The type a method call's receiver holds, when the source states it: a
    /// local of known type, or an enum variant or associated constant
    /// (`Theme::Regular`), whose type is the path it sits under.
    fn receiver_owner(&self, expression: &syn::Expr) -> Option<String> {
        if let Some(name) = receiver_name(expression) {
            return self.receivers.get(&name).cloned();
        }
        let mut current = expression;
        while let syn::Expr::Paren(inner) = current {
            current = &inner.expr;
        }
        // `self.walk.facts`: the base's type, then the field's declared type.
        if let syn::Expr::Field(field) = current {
            let syn::Member::Named(name) = &field.member else {
                return None;
            };
            let owner = self.receiver_owner(&field.base)?;
            return self.walk.field_type(&owner, &ident_name(name));
        }
        let syn::Expr::Path(path) = current else {
            return None;
        };
        if path.qself.is_some() || path.path.segments.len() < 2 {
            return None;
        }
        let mut owner = path.path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        self.walk
            .resolve_path(&self.container, &owner)
            .map(|(target, _)| target)
    }

    /// The bindings a struct pattern makes (`let Index { store, .. } = x;`),
    /// each with the type of the field it takes, when the struct's
    /// declaration states one. Every other binding of the pattern shadows
    /// an outer one of unknown type.
    fn destructured_receivers(&mut self, pattern: &syn::PatStruct) {
        let owner = if pattern.qself.is_none() {
            self.walk
                .resolve_path(&self.container, &pattern.path)
                .map(|(target, _)| target)
        } else {
            None
        };
        for field in &pattern.fields {
            let syn::Pat::Ident(binding) = field.pat.as_ref() else {
                continue;
            };
            let name = ident_name(&binding.ident);
            let stated = match (&owner, &field.member) {
                (Some(owner), syn::Member::Named(member)) => {
                    self.walk.field_type(owner, &ident_name(member))
                }
                _ => None,
            };
            match stated {
                Some(target) => {
                    self.receivers.insert(name, target);
                }
                None => {
                    self.receivers.remove(&name);
                }
            }
        }
    }

    /// The method whose result an expression is, when its owner is known: a
    /// method call on a receiver of stated type, or an associated call
    /// `Type::f(..)`. `Type::method` as a canonical path, or None.
    fn returning_call(&self, expression: &syn::Expr) -> Option<String> {
        match expression {
            syn::Expr::Paren(inner) => self.returning_call(&inner.expr),
            syn::Expr::Reference(reference) => self.returning_call(&reference.expr),
            syn::Expr::MethodCall(call) => {
                let owner = self.receiver_owner(&call.receiver)?;
                Some(format!("{owner}::{}", ident_name(&call.method)))
            }
            syn::Expr::Call(call) => match call.func.as_ref() {
                syn::Expr::Path(path) if path.qself.is_none() && path.path.segments.len() >= 2 => {
                    self.walk
                        .resolve_path(&self.container, &path.path)
                        .map(|(target, _)| target)
                }
                _ => None,
            },
            _ => None,
        }
    }

    /// A speculative `references` edge to the function a path names.
    fn function_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if let Some((target, _)) = self.walk.resolve_path(&self.container, path) {
            let endpoint = reference("function", &target);
            self.walk
                .facts
                .speculative_edge("references", &self.enclosing, &endpoint, span);
        }
    }
}

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            self.visit_call(&path.path, node.span());
            // `Action::Deny(x)` builds a variant: the enum is used. The callee
            // itself is not walked as a value path, since it is the call.
            self.owner_reference(&path.path, node.span());
            for argument in &node.args {
                self.visit_expr(argument);
            }
            return;
        }
        syn::visit::visit_expr_call(self, node);
    }

    fn visit_expr_path(&mut self, node: &syn::ExprPath) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.value_reference(&node.path, node.span());
        }
        syn::visit::visit_expr_path(self, node);
    }

    fn visit_type_path(&mut self, node: &syn::TypePath) {
        if node.qself.is_none() {
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_type_path(self, node);
    }

    fn visit_trait_bound(&mut self, node: &syn::TraitBound) {
        self.type_reference(&node.path, node.span());
        syn::visit::visit_trait_bound(self, node);
    }

    fn visit_pat_tuple_struct(&mut self, node: &syn::PatTupleStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_tuple_struct(self, node);
    }

    fn visit_pat_struct(&mut self, node: &syn::PatStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_struct(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // A macro body is tokens to syn. Most invocations in ordinary code
        // (`format!`, `println!`, `assert_eq!`, `write!`) take comma-separated
        // expressions, so a body that parses as such is walked like any other
        // arguments; one that does not (`vec![x; n]`, `json!({..})`, a DSL)
        // contributes nothing rather than a guess.
        self.visit_macro_tokens(node.tokens.clone());
    }

    fn visit_expr_struct(&mut self, node: &syn::ExprStruct) {
        if let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, &node.path) {
            let endpoint = reference("class", &target);
            if unconfirmed {
                self.walk.facts.conditional_edge(
                    &self.enclosing,
                    &endpoint,
                    syn::spanned::Spanned::span(node),
                );
            } else {
                self.walk.facts.edge(
                    "calls",
                    &self.enclosing,
                    &endpoint,
                    "probable",
                    syn::spanned::Spanned::span(node),
                );
            }
        }
        syn::visit::visit_expr_struct(self, node);
    }

    fn visit_expr_method_call(&mut self, node: &syn::ExprMethodCall) {
        // A `route(...)` method call is how axum and actix declare routes.
        if node.method == "route" {
            self.call_routes(node);
        }
        // A call on what another call returns: `s.mode().label()` or
        // `State::new().mode()`. The inner call's owner is known here; its
        // return type may be declared in another file, so the member is named
        // through the call and the core resolves it, or drops the edge.
        let returning = self.returning_call(&node.receiver);
        if let Some(callee) = &returning {
            let endpoint = reference(
                "method_of_return",
                &format!("{callee}::{}", ident_name(&node.method)),
            );
            self.walk.facts.edge(
                "calls",
                &self.enclosing,
                &endpoint,
                "probable",
                node.method.span(),
            );
        }
        if let Some(target) = self.receiver_owner(&node.receiver) {
            let endpoint = reference("method", &format!("{target}::{}", ident_name(&node.method)));
            self.walk.facts.speculative_edge(
                "calls",
                &self.enclosing,
                &endpoint,
                node.method.span(),
            );
        } else if returning.is_none() {
            self.walk.facts.untyped_call(ident_name(&node.method));
        }
        syn::visit::visit_expr_method_call(self, node);
    }

    fn visit_local(&mut self, node: &syn::Local) {
        // The initializer is walked first: it runs before the binding exists,
        // so `let p = p.clone()` resolves its receiver through the old `p`.
        syn::visit::visit_local(self, node);
        let (ident, annotation) = match &node.pat {
            syn::Pat::Struct(pattern) => {
                self.destructured_receivers(pattern);
                return;
            }
            syn::Pat::Ident(ident) => (ident_name(&ident.ident), None),
            syn::Pat::Type(typed) => match typed.pat.as_ref() {
                syn::Pat::Ident(ident) => (ident_name(&ident.ident), Some(typed.ty.as_ref())),
                _ => return,
            },
            _ => return,
        };
        let stated = match annotation {
            Some(ty) => self.walk.receiver_type(&self.container, ty),
            None => node
                .init
                .as_ref()
                .and_then(|init| constructed_type(&init.expr))
                .and_then(|path| self.walk.resolve_path(&self.container, &path))
                .map(|(target, _)| target),
        };
        // A rebinding of unknown type shadows the old one, so its type is forgotten.
        match stated {
            Some(target) => {
                self.receivers.insert(ident, target);
            }
            None => {
                self.receivers.remove(&ident);
            }
        }
    }

    fn visit_expr_closure(&mut self, node: &syn::ExprClosure) {
        // A closure parameter shadows any outer binding of the same name, and
        // says nothing about its own type, so it is forgotten while inside.
        let saved = self.receivers.clone();
        for input in &node.inputs {
            if let syn::Pat::Ident(ident) = input {
                self.receivers.remove(&ident_name(&ident.ident));
            }
        }
        syn::visit::visit_expr_closure(self, node);
        self.receivers = saved;
    }
}

impl Calls<'_, '_> {
    /// Walk a macro body as expressions, as far as it is made of them.
    ///
    /// Comma-separated expressions first (`format!`, `assert_eq!`). A body
    /// that is not (`json!({ "k": f(x) })`) is split at its top-level commas,
    /// each piece loses a leading `"key":` or `key:`, and what remains is
    /// parsed on its own; a piece that still does not parse is searched
    /// through its bracketed groups the same way. Nothing is guessed: only
    /// token runs that parse as Rust expressions are walked. The groups still
    /// to search are kept in a worklist, so the walk is one loop rather than
    /// two functions calling each other.
    fn visit_macro_tokens(&mut self, tokens: proc_macro2::TokenStream) {
        use syn::parse::Parser;
        let parser = syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated;
        let mut pending = vec![tokens];
        while let Some(tokens) = pending.pop() {
            if let Ok(arguments) = parser.parse2(tokens.clone()) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                continue;
            }
            for piece in top_level_pieces(tokens) {
                match macro_piece(piece) {
                    Ok(expression) => self.visit_expr(&expression),
                    Err(groups) => pending.extend(groups),
                }
            }
        }
    }
}

/// A macro body split at its top-level commas.
fn top_level_pieces(tokens: proc_macro2::TokenStream) -> Vec<Vec<proc_macro2::TokenTree>> {
    let mut pieces = vec![Vec::new()];
    for token in tokens {
        if matches!(&token, proc_macro2::TokenTree::Punct(punct) if punct.as_char() == ',') {
            pieces.push(Vec::new());
        } else if let Some(piece) = pieces.last_mut() {
            piece.push(token);
        }
    }
    pieces
}

/// One comma-separated piece of a macro body, without a leading `"key":` or
/// `key:`: the expression it parses as, or else the bracketed groups inside
/// it, which may hold expressions of their own. See [`Calls::visit_macro_tokens`].
fn macro_piece(
    mut piece: Vec<proc_macro2::TokenTree>,
) -> Result<syn::Expr, Vec<proc_macro2::TokenStream>> {
    let keyed = matches!(
        piece.as_slice(),
        [
            proc_macro2::TokenTree::Literal(_) | proc_macro2::TokenTree::Ident(_),
            proc_macro2::TokenTree::Punct(colon),
            next,
            ..
        ] if colon.as_char() == ':'
            && colon.spacing() == proc_macro2::Spacing::Alone
            && !matches!(next, proc_macro2::TokenTree::Punct(p) if p.as_char() == ':')
    );
    if keyed {
        piece.drain(..2);
    }
    if piece.is_empty() {
        return Err(Vec::new());
    }
    let stream: proc_macro2::TokenStream = piece.iter().cloned().collect();
    syn::parse2::<syn::Expr>(stream).map_err(|_| {
        piece
            .into_iter()
            .filter_map(|token| match token {
                proc_macro2::TokenTree::Group(group) => Some(group.stream()),
                _ => None,
            })
            .collect()
    })
}

/// Whether an attribute is `#[wasm_bindgen]`, however its path is spelled,
/// or a `cfg_attr`, at any depth, that applies it.
pub(super) fn is_wasm_bindgen(attr: &syn::Attribute) -> bool {
    applies_wasm_bindgen(&attr.meta)
}

/// Whether one attribute's meta is `wasm_bindgen`, or a `cfg_attr` that
/// applies it: `#[cfg_attr(target_arch = "wasm32", wasm_bindgen)]` is how an
/// item is exported only to the build the bindings exist for, and a
/// `cfg_attr` may apply another.
fn applies_wasm_bindgen(meta: &syn::Meta) -> bool {
    if meta
        .path()
        .segments
        .last()
        .is_some_and(|segment| segment.ident == "wasm_bindgen")
    {
        return true;
    }
    let syn::Meta::List(list) = meta else {
        return false;
    };
    list.path.is_ident("cfg_attr")
        && list
            .parse_args_with(
                syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated,
            )
            .is_ok_and(|metas| metas.iter().skip(1).any(applies_wasm_bindgen))
}

/// Whether a function is exported to a caller outside Rust: `#[no_mangle]`
/// (also as `#[unsafe(no_mangle)]`), `#[export_name]`, `#[wasm_bindgen]`, or
/// an `extern` ABI.
pub(super) fn is_foreign_export(node: &syn::ItemFn) -> bool {
    node.sig.abi.is_some()
        || node.attrs.iter().any(|attr| {
            let path = attr.path();
            path.is_ident("no_mangle")
                || path.is_ident("export_name")
                || is_wasm_bindgen(attr)
                || (path.is_ident("unsafe")
                    && attr
                        .meta
                        .require_list()
                        .is_ok_and(|list| list.tokens.to_string().contains("no_mangle")))
        })
}

/// The local a method call's receiver names, seen through `&` and parentheses.
fn receiver_name(expression: &syn::Expr) -> Option<String> {
    match expression {
        syn::Expr::Paren(inner) => receiver_name(&inner.expr),
        syn::Expr::Reference(reference) => receiver_name(&reference.expr),
        syn::Expr::Path(path) if path.qself.is_none() => path.path.get_ident().map(ident_name),
        _ => None,
    }
}

/// The type a `let` initializer constructs, when the source names it: a
/// struct literal `T { .. }`, or an associated call `T::new(..)` (taken to
/// return `Self`, as constructors do), either one behind `?`.
fn constructed_type(expression: &syn::Expr) -> Option<syn::Path> {
    match expression {
        syn::Expr::Try(inner) => constructed_type(&inner.expr),
        syn::Expr::Paren(inner) => constructed_type(&inner.expr),
        syn::Expr::Struct(literal) if literal.qself.is_none() => Some(literal.path.clone()),
        syn::Expr::Call(call) => match call.func.as_ref() {
            syn::Expr::Path(path) if path.qself.is_none() && path.path.segments.len() >= 2 => {
                let mut owner = path.path.clone();
                owner.segments.pop();
                owner.segments.pop_punct();
                Some(owner)
            }
            _ => None,
        },
        _ => None,
    }
}

/// Whether a callee path builds a value: its last segment is UpperCamelCase,
/// as tuple structs and enum variants are and functions are not.
fn builds_value(path: &syn::Path) -> bool {
    path.segments
        .last()
        .is_some_and(|segment| ident_name(&segment.ident).starts_with(char::is_uppercase))
}

/// Whether a constructor path names an enum variant (`Error::Io`,
/// `Self::Empty`): the segment before the last is a type, too.
fn names_variant(path: &syn::Path) -> bool {
    let count = path.segments.len();
    count >= 2 && ident_name(&path.segments[count - 2].ident).starts_with(char::is_uppercase)
}

/// Guess whether a resolved call target names a free function or a
/// method/associated function on a type.
///
/// `path_target` only resolves *where* a path points, not *what kind of
/// item* it names — that would require type information this worker does
/// not have. This falls back to Rust's own naming convention instead: types
/// are UpperCamelCase and modules are snake_case by near-universal
/// convention, so the segment immediately before the final one carries the
/// same signal a human reader would use. `crate::Engine::stop` guesses
/// `method` because `Engine` starts uppercase; `crate::net::http::get`
/// guesses `function` because `http` does not.
///
/// A convention-breaking crate can fool this guess. That cost is accepted
/// deliberately: a wrong guess builds a reference of the wrong kind, which
/// then matches no declared node. For a target the source rooted or imported,
/// the reconciler synthesises an external node for it rather than crashing —
/// call targets are never filtered the way edge sources are (see
/// `Facts::finish`). For a guessed target it costs the edge instead, since
/// `Facts::finish` requires a pending call to match a declaration exactly.
/// Either way a bad guess costs one edge or one spurious node, not a failed
/// scan.
fn call_kind(canonical: &str) -> &'static str {
    let segments: Vec<&str> = canonical.split("::").collect();
    let owner = segments.len().checked_sub(2).and_then(|i| segments.get(i));
    match owner {
        Some(segment) if segment.starts_with(char::is_uppercase) => "method",
        _ => "function",
    }
}

#[cfg(test)]
mod tests {
    use super::call_kind;

    #[test]
    fn a_snake_case_owner_segment_guesses_a_free_function() {
        assert_eq!("function", call_kind("crate::helper"));
        assert_eq!("function", call_kind("crate::net::http::get"));
    }

    #[test]
    fn an_upper_camel_case_owner_segment_guesses_a_method() {
        assert_eq!("method", call_kind("crate::Engine::stop"));
        assert_eq!("method", call_kind("crate::net::Engine::stop"));
    }
}
