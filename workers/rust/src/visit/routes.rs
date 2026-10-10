//! Framework routes: actix and rocket handler attributes, and the
//! `route(...)` calls axum and actix wire handlers with.
//!
//! Only the request's frameworks are looked for, only static paths become
//! route nodes, and every route waits for the end of the walk, since its
//! handler may be declared further down the file.

use std::collections::BTreeMap;

use syn::spanned::Spanned;

use super::cfg::{attr_method, attr_path};
use super::{Calls, Walk};
use crate::facts::reference;
use crate::resolve::ident_name;

/// One route fact discovered while walking, emitted after the walk.
///
/// Unlike ordinary nodes, a route's handler may be declared later in the file
/// than the routing call that names it (Rust is order-agnostic), so routes are
/// deferred until the whole file's declarations are known.
pub(super) struct RouteCandidate {
    /// Semantic framework name (`axum`, `actix`, `rocket`) for the node's
    /// `framework` attribute.
    framework: &'static str,
    /// HTTP method, uppercase.
    method: String,
    /// The path as written, when it is a static literal.
    path: String,
    /// Handler's resolved canonical path, or the container-relative guess
    /// when nothing resolved it.
    handler: String,
    /// Whether `handler` is that guess — needs the same-file declarations to
    /// confirm it, like a call target.
    handler_unconfirmed: bool,
    /// Where the routing statement is, for evidence.
    span: proc_macro2::Span,
}

impl Walk<'_> {
    /// Record routes declared by actix-style handler attributes.
    ///
    /// `#[get("/path")]`, `#[post(...)]`, … and actix's `#[route("/path",
    /// method = "DELETE")]` are actix's and rocket's primary wiring — both
    /// share the shape, so which framework owns the route is decided by the
    /// scan request's framework list (actix when both are present). The route
    /// fact is deferred to [`Walk::flush_routes`] like every other route; the
    /// handler is this function itself, so it is always declared.
    pub(super) fn attribute_routes(&mut self, canonical: &str, attrs: &[syn::Attribute]) {
        let actix = self.frameworks.iter().any(|f| f == "actix");
        let rocket = self.frameworks.iter().any(|f| f == "rocket");
        if !actix && !rocket {
            return;
        }
        const VERBS: [&str; 7] = ["get", "post", "put", "delete", "patch", "head", "options"];
        for attr in attrs {
            let Some(ident) = attr.path().get_ident() else {
                continue;
            };
            let name = ident_name(ident);
            let (path, method) = if VERBS.contains(&name.as_str()) {
                (attr_path(attr), Some(name.to_uppercase()))
            } else if actix && name == "route" {
                (attr_path(attr), attr_method(attr))
            } else {
                continue;
            };
            let Some(path) = path.filter(|p| !is_dynamic_path(p)) else {
                // A non-literal path, or a literal with dynamic segments
                // (`<id>`, `{id}`, `:id`), would produce a guessed route;
                // diagnose it instead, mirroring the Python worker's
                // PY_DYNAMIC_ROUTE_PATH.
                self.facts.diagnostic(
                    "warning",
                    "RS_DYNAMIC_ROUTE_PATH",
                    "Dynamic Rust route path was skipped.",
                    attr.span().start().line,
                );
                continue;
            };
            let Some(method) = method else {
                continue; // an actix `#[route]` without a method names no verb
            };
            self.routes.push(RouteCandidate {
                framework: if actix { "actix" } else { "rocket" },
                method,
                path,
                handler: canonical.to_owned(),
                handler_unconfirmed: false,
                span: attr.span(),
            });
        }
    }

    /// Emit the routes and roles collected during the walk, once the whole
    /// file's declarations are known.
    ///
    /// A route's handler may be declared later in the file than the routing
    /// call that names it, so both kinds of route — attribute and call — are
    /// flushed here. An attribute route's handler is the function being
    /// walked, hence always declared; a call route's handler may be an
    /// unresolved guess, which only counts if it names a real declaration in
    /// this file.
    pub(super) fn flush_routes(&mut self) {
        for route in &self.routes {
            if route.handler_unconfirmed
                && !self.facts.declares(&reference("function", &route.handler))
            {
                // Unknown handler: the route exists only as a guess. Dropping
                // it is a false negative, never a wrong fact.
                continue;
            }
            let canonical = format!("{} {} => {}", route.method, route.path, route.handler);
            self.facts.node_with_attributes(
                "route",
                &canonical,
                &format!("{} {}", route.method, route.path),
                route.span,
                route.span,
                BTreeMap::from([
                    ("framework".to_owned(), serde_json::json!(route.framework)),
                    (
                        "methods".to_owned(),
                        serde_json::json!(vec![route.method.clone()]),
                    ),
                    ("path".to_owned(), serde_json::json!(route.path)),
                ]),
            );
            let source = reference("route", &canonical);
            let target = reference("function", &route.handler);
            self.facts
                .edge("routes_to", &source, &target, "certain", route.span);
            // The handler role is only meaningful here when the handler is in
            // this file; a cross-file handler keeps its role with its own
            // contribution.
            if self.facts.declares(&target) {
                self.role_marks.push(route.handler.clone());
            }
        }
        for handler in &self.role_marks {
            self.facts.node_attribute(
                handler,
                "rust_framework_roles",
                serde_json::json!(["rust.route_handler"]),
            );
        }
    }
}

impl Calls<'_, '_> {
    /// An axum `Router::route("/path", get(handler))` or actix
    /// `web::resource("/path").route(web::get().to(handler))` call, recorded
    /// as a route fact for [`Walk::flush_routes`].
    ///
    /// Only the two canonical shapes are handled: the routing shorthand
    /// (`get(handler)`, `routing::post(handler)`, `any(handler)`) for axum,
    /// and the `web::<verb>().to(handler)` chain actix builds inside `route`.
    /// Anything else — `nest`, `route_service`, a handler named by a
    /// non-path expression — contributes nothing rather than a guessed
    /// route. A dynamic path (a variable, or a `{}` segment) is diagnosed
    /// rather than guessed, mirroring `PY_DYNAMIC_ROUTE_PATH`.
    fn framework_route(&mut self, node: &syn::ExprMethodCall) {
        let has_axum = self.walk.frameworks.iter().any(|f| f == "axum");
        let has_actix = self.walk.frameworks.iter().any(|f| f == "actix");
        if !has_axum && !has_actix {
            return;
        }
        let args: Vec<&syn::Expr> = node.args.iter().collect();
        let (route_path, method, handler, framework) = if has_axum && args.len() >= 2 {
            let Some(path) = expr_string(args[0]) else {
                self.dynamic_route(node.span());
                return;
            };
            let Some((method, handler)) = routing_shorthand(args[1]) else {
                return;
            };
            (path, method, handler, "axum")
        } else if has_actix && args.len() == 1 {
            let Some((path, method, handler)) = actix_resource(&node.receiver, args[0]) else {
                return;
            };
            (path, method, handler, "actix")
        } else {
            return;
        };
        if is_dynamic_path(&route_path) {
            self.dynamic_route(node.span());
            return;
        }
        let Some(path) = path_of(handler).and_then(|path| {
            self.walk
                .resolve_path(&self.container, path)
                .map(|(target, unconfirmed)| RouteCandidate {
                    framework,
                    method,
                    path: route_path,
                    handler: target,
                    handler_unconfirmed: unconfirmed,
                    span: node.span(),
                })
        }) else {
            return;
        };
        self.walk.routes.push(path);
    }

    /// A dynamic route path diagnostic.
    fn dynamic_route(&mut self, span: proc_macro2::Span) {
        self.walk.facts.diagnostic(
            "warning",
            "RS_DYNAMIC_ROUTE_PATH",
            "Dynamic Rust route path was skipped.",
            span.start().line,
        );
    }
}

/// Whether a route path contains a dynamic segment (`<id>` for rocket,
/// `{id}` for actix, `:id` for axum). These arrive as plain string literals,
/// so the literal check alone cannot tell them apart from a static path —
/// and a route node named `GET /users/<id> => …` would be a false fact.
fn is_dynamic_path(path: &str) -> bool {
    path.bytes()
        .any(|b| matches!(b, b'{' | b'}' | b'<' | b'>' | b':'))
}

/// The string value of a string-literal expression.
fn expr_string(expr: &syn::Expr) -> Option<String> {
    let syn::Expr::Lit(lit) = expr else {
        return None;
    };
    let syn::Lit::Str(text) = &lit.lit else {
        return None;
    };

    Some(text.value())
}

/// A path expression, when the expression is a bare path.
fn path_of(expr: &syn::Expr) -> Option<&syn::Path> {
    let syn::Expr::Path(path) = expr else {
        return None;
    };

    Some(&path.path)
}

/// The `get(handler)`/`routing::post(handler)`/`any(handler)` shorthand axum
/// takes as its handler argument: the HTTP verb and the handler expression.
fn routing_shorthand(expr: &syn::Expr) -> Option<(String, &syn::Expr)> {
    let syn::Expr::Call(call) = expr else {
        return None;
    };
    let path = path_of(&call.func)?;
    let name = ident_name(&path.segments.last()?.ident).to_uppercase();
    if !matches!(
        name.as_str(),
        "GET" | "POST" | "PUT" | "DELETE" | "PATCH" | "OPTIONS" | "HEAD" | "ANY"
    ) {
        return None;
    }

    call.args.first().map(|handler| (name, handler))
}

/// actix's `web::resource("/path").route(web::get().to(handler))` shape:
/// the path from the `resource(...)` call and the verb from the `web::<verb>()`
/// chain.
fn actix_resource<'a>(
    receiver: &'a syn::Expr,
    arg: &'a syn::Expr,
) -> Option<(String, String, &'a syn::Expr)> {
    let path = match receiver {
        syn::Expr::Call(resource) => {
            let resource_path = path_of(&resource.func)?;
            if resource_path.segments.last()?.ident != "resource" {
                return None;
            }
            expr_string(resource.args.first()?)?
        }
        syn::Expr::MethodCall(resource) => {
            if resource.method != "resource" {
                return None;
            }
            expr_string(resource.args.first()?)?
        }
        _ => return None,
    };
    let syn::Expr::MethodCall(to) = arg else {
        return None;
    };
    if to.method != "to" {
        return None;
    }
    let method = match &*to.receiver {
        syn::Expr::Call(verb) => path_of(&verb.func)?
            .segments
            .last()?
            .ident
            .to_string()
            .to_uppercase(),
        syn::Expr::MethodCall(verb) => verb.method.to_string().to_uppercase(),
        _ => return None,
    };
    if !matches!(
        method.as_str(),
        "GET" | "POST" | "PUT" | "DELETE" | "PATCH" | "OPTIONS" | "HEAD"
    ) {
        return None;
    }

    Some((path, method, to.args.first()?))
}

/// The `route(...)` method-call shapes of axum and actix. Dispatched from the
/// visitor so the routing logic stays one method: `framework_route` decides
/// which framework's shape applies and defers the fact.
impl Calls<'_, '_> {
    /// Look for a route declaration in one `route(...)` method call.
    pub(super) fn call_routes(&mut self, node: &syn::ExprMethodCall) {
        self.framework_route(node);
    }
}
