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
    // Regression guard for the proc-macro2 `span-locations` feature. Without it
    // every span collapses to line 0 and all evidence is silently useless.
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
fn use_declarations_become_import_edges() {
    let source = r#"
use std::collections::HashMap;
use serde::{Serialize, Deserialize as De};
use crate::net::http;

pub fn go() {}
"#;
    let contributions = scan_fixture("imports", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let imports: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();

    assert!(imports.contains(&"rust:module:std::collections::HashMap"));
    assert!(imports.contains(&"rust:module:serde::Serialize"));
    assert!(imports.contains(&"rust:module:serde::Deserialize"));
    assert!(imports.contains(&"rust:module:crate::net::http"));
    for edge in edges.iter().filter(|edge| edge["kind"] == "imports") {
        assert_eq!(
            "rust:module:crate", edge["source"],
            "imports are owned by the module"
        );
    }
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
            && edge["target"] == "rust:module:serde::Serialize"
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
            && edge["target"] == "rust:module:crate::net::inner::Thing"
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
            && edge["target"] == "rust:module:crate::net::Parent"
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

    assert!(imports("rust:module:crate::net::inner::deep::Thing"));
    assert!(imports("rust:module:crate::net::Outer"));
}
