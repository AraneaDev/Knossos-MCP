//! Scanning tests driving the worker against real files on disk.

use std::io::Cursor;

use knossos_rust_worker::server::run;
use serde_json::Value;

/// Write `files` (raw bytes) into a fresh temporary root and run a scan
/// request with `params` merged over the base (`root`, `files`), returning
/// contributions. The byte-oriented base every other fixture helper here
/// builds on, so a fixture that needs non-UTF-8 content does not have to
/// duplicate the request/response plumbing.
pub fn scan_fixture_with_bytes(name: &str, files: &[(&str, &[u8])], params: &Value) -> Vec<Value> {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, bytes) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, bytes).unwrap();
    }
    let base = serde_json::json!({
        "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
        "files": files.iter().map(|(relative, _)| *relative).collect::<Vec<_>>(),
    });
    let mut merged = base.as_object().unwrap().clone();
    for (key, value) in params.as_object().unwrap() {
        merged.insert(key.clone(), value.clone());
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": merged,
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let _ = std::fs::remove_dir_all(&root);

    replies
        .into_iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect()
}

/// Like [`scan_fixture_with_bytes`], but returns the request's final `result`
/// object (where `input_hashes` lives) instead of its contributions.
pub fn scan_result_with_bytes(name: &str, files: &[(&str, &[u8])], params: &Value) -> Value {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, bytes) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, bytes).unwrap();
    }
    let base = serde_json::json!({
        "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
        "files": files.iter().map(|(relative, _)| *relative).collect::<Vec<_>>(),
    });
    let mut merged = base.as_object().unwrap().clone();
    for (key, value) in params.as_object().unwrap() {
        merged.insert(key.clone(), value.clone());
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": merged,
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let _ = std::fs::remove_dir_all(&root);

    replies
        .into_iter()
        .find(|reply| reply.get("result").is_some())
        .map(|reply| reply["result"].clone())
        .expect("scan request produced no result")
}

/// Write `files` into a fresh temporary root and run a scan request with
/// `params` merged over the base (`root`, `files`), returning contributions.
pub fn scan_fixture_with(name: &str, files: &[(&str, &str)], params: &Value) -> Vec<Value> {
    let byte_files: Vec<(&str, &[u8])> = files
        .iter()
        .map(|(relative, source)| (*relative, source.as_bytes()))
        .collect();
    scan_fixture_with_bytes(name, &byte_files, params)
}

/// Write `files` into a fresh temporary root and scan them, returning contributions.
pub fn scan_fixture(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    scan_fixture_with(name, files, &serde_json::json!({}))
}

#[test]
fn a_crate_root_main_marks_the_module_executable() {
    let contributions = scan_fixture("executable-main", &[("src/main.rs", "fn main() {}\n")]);
    let module = contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .find(|node| node["kind"] == "module")
        .unwrap();
    assert_eq!(serde_json::json!(true), module["attributes"]["executable"]);
}

#[test]
fn a_library_file_without_main_is_not_executable() {
    let contributions = scan_fixture("not-executable", &[("src/lib.rs", "pub fn go() {}\n")]);
    let module = contributions[0]["nodes"].as_array().unwrap()[0].clone();
    assert_eq!(serde_json::json!({}), module["attributes"]);
}

#[test]
fn axum_routes_become_route_nodes_and_edges() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/health", get(handler))
}
"#;
    let contributions = scan_fixture_with(
        "axum-routes",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let route = nodes
        .iter()
        .find(|node| node["kind"] == "route")
        .expect("axum route node missing");
    assert_eq!("GET /health => crate::handler", route["canonical_name"]);
    assert_eq!("axum", route["attributes"]["framework"]);
    assert_eq!("/health", route["attributes"]["path"]);

    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "routes_to"
            && edge["source"] == "rust:route:GET /health => crate::handler"
            && edge["target"] == "rust:function:crate::handler"
    }));
}

#[test]
fn axum_routes_are_diagnosed_when_framework_not_requested() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/health", get(handler))
}
"#;
    let contributions = scan_fixture("axum-unrequested", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(
        !nodes.iter().any(|node| node["kind"] == "route"),
        "no route without the axum framework hint"
    );
}

#[test]
fn actix_attribute_routes_are_recorded() {
    let source = r#"
#[get("/ping")]
#[route("/pong", method = "PUT")]
fn ping() {}
"#;
    let contributions = scan_fixture_with(
        "actix-attrs",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["actix"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let routes: Vec<&Value> = nodes
        .iter()
        .filter(|node| node["kind"] == "route")
        .collect();
    assert_eq!(2, routes.len());
    assert!(routes
        .iter()
        .any(|route| route["canonical_name"] == "GET /ping => crate::ping"));
    assert!(routes
        .iter()
        .any(|route| route["canonical_name"] == "PUT /pong => crate::ping"));
    let handler = nodes
        .iter()
        .find(|node| node["canonical_name"] == "crate::ping")
        .unwrap();
    assert_eq!(
        serde_json::json!(["rust.route_handler"]),
        handler["attributes"]["rust_framework_roles"]
    );
}

#[test]
fn actix_call_routes_are_recorded() {
    let source = r#"
fn handler() {}

fn app() {
    web::resource("/health").route(web::get().to(handler));
}
"#;
    let contributions = scan_fixture_with(
        "actix-call-route",
        &[("src/routes.rs", source)],
        &serde_json::json!({ "frameworks": ["actix"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes.iter().any(|node| {
        node["kind"] == "route" && node["canonical_name"] == "GET /health => crate::routes::handler"
    }));
    assert!(contributions[0]["edges"]
        .as_array()
        .unwrap()
        .iter()
        .any(|edge| {
            edge["kind"] == "routes_to" && edge["target"] == "rust:function:crate::routes::handler"
        }));
}

#[test]
fn axum_dynamic_route_path_is_diagnosed_not_guessed() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/users/:id", get(handler))
}
"#;
    let contributions = scan_fixture_with(
        "axum-dynamic-route",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    assert!(!contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "route"));
    assert!(contributions[0]["diagnostics"]
        .as_array()
        .unwrap()
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_DYNAMIC_ROUTE_PATH"));
}

#[test]
fn rocket_attribute_routes_are_recorded() {
    let source = r#"
#[get("/world")]
fn world() {}
"#;
    let contributions = scan_fixture_with(
        "rocket-attrs",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["rocket"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes
        .iter()
        .any(|node| node["kind"] == "route"
            && node["canonical_name"] == "GET /world => crate::world"));
}

#[test]
fn a_dynamic_route_path_is_diagnosed_not_guessed() {
    let source = r#"
#[get("/users/<id>")]
fn user() {}
"#;
    let contributions = scan_fixture_with(
        "dynamic-route",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["rocket"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(!nodes.iter().any(|node| node["kind"] == "route"));
    let diagnostics = contributions[0]["diagnostics"].as_array().unwrap();
    assert!(diagnostics
        .iter()
        .any(|diag| diag["code"] == "RS_DYNAMIC_ROUTE_PATH"));
}

#[test]
fn a_crate_manifest_adds_a_package_node_to_the_crate_root() {
    let contributions = scan_fixture_with(
        "crate-package",
        &[
            ("Cargo.toml", "[package]\nname = \"demo\"\n"),
            ("src/main.rs", "fn main() {}\n"),
        ],
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let main_contribution = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/main.rs")
        .unwrap();
    let nodes = main_contribution["nodes"].as_array().unwrap();
    let package = nodes
        .iter()
        .find(|node| node["kind"] == "package")
        .expect("crate package node missing");
    assert_eq!("demo", package["canonical_name"]);
    let edges = main_contribution["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:package:demo"
            && edge["target"] == "rust:module:crate"
    }));
}

#[test]
fn a_package_with_library_and_binary_roots_keeps_the_roots_distinct() {
    let contributions = scan_fixture_with(
        "crate-library-and-binary",
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"demo\"\n\n[lib]\nname = \"demo\"\n",
            ),
            ("src/lib.rs", "pub struct Library;\n"),
            ("src/main.rs", "fn main() {}\n"),
        ],
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let library = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/lib.rs")
        .unwrap();
    let binary = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/main.rs")
        .unwrap();
    assert!(library["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "module" && node["canonical_name"] == "crate" }));
    assert!(binary["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "module" && node["canonical_name"] == "crate::main" }));
    assert!(binary["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "package" && node["canonical_name"] == "demo" }));
    assert!(binary["edges"].as_array().unwrap().iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:package:demo"
            && edge["target"] == "rust:module:crate::main"
    }));
}

#[test]
fn an_impl_for_a_type_in_another_file_keeps_its_edges() {
    // The Phase 3 cross-file index: the declaring file is in the same
    // request, so the `implements` and `contains` edges sourced from
    // `crate::model::Engine` survive instead of orphaning the methods. The
    // class node itself lives in model.rs's contribution.
    let contributions = scan_fixture(
        "cross-file-impl",
        &[
            ("src/lib.rs", "pub mod model;\n"),
            ("src/model.rs", "pub struct Engine;\n"),
            (
                "src/engine.rs",
                "use crate::model::Engine;\n\nimpl Engine {\n    pub fn start(&self) {}\n}\n",
            ),
        ],
    );
    let engine = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/engine.rs")
        .unwrap();
    let edges = engine["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::model::Engine"
            && edge["target"] == "rust:method:crate::model::Engine::start"
    }));
}

#[test]
fn a_child_module_call_resolves_through_the_scan_wide_index() {
    // `sign::any_supported_type` was a documented false negative: the child
    // module's declaration lives in another file, which the per-file walk
    // could not see. The scan-wide index resolves it.
    let contributions = scan_fixture(
        "child-module-call",
        &[
            (
                "src/lib.rs",
                "pub mod sign;\n\npub fn go() {\n    sign::any_supported_type();\n}\n",
            ),
            ("src/sign.rs", "pub fn any_supported_type() {}\n"),
        ],
    );
    let lib = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/lib.rs")
        .unwrap();
    let edges = lib["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::go"
            && edge["target"] == "rust:function:crate::sign::any_supported_type"
    }));
}

#[test]
fn a_file_becomes_a_module_node_at_its_real_first_line() {
    let contributions = scan_fixture("module-node", &[("src/greeting.rs", "\n\nfn hello() {}\n")]);

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(
        "knossos.rust:file:src/greeting.rs",
        contribution["owner_key"]
    );
    let module = &contribution["nodes"][0];
    assert_eq!("module", module["kind"]);
    assert_eq!("crate::greeting", module["canonical_name"]);
    assert_eq!("greeting", module["display_name"]);
    assert_eq!("ast", module["origin"]);
    assert_eq!("certain", module["confidence"]);
    assert_eq!("src/greeting.rs", module["evidence"]["path"]);
    assert_eq!(1, module["evidence"]["start_line"]);
    assert_eq!(serde_json::json!({}), module["attributes"]);
}

#[test]
fn spans_report_real_line_numbers_not_zero() {
    // Regression guard for the proc-macro2 `span-locations` feature: the pinned
    // proc-macro2 gates `Span::start()`/`end()` behind `#[cfg(span_locations)]`,
    // so dropping the feature is a compile error, not a silent line-0 span.
    // This test guards the feature staying enabled and evidence staying real.
    let source = "// one\n// two\nfn third_line() {}\n";
    let contributions = scan_fixture("span-lines", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let function = nodes
        .iter()
        .find(|node| node["display_name"] == "third_line")
        .expect("the function node is missing");

    assert_eq!(3, function["evidence"]["start_line"]);
}

#[test]
fn a_symlink_escaping_the_root_is_unscannable() {
    let root = std::env::temp_dir().join("knossos-rust-symlink-escape");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();

    let outside = std::env::temp_dir().join("knossos-rust-symlink-escape-target.rs");
    std::fs::write(&outside, "fn outside() {}\n").unwrap();

    let link = root.join("src/escape.rs");
    std::os::unix::fs::symlink(&outside, &link).unwrap();

    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/escape.rs"],
        },
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let _ = std::fs::remove_dir_all(&root);
    let _ = std::fs::remove_file(&outside);

    let contributions: Vec<Value> = replies
        .into_iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect();

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(0, contribution["nodes"].as_array().unwrap().len());
    let diagnostics = contribution["diagnostics"].as_array().unwrap();
    assert!(diagnostics
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_UNSCANNABLE_FILE"));
}

#[test]
fn declarations_become_nodes_with_containment_edges() {
    let source = r#"
pub struct Engine {
    pub name: String,
}

pub enum Mode {
    Fast,
}

pub trait Runner {
    fn run(&self);
}

impl Engine {
    pub fn start(&self) {}
}

pub fn boot() {}

pub mod inner {
    pub fn nested() {}
}
"#;
    let contributions = scan_fixture("declarations", &[("src/engine.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let kind_of = |name: &str| {
        nodes
            .iter()
            .find(|node| node["canonical_name"] == name)
            .unwrap_or_else(|| panic!("missing node {name}"))["kind"]
            .as_str()
            .unwrap()
            .to_owned()
    };

    assert_eq!("class", kind_of("crate::engine::Engine"));
    assert_eq!("class", kind_of("crate::engine::Mode"));
    assert_eq!("interface", kind_of("crate::engine::Runner"));
    assert_eq!("method", kind_of("crate::engine::Engine::start"));
    assert_eq!("method", kind_of("crate::engine::Runner::run"));
    assert_eq!("function", kind_of("crate::engine::boot"));
    assert_eq!("module", kind_of("crate::engine::inner"));
    assert_eq!("function", kind_of("crate::engine::inner::nested"));

    let edges = contributions[0]["edges"].as_array().unwrap();
    let contains = |source: &str, target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "contains" && edge["source"] == source && edge["target"] == target
        })
    };
    assert!(contains(
        "rust:module:crate::engine",
        "rust:class:crate::engine::Engine"
    ));
    assert!(contains(
        "rust:module:crate::engine",
        "rust:module:crate::engine::inner"
    ));
    assert!(contains(
        "rust:module:crate::engine::inner",
        "rust:function:crate::engine::inner::nested"
    ));
    assert!(contains(
        "rust:class:crate::engine::Engine",
        "rust:method:crate::engine::Engine::start"
    ));
    assert!(contains(
        "rust:interface:crate::engine::Runner",
        "rust:method:crate::engine::Runner::run"
    ));
}

#[test]
fn a_bare_mod_declaration_does_not_duplicate_the_module_node() {
    // `pub mod foo;` in lib.rs and the module actually declared in `foo.rs` both
    // canonicalise to `crate::foo`. Declaring a node for both would give the
    // reconciler two nodes with the same stable id but different evidence
    // paths, which is not exempt from `reconciler.duplicate_symbol_evidence`
    // the way `package`/`external_*` kinds are — so lib.rs must contribute only
    // the `contains` edge, not a second node.
    let contributions = scan_fixture(
        "mod-declaration",
        &[
            ("src/lib.rs", "pub mod foo;\n"),
            ("src/foo.rs", "pub fn hello() {}\n"),
        ],
    );

    let lib = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/lib.rs")
        .expect("missing src/lib.rs contribution");
    let lib_nodes = lib["nodes"].as_array().unwrap();
    assert!(
        !lib_nodes
            .iter()
            .any(|node| node["canonical_name"] == "crate::foo"),
        "lib.rs must not declare a second node for a bare `mod foo;`"
    );
    let lib_edges = lib["edges"].as_array().unwrap();
    assert!(lib_edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:module:crate"
            && edge["target"] == "rust:module:crate::foo"
    }));

    let foo = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/foo.rs")
        .expect("missing src/foo.rs contribution");
    let foo_nodes = foo["nodes"].as_array().unwrap();
    assert!(
        foo_nodes
            .iter()
            .any(|node| node["canonical_name"] == "crate::foo" && node["kind"] == "module"),
        "foo.rs must declare the module node crate::foo itself"
    );
}

#[test]
fn an_impl_for_an_undeclared_type_emits_methods_but_no_contains_edge() {
    // `Elsewhere` is never declared in this file (it might live in another
    // module entirely), so the type's canonical path was never emitted as a
    // node here. `GraphReconciler::resolveEdges` throws when an edge's source
    // is not in the node map, so `Facts::finish` must drop this edge rather
    // than let it reach the core. The method node itself is still legitimate
    // evidence and stays.
    let source = r#"
impl Elsewhere {
    pub fn go(&self) {}
}
"#;
    let contributions = scan_fixture("impl-undeclared-type", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes.iter().any(|node| {
        node["canonical_name"] == "crate::Elsewhere::go" && node["kind"] == "method"
    }));

    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| {
            edge["kind"] == "contains" && edge["target"] == "rust:method:crate::Elsewhere::go"
        }),
        "no contains edge should survive with an undeclared source"
    );
}

#[test]
fn an_impl_for_a_declared_type_still_emits_its_contains_edge() {
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {}
}
"#;
    let contributions = scan_fixture("impl-declared-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::Engine"
            && edge["target"] == "rust:method:crate::Engine::start"
    }));
}

#[test]
fn use_declarations_become_import_edges_pointing_at_the_containing_module() {
    // A `use` names a struct, trait, or function, whose node kind is `class`,
    // `interface`, or `function` — never `module`. Targeting the symbol's own
    // path as a module therefore resolved against nothing, and made the
    // reconciler synthesise an external module twinning the very symbol the
    // project had already declared. The edge points at the module that holds
    // the symbol instead, which is a node the graph really has, and the symbol
    // keeps going into the alias map. `workers/python/bin/worker.py` splits the
    // two the same way.
    let source = r#"
use std::collections::HashMap;
use serde::{Serialize, Deserialize as De};
use crate::net::http;
use serde_json;

pub fn go() {}
"#;
    let contributions = scan_fixture("imports", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let mut imports: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();
    imports.sort_unstable();

    // An exact set, not a containment check: it pins that the symbol's own
    // path is NOT emitted (a target built from the full path would read
    // `rust:module:std::collections::HashMap`), and that one `use` line
    // naming two symbols from one module emits one edge, not two.
    assert_eq!(
        vec![
            "rust:module:crate::net",
            "rust:module:serde",
            "rust:module:serde_json",
            "rust:module:std::collections",
        ],
        imports
    );
    for edge in edges.iter().filter(|edge| edge["kind"] == "imports") {
        assert_eq!(
            "rust:module:crate", edge["source"],
            "imports are owned by the module"
        );
    }
}

#[test]
fn an_imported_symbol_still_resolves_a_call_through_its_alias() {
    // The companion to the test above: retargeting the EDGE must leave the
    // alias map alone, so an imported name still resolves a `calls` target to
    // the symbol's own full path, not to the module the edge points at.
    let source = r#"
use crate::net::http::get;
use crate::net::client as remote;

pub fn go() {
    get();
    remote::fetch();
}
"#;
    let contributions = scan_fixture("import-aliases", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let calls = |target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "calls"
                && edge["source"] == "rust:function:crate::go"
                && edge["target"] == target
        })
    };

    assert!(calls("rust:function:crate::net::http::get"));
    assert!(calls("rust:function:crate::net::client::fetch"));
}

#[test]
fn a_use_inside_a_nested_mod_is_attributed_to_the_files_own_module() {
    let source = r#"
mod inner {
    use serde::Serialize;
}
"#;
    let contributions = scan_fixture("nested-mod-imports", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate"
            && edge["target"] == "rust:module:serde"
    }));
}

#[test]
fn impl_blocks_and_supertraits_become_inheritance_edges() {
    let source = r#"
use std::fmt::Display;

pub trait Named: Display {
    fn name(&self) -> String;
}

pub struct Engine;

impl Named for Engine {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("inheritance", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let has = |kind: &str, source: &str, target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == kind && edge["source"] == source && edge["target"] == target
        })
    };

    assert!(has(
        "implements",
        "rust:class:crate::Engine",
        "rust:interface:crate::Named"
    ));
    assert!(has(
        "extends",
        "rust:interface:crate::Named",
        "rust:interface:std::fmt::Display"
    ));
}

#[test]
fn an_implements_edge_sourced_from_an_undeclared_type_is_dropped() {
    // Same drop mechanism `Facts::finish` already applies to `contains` edges
    // (see `an_impl_for_an_undeclared_type_emits_methods_but_no_contains_edge`),
    // exercised here for `implements`: `Elsewhere` is never declared in this
    // file, so an edge sourced from it cannot survive.
    let source = r#"
pub trait Named {
    fn name(&self) -> String;
}

impl Named for Elsewhere {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("implements-undeclared-source", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| edge["kind"] == "implements"),
        "an implements edge whose source type was never declared here must be dropped"
    );
}

#[test]
fn a_self_leaf_imports_the_prefix_module_itself() {
    // `use crate::foo::{self, bar};` binds `foo` to the module `crate::foo`,
    // not to a symbol inside it, so that leaf's edge points at `crate::foo`
    // while `bar`'s points at the module holding it — the same `crate::foo`.
    // One edge covers both.
    let source = r#"
use crate::foo::{self, bar};

pub fn go() {}
"#;
    let contributions = scan_fixture("use-self-leaf", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let imports: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();

    assert_eq!(vec!["rust:module:crate::foo"], imports);
}

#[test]
fn a_use_self_import_is_rebased_against_the_current_module() {
    let source = r#"
use self::inner::Thing;

pub mod inner {
    pub struct Thing;
}
"#;
    let contributions = scan_fixture("use-self-rebased", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate::net"
            && edge["target"] == "rust:module:crate::net::inner"
    }));
}

#[test]
fn a_use_super_import_is_rebased_against_the_parent_module() {
    let source = "use super::Parent;\n";
    let contributions = scan_fixture("use-super-rebased", &[("src/net/http.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate::net::http"
            && edge["target"] == "rust:module:crate::net"
    }));
}

#[test]
fn a_super_chain_escaping_the_crate_root_emits_no_import_edge() {
    let source = "use super::super::super::Unreachable;\n";
    let contributions = scan_fixture("use-super-escapes-root", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| edge["kind"] == "imports"),
        "a super chain longer than the module path names nothing resolvable"
    );
}

#[test]
fn an_impl_for_a_qualified_type_resolves_the_correct_struct() {
    // Two `Engine`s exist in this file: one at the crate root, one nested in
    // `mod other`. `impl Named for other::Engine` names the nested one
    // specifically. `type_path` used to take only the self type's last path
    // segment, which collapses `other::Engine` to `crate::Engine` — a node
    // that genuinely exists here, so the Task 3 "edge source never declared"
    // filter cannot catch the misattribution. The implements edge must source
    // from the nested struct, never the unrelated top-level one.
    let source = r#"
pub trait Named {
    fn name(&self) -> String;
}

pub struct Engine;

mod other {
    pub struct Engine;
}

impl Named for other::Engine {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("impl-qualified-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "implements"
            && edge["source"] == "rust:class:crate::other::Engine"
            && edge["target"] == "rust:interface:crate::Named"
    }));
    assert!(
        !edges.iter().any(|edge| {
            edge["kind"] == "implements" && edge["source"] == "rust:class:crate::Engine"
        }),
        "the implements edge must not be misattributed to the unrelated top-level Engine"
    );
}

#[test]
fn a_bare_same_file_impl_still_attaches_methods_to_its_own_type() {
    // Regression lock for the `type_path` -> `path_target` refactor: an
    // unqualified, unaliased self type must resolve exactly as before.
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {}
}
"#;
    let contributions = scan_fixture("impl-bare-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::Engine"
            && edge["target"] == "rust:method:crate::Engine::start"
    }));
}

#[test]
fn an_impl_for_an_aliased_type_resolves_through_the_alias_map() {
    let source = r#"
use crate::net::Engine;

impl Engine {
    pub fn start(&self) {}
}

pub mod net {
    pub struct Engine;
}
"#;
    let contributions = scan_fixture("impl-aliased-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::net::Engine"
            && edge["target"] == "rust:method:crate::net::Engine::start"
    }));
}

#[test]
fn self_and_super_use_leaves_inside_a_nested_mod_rebase_against_the_nested_module() {
    // The edge SOURCE is always the file's own module (`crate::net`), per
    // `collect_uses`'s design note, regardless of nesting. But what a
    // `self::`/`super::` path itself MEANS is a different question: Rust
    // resolves those against the module the `use` line lexically appears in
    // — here, the nested `inner` module, not the file's own module. A
    // refactor that flattened rebasing to `self.module` would look plausible
    // and would break this silently without a dedicated test.
    let source = r#"
mod inner {
    use self::deep::Thing;
    use super::Outer;

    pub mod deep {
        pub struct Thing;
    }
}

pub struct Outer;
"#;
    let contributions = scan_fixture("nested-mod-self-super-rebase", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let imports = |target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "imports"
                && edge["source"] == "rust:module:crate::net"
                && edge["target"] == target
        })
    };

    assert!(imports("rust:module:crate::net::inner::deep"));
    assert!(imports("rust:module:crate::net"));
}

#[test]
fn calls_resolve_through_the_import_map() {
    let source = r#"
use crate::net::http;

pub struct Engine;

impl Engine {
    pub fn start(&self) {
        http::get();
        helper();
        Engine::stop();
    }

    pub fn stop() {}
}

pub fn helper() {}
"#;
    let contributions = scan_fixture("calls", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let calls: Vec<(&str, &str)> = edges
        .iter()
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| {
            (
                edge["source"].as_str().unwrap(),
                edge["target"].as_str().unwrap(),
            )
        })
        .collect();

    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:function:crate::net::http::get"
    )));
    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:function:crate::helper"
    )));
    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:method:crate::Engine::stop"
    )));
}

#[test]
fn an_unresolved_qualified_call_head_is_dropped_rather_than_guessed() {
    // `String` is a prelude type: neither imported nor declared here. Building
    // the target from the enclosing container produced
    // `crate::net::http::String::from`, a `crate::`-rooted name no crate member
    // declares, which the reconciler materialised as a fabricated external
    // node. Valid Rust requires a bare multi-segment head to be imported or
    // locally declared, so dropping it loses no legitimate edge. `Engine::stop`
    // in the same file is exactly such a legitimate head, and must survive.
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {
        String::from("x");
        serde_json::to_value(1);
        Engine::stop();
    }

    pub fn stop() {}
}
"#;
    let contributions = scan_fixture("unresolved-qualified-call", &[("src/net/http.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let targets: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();

    assert_eq!(
        vec!["rust:method:crate::net::http::Engine::stop"],
        targets,
        "only a head this file declares survives"
    );
}

#[test]
fn repeated_edges_collapse_to_one_row_per_kind_source_target() {
    // The scanner SDK stores one edge per kind/source/target within an owner
    // (docs/reference/scanner-sdk.md), and the Python worker keys its edge map
    // the same way. Every symbol imported from one module renders the identical
    // `imports` row, and a call repeated in two branches renders the identical
    // `calls` row, so the contribution collapses them instead of shipping the
    // duplicates.
    let source = r#"
use crate::net::http::{get, post};
use crate::net::http::head;

pub fn caller(flag: bool) {
    if flag {
        crate::helper();
    } else {
        crate::helper();
    }
}
"#;
    let contributions = scan_fixture("collapsed-edges", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let rows: Vec<(&str, &str)> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports" || edge["kind"] == "calls")
        .map(|edge| {
            (
                edge["kind"].as_str().unwrap(),
                edge["target"].as_str().unwrap(),
            )
        })
        .collect();

    assert_eq!(
        vec![
            ("calls", "rust:function:crate::helper"),
            ("imports", "rust:module:crate::net::http"),
        ],
        rows,
        "three imported symbols and two identical calls collapse to one row each"
    );
}

#[test]
fn a_qself_rooted_call_emits_no_edge_for_its_bare_callee() {
    // `syn` renders `<Widget>::default()` as the bare path `default` with
    // `leading_colon` set, because the qualified self takes position 0. Read
    // literally that is an absolute path, and trusting it emitted a `calls`
    // edge to `rust:function:default`, which the reconciler materialised as an
    // external node whose canonical name is the single word `default`. A
    // single-segment callee is never trusted outright, so this resolves to
    // nothing this file declares and is dropped.
    let source = r#"
pub struct Widget;

pub fn build() {
    <Widget>::default();
}
"#;
    let contributions = scan_fixture("qself-bare-callee", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges.iter().any(|edge| edge["kind"] == "calls"),
        "a bare qself callee names no declaration here: {edges:?}"
    );
}

#[test]
fn a_call_through_an_fn_receiver_emits_no_edge() {
    // `self()` calls the `Fn` receiver itself. The path is the single segment
    // `self`, which rebases to the enclosing module, so trusting it emitted a
    // `rust:function:` edge to a path that names a declared MODULE — an
    // `external_function` twin of a real node. A single-segment callee is
    // never trusted outright, and `crate` is declared as a module rather than
    // a function, so the deferred check drops it.
    let source = r#"
pub trait Handler {
    fn handle(self);
}

impl<F: Fn()> Handler for F {
    fn handle(self) {
        self();
    }
}
"#;
    let contributions = scan_fixture("fn-receiver-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges.iter().any(|edge| edge["kind"] == "calls"),
        "calling the receiver names no declaration here: {edges:?}"
    );
}

#[test]
fn a_method_call_on_an_unknown_receiver_emits_no_edge() {
    // A receiver no signature, annotation or constructor names: the result of
    // a call, and a closure parameter. An edge here would be a guess.
    let source = "pub fn go() { make().run(); let f = |v| v.run(); f(1); }\n";
    let contributions = scan_fixture("unknown-receiver", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges
            .iter()
            .any(|edge| edge["kind"] == "calls"
                && edge["target"].as_str().unwrap().ends_with("::run")),
        "{edges:?}"
    );
}

/// The `calls` edges from `source` into a `::<method>` target, as
/// `(target, speculative)` pairs.
fn method_calls(contributions: &[Value], source: &str) -> Vec<(String, bool)> {
    let mut calls: Vec<(String, bool)> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "calls" && edge["source"] == source)
        .map(|edge| {
            (
                edge["target"].as_str().unwrap().to_owned(),
                edge["attributes"]["speculative"] == Value::Bool(true),
            )
        })
        .collect();
    calls.sort();
    calls
}

#[test]
fn a_method_call_on_a_receiver_of_known_type_is_a_speculative_edge() {
    // Rust names a receiver's type in the signature, the annotation or the
    // constructor, so `self.evaluate()`, `p.evaluate()` on `p: &Policy` and
    // `let q = Policy::new(); q.evaluate()` all say which method they call.
    // Leaving them out reported nearly every method as unreferenced. The
    // target is still a guess for a trait method (`p.clone()`), so the edge is
    // speculative: the core keeps it only when the method exists.
    let source = r#"
pub struct Policy;

impl Policy {
    pub fn new() -> Self { Policy }
    pub fn evaluate(&self) {}
    pub fn evaluate_all(&self) { self.evaluate(); }
}

pub fn by_parameter(p: &Policy) { p.evaluate(); p.clone(); }

pub fn by_binding() {
    let q = Policy::new();
    q.evaluate();
    let r: Policy = make();
    r.evaluate();
    let s = Policy {};
    s.evaluate();
}

fn make() -> Policy { Policy }
"#;
    let contributions = scan_fixture("known-receiver", &[("src/lib.rs", source)]);

    assert_eq!(
        method_calls(&contributions, "rust:method:crate::Policy::evaluate_all"),
        vec![("rust:method:crate::Policy::evaluate".to_owned(), true)]
    );
    assert_eq!(
        method_calls(&contributions, "rust:function:crate::by_parameter"),
        vec![
            ("rust:method:crate::Policy::clone".to_owned(), true),
            ("rust:method:crate::Policy::evaluate".to_owned(), true),
        ]
    );
    let by_binding = method_calls(&contributions, "rust:function:crate::by_binding");
    assert_eq!(
        by_binding
            .iter()
            .filter(|(target, _)| target == "rust:method:crate::Policy::evaluate")
            .count(),
        1,
        "three receivers, one deduplicated edge: {by_binding:?}"
    );
    assert!(by_binding.contains(&("rust:method:crate::Policy::evaluate".to_owned(), true)));
}

#[test]
fn a_rebinding_of_unknown_type_forgets_the_receivers_type() {
    let source = r#"
pub struct Policy;
impl Policy { pub fn new() -> Self { Policy } pub fn evaluate(&self) {} }
pub fn go() {
    let p = Policy::new();
    let p = something();
    p.evaluate();
}
fn something() -> u8 { 0 }
"#;
    let contributions = scan_fixture("rebound-receiver", &[("src/lib.rs", source)]);

    assert!(!method_calls(&contributions, "rust:function:crate::go")
        .iter()
        .any(|(target, _)| target.ends_with("::evaluate")),);
}

#[test]
fn an_ambiguous_alias_emits_no_calls_edge_rather_than_guessing() {
    // `Thing` is bound to two different full paths by two different `use`
    // lines, so `Aliases::resolve` is poisoned for it (see resolve.rs). A
    // call naming it must be dropped, not guessed against the container.
    let source = r#"
mod a {
    pub use crate::first::Thing;
}
mod b {
    pub use crate::second::Thing;
}

pub fn caller() {
    Thing::go();
}
"#;
    let contributions = scan_fixture("ambiguous-alias-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| {
        edge["kind"] == "calls" && edge["target"].as_str().unwrap().contains("Thing")
    }));
}

#[test]
fn an_unambiguous_alias_still_resolves_a_calls_edge() {
    let source = r#"
use crate::first::Thing;

pub fn caller() {
    Thing::go();
}
"#;
    let contributions = scan_fixture("unambiguous-alias-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::caller"
            && edge["target"] == "rust:method:crate::first::Thing::go"
    }));
}

#[test]
fn a_call_through_a_local_closure_binding_emits_no_edge() {
    let source = "pub fn go() { let f = || {}; f(); }\n";
    let contributions = scan_fixture("closure-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| edge["kind"] == "calls"));
}

#[test]
fn a_call_through_a_local_fn_pointer_binding_emits_no_edge() {
    let source = "fn helper() {}\npub fn go() { let f: fn() = helper; f(); }\n";
    let contributions = scan_fixture("fn-pointer-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| edge["kind"] == "calls"));
}

#[test]
fn a_bare_call_to_a_function_declared_later_in_the_file_still_resolves() {
    let source = "pub fn caller() { helper(); }\n\nfn helper() {}\n";
    let contributions = scan_fixture("forward-declared-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::caller"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn calls_inside_a_nested_mod_resolve_against_their_own_module() {
    let source = r#"
fn helper() {}

mod inner {
    fn helper() {}
    fn caller() {
        helper();
    }
}
"#;
    let contributions = scan_fixture("nested-mod-call-container", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::inner::caller"
            && edge["target"] == "rust:function:crate::inner::helper"
    }));
    assert!(!edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::inner::caller"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn a_qualified_path_call_resolves_to_the_traits_declared_method() {
    let source = r#"
pub trait Named {
    fn name() -> &'static str;
}

pub struct Engine;

impl Named for Engine {
    fn name() -> &'static str {
        "engine"
    }
}

pub fn describe() {
    <Engine as Named>::name();
}
"#;
    let contributions = scan_fixture("qualified-path-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::describe"
            && edge["target"] == "rust:method:crate::Named::name"
    }));
}

#[test]
fn a_call_inside_a_trait_default_body_resolves() {
    let source = r#"
pub fn helper() {}

pub trait Greeter {
    fn greet(&self) {
        helper();
    }
}
"#;
    let contributions = scan_fixture("trait-default-body-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:method:crate::Greeter::greet"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn a_syntax_error_costs_only_its_own_file() {
    let contributions = scan_fixture(
        "syntax-error",
        &[
            ("src/broken.rs", "pub fn ( {\n"),
            ("src/good.rs", "pub fn fine() {}\n"),
        ],
    );

    assert_eq!(2, contributions.len(), "every file gets a contribution");
    let broken = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/broken.rs")
        .unwrap();
    assert_eq!("RS_SYNTAX_ERROR", broken["diagnostics"][0]["code"]);
    assert_eq!("error", broken["diagnostics"][0]["severity"]);
    assert!(
        broken["diagnostics"][0]["evidence"]["start_line"]
            .as_u64()
            .unwrap()
            >= 1
    );

    let good = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/good.rs")
        .unwrap();
    assert!(good["diagnostics"].as_array().unwrap().is_empty());
    assert!(!good["nodes"].as_array().unwrap().is_empty());
}

#[test]
fn a_missing_file_is_a_diagnostic_not_a_failed_request() {
    let contributions = scan_fixture("missing", &[("src/present.rs", "pub fn here() {}\n")]);
    assert_eq!(1, contributions.len());

    // Scan a path the fixture never wrote, through the same request shape.
    let root = std::env::temp_dir().join("knossos-rust-missing-only");
    std::fs::create_dir_all(&root).unwrap();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/absent.rs"],
        },
    });
    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(request.to_string().into_bytes()), &mut output)
        .unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    assert!(text.contains("RS_UNSCANNABLE_FILE"));
    // Parse each reply rather than substring-matching: the diagnostic itself
    // legitimately carries `"severity":"error"`, so a blind search for the
    // word "error" would fire on that and could never pass. What actually
    // matters is that no reply is a JSON-RPC error object.
    let replies: Vec<Value> = text
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).expect("worker wrote non-JSON stdout"))
        .collect();
    assert!(
        replies.iter().all(|reply| reply.get("error").is_none()),
        "a missing file must not fail the request",
    );
}

#[test]
fn an_oversized_file_is_refused_by_the_byte_limit() {
    let root = std::env::temp_dir().join("knossos-rust-oversized");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("src/big.rs"), "pub fn big() {}\n".repeat(100)).unwrap();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/big.rs"],
            "limits": {"max_file_bytes": 10},
        },
    });
    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(request.to_string().into_bytes()), &mut output)
        .unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    assert!(text.contains("File exceeds the scan byte limit."));
}

#[test]
fn files_scanned_counts_this_request_only() {
    // The core splits one language's work into batches and sends them as
    // separate `scan` requests over the *same* session, summing each
    // request's `files_scanned` into a running total. A worker that reset
    // its counter per process instead of per request (a cumulative total)
    // would only be exposed by a second request in that same session — a
    // single-request test cannot distinguish "counts this request" from
    // "counts everything the process has ever scanned". Two files in the
    // first request and one in the second also rules out a coincidental
    // match against a hardcoded or otherwise-wrong constant.
    let root = std::env::temp_dir().join("knossos-rust-counting-two-requests");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("src/a.rs"), "pub fn a() {}\n").unwrap();
    std::fs::write(root.join("src/b.rs"), "pub fn b() {}\n").unwrap();
    std::fs::write(root.join("src/c.rs"), "pub fn c() {}\n").unwrap();
    let canonical_root = std::fs::canonicalize(&root).unwrap();
    let canonical_root = canonical_root.to_str().unwrap();

    let first = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": canonical_root,
            "files": ["src/a.rs", "src/b.rs"],
        },
    });
    let second = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "scan",
        "params": {
            "root": canonical_root,
            "files": ["src/c.rs"],
        },
    });
    let mut input = first.to_string();
    input.push('\n');
    input.push_str(&second.to_string());
    input.push('\n');

    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(input.into_bytes()), &mut output).unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    let results: Vec<Value> = text
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str::<Value>(line).unwrap())
        .filter(|reply| reply.get("result").is_some())
        .collect();
    assert_eq!(2, results.len(), "one result reply per request");
    assert_eq!(
        2, results[0]["result"]["files_scanned"],
        "first request scans two files"
    );
    assert_eq!(
        1, results[1]["result"]["files_scanned"],
        "second request scans one file; a cumulative counter would report 3"
    );
}

#[test]
fn an_expr_struct_instantiation_emits_a_calls_edge() {
    let facts = scan_fixture(
        "exprstruct",
        &[(
            "src/lib.rs",
            "
        struct Widget {}
        fn factory() {
            Widget {};
        }
    ",
        )],
    );
    let edges = facts[0]["edges"].as_array().unwrap();
    let edge = edges.iter().find(|e| {
        e["kind"] == "calls"
            && e["source"] == "rust:function:crate::factory"
            && e["target"] == "rust:class:crate::Widget"
    });
    assert!(
        edge.is_some(),
        "missing calls edge to Widget. Edges: {:?}",
        edges
    );
}

fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

#[test]
fn every_read_file_reports_the_hash_of_its_raw_bytes() {
    let files = [
        ("src/bom.rs", "\u{feff}pub fn bom() {}\n"),
        ("src/crlf.rs", "pub fn crlf() {}\r\n"),
        ("src/broken.rs", "pub fn {\n"),
    ];
    let contributions = scan_fixture("content-hash", &files);

    for (relative, source) in files {
        let owner = format!("knossos.rust:file:{relative}");
        let contribution = contributions
            .iter()
            .find(|c| c["owner_key"] == owner.as_str())
            .unwrap_or_else(|| panic!("no contribution for {relative}"));
        assert_eq!(
            sha256_hex(source.as_bytes()),
            contribution["content_hash"],
            "{relative}"
        );
    }
}

#[test]
fn a_file_that_was_never_read_reports_no_hash() {
    let contributions = scan_fixture_with(
        "content-hash-unread",
        &[("src/big.rs", "pub fn big() {}\n")],
        &serde_json::json!({"limits": {"max_file_bytes": 1}}),
    );

    assert_eq!(1, contributions.len());
    assert!(contributions[0].get("content_hash").is_none());
}

#[test]
fn the_result_reports_input_hashes_for_every_file_it_read() {
    // The exact requested-files map: a BOM file and a syntax-error file both
    // get read (and hashed), so both belong in `input_hashes` even though the
    // syntax-error file contributes no nodes or edges.
    let files: [(&str, &[u8]); 2] = [
        ("src/bom.rs", "\u{feff}pub fn bom() {}\n".as_bytes()),
        ("src/broken.rs", b"pub fn {\n"),
    ];
    let result = scan_result_with_bytes("input-hashes", &files, &serde_json::json!({}));

    let input_hashes = result["input_hashes"]
        .as_object()
        .expect("input_hashes must be a JSON object");
    assert_eq!(2, input_hashes.len());
    for (relative, bytes) in files {
        assert_eq!(
            Value::String(sha256_hex(bytes)),
            input_hashes[relative],
            "{relative}"
        );
    }
}

/// Scan `requested` under an existing `root`, returning the contributions and
/// the final result. Unlike the fixture helpers, the requested paths need not
/// be files the test wrote, so a test can ask for what is missing.
fn scan_existing_root(
    root: &std::path::Path,
    requested: &[&str],
    params: &Value,
) -> (Vec<Value>, Value) {
    let mut merged = serde_json::json!({
        "root": std::fs::canonicalize(root).unwrap().to_str().unwrap(),
        "files": requested,
    });
    for (key, value) in params.as_object().unwrap() {
        merged[key] = value.clone();
    }
    let request =
        serde_json::json!({"jsonrpc": "2.0", "id": 1, "method": "scan", "params": merged});
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let contributions = replies
        .iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect();
    let result = replies
        .iter()
        .find(|reply| reply.get("result").is_some())
        .map(|reply| reply["result"].clone())
        .expect("scan request produced no result");

    (contributions, result)
}

/// A fresh, empty temporary root named for the test.
fn fresh_root(name: &str) -> std::path::PathBuf {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(&root).unwrap();
    root
}

#[test]
fn a_requested_file_whose_read_fails_is_reported_as_null() {
    // Over the byte cap, gone, a directory, or a link leaving the root: the
    // filesystem refused the read, and the contribution standing in for the
    // file carries no facts. Null makes the core fail the scan for a
    // discovered path instead of keeping a graph without them.
    let root = fresh_root("input-hashes-read-failures");
    let outside = fresh_root("input-hashes-read-failures-outside");
    std::fs::create_dir_all(root.join("src/dir.rs")).unwrap();
    std::fs::write(root.join("src/big.rs"), "pub fn big() {}\n").unwrap();
    std::fs::write(outside.join("out.rs"), "pub fn out() {}\n").unwrap();
    std::os::unix::fs::symlink(outside.join("out.rs"), root.join("src/out.rs")).unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["src/big.rs", "src/dir.rs", "src/gone.rs", "src/out.rs"],
        &serde_json::json!({"limits": {"max_file_bytes": 10}}),
    );
    let _ = std::fs::remove_dir_all(&root);
    let _ = std::fs::remove_dir_all(&outside);

    assert_eq!(
        serde_json::json!({"src/big.rs": null, "src/dir.rs": null, "src/gone.rs": null, "src/out.rs": null}),
        result["input_hashes"]
    );
    assert_eq!(4, contributions.len());
    for contribution in &contributions {
        assert_eq!(serde_json::json!([]), contribution["nodes"]);
        assert_eq!(
            "RS_UNSCANNABLE_FILE",
            contribution["diagnostics"][0]["code"]
        );
    }
    assert_eq!(
        "Scan path is not a regular file.",
        contributions[1]["diagnostics"][0]["message"]
    );
}

#[test]
fn an_absent_crate_root_probe_is_reported_as_null_and_a_present_unread_one_is_not() {
    // Whether `src/lib.rs` exists decides where the package node attaches and
    // what `src/main.rs`'s module path is. An absent answer is recorded, so a
    // discovered root missing for that moment fails the scan; a present root
    // this request never read carries no facts from it and stays unreported.
    let root = fresh_root("input-hashes-crate-probes");
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("Cargo.toml"), "[package]\nname = \"probe\"\n").unwrap();
    std::fs::write(root.join("src/main.rs"), "fn main() {}\n").unwrap();
    std::fs::write(root.join("src/other.rs"), "pub fn other() {}\n").unwrap();

    let (_, result) = scan_existing_root(
        &root,
        &["src/other.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(
        serde_json::json!({
            "Cargo.toml": sha256_hex(b"[package]\nname = \"probe\"\n"),
            "src/lib.rs": null,
            "src/other.rs": sha256_hex(b"pub fn other() {}\n"),
        }),
        result["input_hashes"]
    );
}

#[test]
fn a_crate_root_that_is_not_a_regular_file_is_reported_as_null() {
    // Not a file to the probe and not readable to the request: both answers
    // are null, and a requested directory must not be mistaken for a crate root.
    let root = fresh_root("input-hashes-crate-probe-conflict");
    std::fs::create_dir_all(root.join("src/lib.rs")).unwrap();
    std::fs::write(root.join("Cargo.toml"), "[package]\nname = \"probe\"\n").unwrap();

    let (_, result) = scan_existing_root(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(
        serde_json::json!({
            "Cargo.toml": sha256_hex(b"[package]\nname = \"probe\"\n"),
            "src/lib.rs": null,
            "src/main.rs": null,
        }),
        result["input_hashes"]
    );
}

#[test]
fn a_file_that_is_not_utf8_still_reports_the_hash_of_its_raw_bytes() {
    // `prepare_one` reads bytes before it ever tries to decode them, so a file
    // that fails the UTF-8 check has still been read: it must carry the hash
    // of exactly those bytes, the same as a file that goes on to parse
    // successfully or fails later with a syntax error.
    let bytes: &[u8] = b"pub fn go() {\xff}\n";
    let contributions = scan_fixture_with_bytes(
        "content-hash-non-utf8",
        &[("src/binary.rs", bytes)],
        &serde_json::json!({}),
    );

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(sha256_hex(bytes), contribution["content_hash"]);
    assert_eq!(0, contribution["nodes"].as_array().unwrap().len());
    assert!(contribution["diagnostics"]
        .as_array()
        .unwrap()
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_UNSCANNABLE_FILE"));
}

#[test]
fn self_in_impl_block_resolves_to_the_impl_type() {
    let facts = scan_fixture(
        "selfimpl",
        &[(
            "src/lib.rs",
            "
        struct Widget {}
        impl Widget {
            fn factory() {
                Self {};
            }
        }
    ",
        )],
    );
    let edges = facts[0]["edges"].as_array().unwrap();
    let edge = edges.iter().find(|e| {
        e["kind"] == "calls"
            && e["source"] == "rust:method:crate::Widget::factory"
            && e["target"] == "rust:class:crate::Widget"
    });
    assert!(
        edge.is_some(),
        "missing calls edge from factory to Widget using Self. Edges: {:?}",
        edges
    );
}

#[test]
fn an_input_hashes_map_larger_than_one_part_goes_out_ahead_of_the_result_in_parts() {
    // Long paths make a batch's map outgrow one part; every frame stays within
    // the part budget and the parts together hold every file read.
    let root = std::env::temp_dir().join("knossos-rust-input-hashes-parts");
    let _ = std::fs::remove_dir_all(&root);
    let directory = format!(
        "src/{}/{}/{}",
        "d".repeat(250),
        "e".repeat(250),
        "f".repeat(250)
    );
    std::fs::create_dir_all(root.join(&directory)).unwrap();
    let mut expected = serde_json::Map::new();
    let mut requested = Vec::new();
    for index in 0..400 {
        let relative = format!("{directory}/value_{index:04}.rs");
        let contents = format!("pub struct Value{index};\n");
        std::fs::write(root.join(&relative), &contents).unwrap();
        expected.insert(
            relative.clone(),
            Value::String(sha256_hex(contents.as_bytes())),
        );
        requested.push(relative);
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": requested,
        },
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    let mut merged = serde_json::Map::new();
    let mut parts = 0;
    let mut result_seen = false;
    for line in String::from_utf8(output).unwrap().lines() {
        let reply: Value = serde_json::from_str(line).unwrap();
        let map = if reply["method"] == "scan/input_hashes" {
            assert!(!result_seen, "a part arrived after the result");
            assert!(line.len() < 256_100, "a part of {} bytes", line.len());
            parts += 1;
            &reply["params"]["input_hashes"]
        } else if reply.get("result").is_some() {
            result_seen = true;
            &reply["result"]["input_hashes"]
        } else {
            continue;
        };
        for (relative, hash) in map.as_object().unwrap() {
            assert!(
                merged.insert(relative.clone(), hash.clone()).is_none(),
                "{relative} twice"
            );
        }
    }

    assert!(result_seen);
    assert!(parts >= 1, "the map went out on the result's line alone");
    assert_eq!(expected, merged);
}

#[test]
fn a_cargo_manifest_read_for_its_crate_name_reports_the_hash_of_its_raw_bytes() {
    // The manifest names the crate, so its bytes feed facts, and discovery
    // hashes it as a project unit that the core checks the entry against. A
    // manifest that is not UTF-8 was still read and names no crate.
    let root = fresh_root("input-hashes-cargo-manifest");
    std::fs::create_dir_all(root.join("app/src")).unwrap();
    std::fs::create_dir_all(root.join("bad")).unwrap();
    let manifest = b"[package]\nname = \"app\"\n";
    std::fs::write(root.join("app/Cargo.toml"), manifest).unwrap();
    std::fs::write(root.join("app/src/lib.rs"), "pub fn app() {}\n").unwrap();
    std::fs::write(root.join("bad/Cargo.toml"), b"[package]\nname = \"\xff\"\n").unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["app/src/lib.rs"],
        &serde_json::json!({"config_files": ["app/Cargo.toml", "bad/Cargo.toml", "gone/Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(
        Value::String(sha256_hex(manifest)),
        result["input_hashes"]["app/Cargo.toml"]
    );
    assert_eq!(
        Value::String(sha256_hex(b"[package]\nname = \"\xff\"\n")),
        result["input_hashes"]["bad/Cargo.toml"]
    );
    assert_eq!(Value::Null, result["input_hashes"]["gone/Cargo.toml"]);
    assert!(result["input_hashes"].get("bad/src/lib.rs").is_none());
    assert!(contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "package" && node["canonical_name"] == "app"));
}

#[test]
fn a_cargo_manifest_over_the_byte_cap_is_reported_as_null_and_names_no_crate() {
    let root = fresh_root("input-hashes-cargo-manifest-cap");
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(
        root.join("Cargo.toml"),
        format!("[package]\nname = \"big\"\n#{}\n", "x".repeat(64)),
    )
    .unwrap();
    std::fs::write(root.join("src/lib.rs"), "pub fn a() {}\n").unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "limits": {"max_file_bytes": 40}}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(Value::Null, result["input_hashes"]["Cargo.toml"]);
    assert!(!contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "package"));
}

/// Every edge of `kind` as a `(source, target)` pair, sorted.
fn edges_of(contributions: &[Value], kind: &str) -> Vec<(String, String)> {
    let mut edges: Vec<(String, String)> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == kind)
        .map(|edge| {
            (
                edge["source"].as_str().unwrap().to_owned(),
                edge["target"].as_str().unwrap().to_owned(),
            )
        })
        .collect();
    edges.sort();
    edges
}

#[test]
fn calls_inside_macro_arguments_are_edges() {
    // `format!`, `assert_eq!` and `println!` bodies are token streams to syn,
    // so a helper only ever called inside one read as uncalled.
    let source = r#"
fn helper() -> u8 { 1 }
fn other() -> u8 { 2 }
pub fn go() {
    let s = format!("{}-{}", helper(), 3);
    assert_eq!(other(), 2);
    println!("{s}");
}
"#;
    let contributions = scan_fixture("macro-calls", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");

    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::helper".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::other".to_owned()
        )),
        "{calls:?}"
    );
}

#[test]
fn types_named_in_fields_signatures_and_patterns_are_references() {
    // A struct or enum used only as a type, a field, or a match arm has no
    // call to be reached by, so without these edges it read as unreferenced.
    let source = r#"
pub enum HookState { Live, Absent }
pub struct Rule;
pub struct Policy { rules: Vec<Rule> }
pub trait Gate {}
pub fn check(s: HookState) -> Option<Rule> {
    match s {
        HookState::Live => None,
        HookState::Absent => None,
    }
}
pub fn boxed(_g: &dyn Gate) {}
"#;
    let contributions = scan_fixture("type-references", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    for (source, target) in [
        ("rust:class:crate::Policy", "rust:class:crate::Rule"),
        ("rust:function:crate::check", "rust:class:crate::HookState"),
        ("rust:function:crate::check", "rust:class:crate::Rule"),
        ("rust:function:crate::boxed", "rust:interface:crate::Gate"),
    ] {
        assert!(
            references.contains(&(source.to_owned(), target.to_owned())),
            "{source} -> {target} missing from {references:?}"
        );
    }
    // Every such edge is speculative: `Vec` and `Option` name nothing here,
    // and the core drops what does not resolve.
    let all_speculative = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "references")
        .all(|edge| edge["attributes"]["speculative"] == Value::Bool(true));
    assert!(all_speculative);
}

#[test]
fn functions_named_by_serde_attributes_are_references() {
    let source = r#"
#[derive(serde::Deserialize)]
pub struct Config {
    #[serde(default = "default_action", skip_serializing_if = "is_false")]
    pub flag: bool,
}
fn default_action() -> bool { true }
fn is_false(value: &bool) -> bool { !*value }
"#;
    let contributions = scan_fixture("serde-references", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    for target in [
        "rust:function:crate::default_action",
        "rust:function:crate::is_false",
    ] {
        assert!(
            references.contains(&("rust:class:crate::Config".to_owned(), target.to_owned())),
            "{target} missing from {references:?}"
        );
    }
}

#[test]
fn calls_in_a_const_initializer_are_edges_from_the_module() {
    let source = "const fn seed() -> u8 { 1 }\npub const ALL: [u8; 1] = [seed()];\n";
    let contributions = scan_fixture("const-calls", &[("src/lib.rs", source)]);

    assert!(edges_of(&contributions, "calls").contains(&(
        "rust:module:crate".to_owned(),
        "rust:function:crate::seed".to_owned()
    )));
}

#[test]
fn a_use_through_a_child_module_is_anchored_at_that_module() {
    // In a crate root, `use policy::Policy;` names the `mod policy` declared
    // beside it. Rendered as written, `policy::Policy::builtin()` became an
    // external method on a crate nobody depends on.
    let files = [
        (
            "src/main.rs",
            "mod policy;\nuse policy::Policy;\nfn main() { Policy::builtin(); }\n",
        ),
        (
            "src/policy.rs",
            "pub struct Policy;\nimpl Policy { pub fn builtin() {} }\n",
        ),
    ];
    let contributions = scan_fixture("child-module-use", &files);

    assert!(edges_of(&contributions, "calls").contains(&(
        "rust:function:crate::main".to_owned(),
        "rust:method:crate::policy::Policy::builtin".to_owned()
    )));
    assert!(edges_of(&contributions, "imports").contains(&(
        "rust:module:crate".to_owned(),
        "rust:module:crate::policy".to_owned()
    )));
}

#[test]
fn a_function_passed_by_name_is_a_reference_when_this_file_declares_it() {
    // `.map(helper)` and `.or_else(fallback)` hand a function over without
    // calling it. A bare name is usually a local binding, so it counts only
    // when this file declares a function by that name.
    let source = r#"
fn helper(x: u8) -> u8 { x }
pub fn go(values: Vec<u8>) -> usize {
    let local = 1;
    values.into_iter().map(helper).filter(|v| *v > local).count()
}
"#;
    let contributions = scan_fixture("function-by-name", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    assert!(
        references.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::helper".to_owned()
        )),
        "{references:?}"
    );
    assert!(
        !references
            .iter()
            .any(|(_, target)| target.ends_with("::local")),
        "{references:?}"
    );
}

#[test]
fn a_workspace_members_modules_are_rooted_at_its_crate_name() {
    // A member crate's `src/` is its own crate root. Named by directory, its
    // modules came out as `crates::engine::src::app`, so every `crate::` path
    // inside it and every `use engine::...` from a sibling crate named
    // nothing, and the whole crate read as unreferenced.
    let files = [
        ("Cargo.toml", "[workspace]\nmembers = [\"crates/*\"]\n"),
        ("crates/core-lib/Cargo.toml", "[package]\nname = \"core-lib\"\n"),
        ("crates/core-lib/src/lib.rs", "pub mod app;\n"),
        (
            "crates/core-lib/src/app.rs",
            "pub struct Engine;\npub fn boot() -> Engine { crate::app::helper(); Engine }\nfn helper() {}\n",
        ),
        ("crates/cli/Cargo.toml", "[package]\nname = \"cli\"\n"),
        ("crates/cli/src/main.rs", "use core_lib::app::boot;\nfn main() { let _e = boot(); }\n"),
    ];
    let contributions = scan_fixture_with(
        "workspace-members",
        &files,
        &serde_json::json!({
            "config_files": ["Cargo.toml", "crates/cli/Cargo.toml", "crates/core-lib/Cargo.toml"],
        }),
    );
    let modules: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "module")
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();
    let calls = edges_of(&contributions, "calls");

    assert!(modules.contains(&"core_lib::app".to_owned()), "{modules:?}");
    assert!(modules.contains(&"cli".to_owned()), "{modules:?}");
    assert!(
        calls.contains(&(
            "rust:function:core_lib::app::boot".to_owned(),
            "rust:function:core_lib::app::helper".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:cli::main".to_owned(),
            "rust:function:core_lib::app::boot".to_owned()
        )),
        "{calls:?}"
    );
}

#[test]
fn a_trait_impl_for_a_foreign_type_is_a_node_of_the_module_declaring_it() {
    // `impl<T: GitSource> GitSource for Arc<T>` attached its methods to
    // `std::sync::Arc`, a name the project does not own, with nothing tying
    // them to the trait they implement, so every forwarding method read as
    // dead. The impl block is the project's, and it implements the trait.
    let source = r#"
use std::sync::Arc;
pub trait GitSource { fn status(&self); }
pub struct Repo;
impl GitSource for Repo { fn status(&self) {} }
impl<T: GitSource> GitSource for Arc<T> {
    fn status(&self) { (**self).status() }
}
"#;
    let contributions = scan_fixture("foreign-trait-impl", &[("src/lib.rs", source)]);
    let names: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        !names.iter().any(|name| name.starts_with("std::")),
        "{names:?}"
    );
    assert!(
        names.contains(&"crate::<impl GitSource for Arc>::status".to_owned()),
        "{names:?}"
    );
    assert!(edges_of(&contributions, "implements").contains(&(
        "rust:class:crate::<impl GitSource for Arc>".to_owned(),
        "rust:interface:crate::GitSource".to_owned()
    )));
}

#[test]
fn a_call_on_a_returned_value_defers_to_the_declared_return_type() {
    // `s.mode().label()`: the receiver of `label` is whatever `State::mode`
    // returns, which may be declared in another file. The worker names the
    // call and states each method's return type; the core joins the two.
    let source = r#"
pub enum Mode { A }
impl Mode { pub fn label(&self) -> &'static str { "a" } }
pub struct State;
impl State {
    pub fn new() -> Self { State }
    pub fn mode(&self) -> Mode { Mode::A }
}
pub fn go(s: &State) -> usize { s.mode().label().len() + State::new().mode().label().len() }
"#;
    let contributions = scan_fixture("returned-receiver", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");
    let returns = edges_of(&contributions, "returns");

    for target in [
        "rust:method_of_return:crate::State::mode::label",
        "rust:method_of_return:crate::State::new::mode",
    ] {
        assert!(
            calls.contains(&("rust:function:crate::go".to_owned(), target.to_owned())),
            "{target}: {calls:?}"
        );
    }
    assert!(
        returns.contains(&(
            "rust:method:crate::State::mode".to_owned(),
            "rust:class:crate::Mode".to_owned()
        )),
        "{returns:?}"
    );
    assert!(
        returns.contains(&(
            "rust:method:crate::State::new".to_owned(),
            "rust:class:crate::State".to_owned()
        )),
        "{returns:?}"
    );
}

#[test]
fn a_method_called_on_an_enum_variant_resolves_to_the_enum() {
    let source = r#"
pub enum Theme { Regular, HighContrast }
impl Theme { pub fn cycles_to(&self) -> Theme { Theme::HighContrast } }
pub fn go() { let _ = Theme::Regular.cycles_to(); }
"#;
    let contributions = scan_fixture("variant-receiver", &[("src/lib.rs", source)]);

    assert_eq!(
        method_calls(&contributions, "rust:function:crate::go"),
        vec![("rust:method:crate::Theme::cycles_to".to_owned(), true)]
    );
}

#[test]
fn a_function_exported_to_a_foreign_caller_is_runtime_invoked() {
    // `#[no_mangle] pub extern "C" fn` and `#[wasm_bindgen]` functions are
    // called by the host embedding the library, never from Rust.
    let source = r#"
#[no_mangle]
pub extern "C" fn alloc(len: usize) -> usize { len }
#[wasm_bindgen]
pub fn greet() {}
#[export_name = "run"]
pub fn run_exported() {}
pub fn ordinary() {}
"#;
    let contributions = scan_fixture("ffi-exports", &[("src/lib.rs", source)]);
    let invoked: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["attributes"]["runtime_invoked"] == Value::Bool(true))
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();

    assert_eq!(
        invoked,
        vec!["crate::alloc", "crate::greet", "crate::run_exported"]
    );
}

#[test]
fn calls_inside_a_json_like_macro_are_edges() {
    // `json!({ "attrs": a.iter().map(attr).collect() })` is no list of
    // expressions, so the whole body was dropped along with every call in it.
    let source = r#"
fn attr(x: &u8) -> u8 { *x }
fn span() -> u8 { 1 }
fn nested() -> u8 { 2 }
pub fn go(values: Vec<u8>) {
    let _ = serde_json::json!({
        "attrs": values.iter().map(attr).collect::<Vec<_>>(),
        "span": span(),
        "inner": { "deep": [nested()] },
    });
}
"#;
    let contributions = scan_fixture("json-macro", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");
    let references = edges_of(&contributions, "references");

    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::span".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::nested".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        references.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::attr".to_owned()
        )),
        "{references:?}"
    );
}

#[test]
fn a_method_called_through_struct_fields_resolves_to_the_field_type() {
    // `self.walk.facts.edge()` calls `Facts::edge` through two fields. Field
    // types were not tracked, so every method only ever reached that way (a
    // collaborator held in a struct) read as unreferenced.
    let source = r#"
pub struct Facts;
impl Facts { pub fn edge(&self) {} }
pub struct Walk { facts: Facts, count: usize }
pub struct Calls<'a> { walk: &'a mut Walk }
impl Calls<'_> {
    fn go(&mut self) { self.walk.facts.edge(); self.walk.count.count_ones(); }
}
"#;
    let contributions = scan_fixture("field-receiver", &[("src/lib.rs", source)]);

    assert!(method_calls(&contributions, "rust:method:crate::Calls::go")
        .contains(&("rust:method:crate::Facts::edge".to_owned(), true)));
}

#[test]
fn a_library_crate_root_is_entered_from_outside() {
    // A library's `lib.rs` is entered by its dependents, and a `cdylib` or
    // wasm crate by a host outside the repository. Nothing in the graph
    // imports it, so its module read as dead.
    let files = [
        ("Cargo.toml", "[package]\nname = \"engine\"\n"),
        ("src/lib.rs", "pub mod grid;\n"),
        ("src/grid.rs", "pub fn get() {}\n"),
    ];
    let contributions = scan_fixture_with(
        "library-root",
        &files,
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let executable: Vec<(String, bool)> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "module")
        .map(|node| {
            (
                node["canonical_name"].as_str().unwrap().to_owned(),
                node["attributes"]["executable"] == Value::Bool(true),
            )
        })
        .collect();

    assert!(
        executable.contains(&("crate".to_owned(), true)),
        "{executable:?}"
    );
    assert!(
        executable.contains(&("crate::grid".to_owned(), false)),
        "{executable:?}"
    );
}
