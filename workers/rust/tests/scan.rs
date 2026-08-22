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
    assert!(contains("crate::engine", "crate::engine::Engine"));
    assert!(contains("crate::engine", "crate::engine::inner"));
    assert!(contains(
        "crate::engine::inner",
        "crate::engine::inner::nested"
    ));
    assert!(contains(
        "crate::engine::Engine",
        "crate::engine::Engine::start"
    ));
    assert!(contains(
        "crate::engine::Runner",
        "crate::engine::Runner::run"
    ));
}
