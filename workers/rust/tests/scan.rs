//! Scanning tests driving the worker against real files on disk.

use std::io::Cursor;
use std::path::Path;

use knossos_rust_worker::server::run;
use serde_json::Value;

/// Write `files` into a fresh temporary root and scan them, returning contributions.
pub fn scan_fixture(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, source) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, source).unwrap();
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": files.iter().map(|(relative, _)| *relative).collect::<Vec<_>>(),
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
    let _ = std::fs::remove_dir_all(Path::new(&root));

    replies
        .into_iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect()
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
fn a_method_call_on_an_unknown_receiver_emits_no_edge() {
    // `value.run()` names no resolvable target: the worker has no type
    // information, so an edge here would be a guess. Dropping it is the
    // documented behaviour.
    let source = "pub fn go(value: Thing) { value.run(); }\n";
    let contributions = scan_fixture("unknown-receiver", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| edge["kind"] == "calls"));
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
