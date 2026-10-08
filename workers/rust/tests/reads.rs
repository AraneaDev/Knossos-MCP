//! What each contribution was read from: the crate-wide declaration index is
//! read from disk whichever files a request names, and every contribution
//! names the files its facts depend on.

use std::collections::BTreeSet;
use std::io::Cursor;
use std::path::{Path, PathBuf};

use knossos_rust_worker::server::run;
use serde_json::{Map, Value};

/// One scan request's answer, with every `scan/input_hashes` part merged back
/// into the result's maps.
struct Answer {
    /// The `scan/contribution` payloads, in order.
    contributions: Vec<Value>,
    /// The final result, its maps holding every part.
    result: Value,
    /// The longest line the worker wrote.
    longest_line: usize,
}

impl Answer {
    /// The contribution owned by `relative`.
    fn of(&self, relative: &str) -> &Value {
        let owner = format!("knossos.rust:file:{relative}");
        self.contributions
            .iter()
            .find(|contribution| contribution["owner_key"] == owner.as_str())
            .unwrap_or_else(|| panic!("no contribution for {relative}"))
    }
}

/// A fresh, empty temporary root named for the test, holding `files`.
fn project(name: &str, files: &[(&str, &str)]) -> PathBuf {
    let root = std::env::temp_dir().join(format!("knossos-rust-reads-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, contents) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, contents).unwrap();
    }
    std::fs::create_dir_all(&root).unwrap();
    root
}

/// Scan `requested` under `root` with `params` merged over the base request,
/// and check the protocol's rules for an attributing worker on the answer.
fn scan(root: &Path, requested: &[&str], params: &Value) -> Answer {
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
    let text = String::from_utf8(output).unwrap();
    let mut contributions = Vec::new();
    let mut parts: Map<String, Value> = Map::new();
    let mut result = Value::Null;
    for line in text.lines() {
        let reply: Value = serde_json::from_str(line).unwrap();
        if reply["method"] == "scan/contribution" {
            contributions.push(reply["params"].clone());
        } else if reply["method"] == "scan/input_hashes" {
            for (field, map) in reply["params"].as_object().unwrap() {
                let merged = parts
                    .entry(field.clone())
                    .or_insert_with(|| Value::Object(Map::new()));
                for (path, hash) in map.as_object().unwrap() {
                    merged[path] = hash.clone();
                }
            }
        } else if reply.get("result").is_some() {
            result = reply["result"].clone();
        }
    }
    for (field, map) in parts {
        for (path, hash) in map.as_object().unwrap() {
            result[&field][path] = hash.clone();
        }
    }
    let answer = Answer {
        contributions,
        result,
        longest_line: text.lines().map(str::len).max().unwrap_or(0),
    };
    assert_follows_the_protocol(&answer, requested);

    answer
}

/// Every read is confirmed by `input_hashes` with the same value, and every
/// entry of `input_hashes` is named by some read or is a requested file.
fn assert_follows_the_protocol(answer: &Answer, requested: &[&str]) {
    let input_hashes = answer.result["input_hashes"].as_object().unwrap();
    let mut named: BTreeSet<String> = requested.iter().map(|path| (*path).to_owned()).collect();
    let mut maps = vec![
        &answer.result["reads"],
        &answer.result["unattributed_reads"],
    ];
    maps.extend(answer.contributions.iter().map(|c| &c["reads"]));
    for map in maps {
        for (path, hash) in map.as_object().expect("reads must be an object") {
            assert_eq!(Some(hash), input_hashes.get(path), "{path}");
            named.insert(path.clone());
        }
    }
    for path in input_hashes.keys() {
        assert!(named.contains(path), "{path} is read but named nowhere");
    }
}

/// Lowercase SHA-256 hex of `bytes`.
fn sha256_hex(bytes: &str) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(bytes.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Whether some contribution has a `calls` edge from `source` to `target`.
fn calls(answer: &Answer, source: &str, target: &str) -> bool {
    answer.contributions.iter().any(|contribution| {
        contribution["edges"]
            .as_array()
            .unwrap()
            .iter()
            .any(|edge| {
                edge["kind"] == "calls"
                    && edge["source"]
                        .as_str()
                        .is_some_and(|value| value.ends_with(source))
                    && edge["target"]
                        .as_str()
                        .is_some_and(|value| value.ends_with(target))
            })
    })
}

const LIB: &str = "mod engine;\nmod sign;\n\npub fn top() -> u32 {\n    sign::any()\n}\n";
const SIGN: &str = "pub fn any() -> u32 {\n    1\n}\n";
const ENGINE: &str = "pub mod sign;\n\npub fn start() -> u32 {\n    sign::any()\n}\n";
const ENGINE_SIGN: &str = "pub struct Signer;\n\npub fn any() -> u32 {\n    2\n}\n";

/// The crate every case below starts from.
fn crate_files() -> Vec<(&'static str, &'static str)> {
    vec![
        ("Cargo.toml", "[package]\nname = \"demo\"\n"),
        ("src/lib.rs", LIB),
        ("src/sign.rs", SIGN),
        ("src/engine.rs", ENGINE),
        ("src/engine/sign.rs", ENGINE_SIGN),
        ("src/other.rs", "pub fn alone() {}\n"),
    ]
}

/// Every Rust file of `files`, as the core lists them in `source_files`.
fn sources(files: &[(&str, &str)]) -> Vec<String> {
    files
        .iter()
        .filter(|(path, _)| path.ends_with(".rs"))
        .map(|(path, _)| (*path).to_owned())
        .collect()
}

#[test]
fn a_request_for_one_file_still_resolves_names_declared_in_the_rest_of_the_crate() {
    let files = crate_files();
    let root = project("one-file-index", &files);
    let answer = scan(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "source_files": sources(&files)}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert!(calls(&answer, "crate::top", "crate::sign::any"));
    let reads = &answer.of("src/lib.rs")["reads"];
    assert_eq!(sha256_hex(SIGN), reads["src/sign.rs"]);
    assert_eq!(Value::Null, reads["src/sign/mod.rs"]);
    assert!(
        reads.get("src/lib.rs").is_none(),
        "a file does not read itself"
    );
}

#[test]
fn a_file_reads_the_module_file_its_lookup_found_and_the_one_it_could_have_been() {
    let files = crate_files();
    let root = project("module-target", &files);
    let answer = scan(
        &root,
        &["src/engine.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "source_files": sources(&files)}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert!(calls(
        &answer,
        "crate::engine::start",
        "crate::engine::sign::any"
    ));
    let reads = &answer.of("src/engine.rs")["reads"];
    assert_eq!(sha256_hex(ENGINE_SIGN), reads["src/engine/sign.rs"]);
    assert_eq!(Value::Null, reads["src/engine/sign/mod.rs"]);
    // The crate's root module is above every name it looked up.
    assert_eq!(sha256_hex(LIB), reads["src/lib.rs"]);
    // A module it never looked inside is not read.
    assert!(reads.get("src/sign.rs").is_none());
    assert!(reads.get("src/other.rs").is_none());
}

#[test]
fn the_manifest_is_read_by_the_whole_request_and_the_rest_of_the_crate_by_nobody() {
    let files = crate_files();
    let root = project("shared-and-unattributed", &files);
    let answer = scan(
        &root,
        &["src/other.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "source_files": sources(&files)}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(
        serde_json::json!({"Cargo.toml": sha256_hex("[package]\nname = \"demo\"\n")}),
        answer.result["reads"]
    );
    // Every crate file is read for the index; the ones `other.rs` never
    // looked inside have contributions of their own that name their reads.
    let unattributed = &answer.result["unattributed_reads"];
    assert_eq!(sha256_hex(ENGINE_SIGN), unattributed["src/engine/sign.rs"]);
    assert_eq!(sha256_hex(SIGN), unattributed["src/sign.rs"]);
    // The crate root is above `other.rs`, so `other.rs` reads it.
    assert_eq!(
        sha256_hex(LIB),
        answer.of("src/other.rs")["reads"]["src/lib.rs"]
    );
    assert!(unattributed.get("src/lib.rs").is_none());
}

#[test]
fn whether_a_file_is_test_code_is_read_from_the_module_that_declares_it() {
    let mut files = crate_files();
    let engine = "pub mod sign;\n\n#[cfg(test)]\nmod tests;\n";
    files.retain(|(path, _)| *path != "src/engine.rs");
    files.push(("src/engine.rs", engine));
    files.push(("src/engine/tests.rs", "pub fn check() {}\n"));
    let root = project("test-module", &files);
    let answer = scan(
        &root,
        &["src/engine/tests.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "source_files": sources(&files)}),
    );
    let _ = std::fs::remove_dir_all(&root);

    let contribution = answer.of("src/engine/tests.rs");
    assert!(contribution["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|node| node["kind"] == "function")
        .all(|node| node["attributes"]["test"] == true));
    assert_eq!(sha256_hex(engine), contribution["reads"]["src/engine.rs"]);
    assert_eq!(Value::Null, contribution["reads"]["src/engine/mod.rs"]);
    assert_eq!(sha256_hex(LIB), contribution["reads"]["src/lib.rs"]);
}

#[test]
fn a_lookup_of_a_member_crate_reads_that_members_files() {
    let files = vec![
        ("Cargo.toml", "[package]\nname = \"app\"\n"),
        (
            "src/lib.rs",
            "use core_lib::*;\n\npub fn c() -> u32 {\n    shared()\n}\n",
        ),
        (
            "crates/core-lib/Cargo.toml",
            "[package]\nname = \"core-lib\"\n",
        ),
        (
            "crates/core-lib/src/lib.rs",
            "pub fn shared() -> u32 {\n    1\n}\n",
        ),
    ];
    let root = project("member-lookup", &files);
    let answer = scan(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({
            "config_files": ["Cargo.toml", "crates/core-lib/Cargo.toml"],
            "source_files": sources(&files),
        }),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert!(calls(&answer, "crate::c", "core_lib::shared"));
    assert_eq!(
        sha256_hex("pub fn shared() -> u32 {\n    1\n}\n"),
        answer.of("src/lib.rs")["reads"]["crates/core-lib/src/lib.rs"]
    );
}

#[test]
fn a_crate_file_over_the_byte_cap_is_read_as_null_and_not_indexed() {
    let big = format!("pub fn any() -> u32 {{\n    1\n}}\n//{}\n", "x".repeat(200));
    let files = vec![
        (
            "src/lib.rs",
            "mod sign;\n\npub fn top() -> u32 {\n    sign::any()\n}\n",
        ),
        ("src/sign.rs", big.as_str()),
    ];
    let root = project("over-the-cap", &files);
    let answer = scan(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"source_files": sources(&files), "limits": {"max_file_bytes": 120}}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert!(!calls(&answer, "crate::top", "crate::sign::any"));
    assert_eq!(Value::Null, answer.result["input_hashes"]["src/sign.rs"]);
    assert_eq!(Value::Null, answer.of("src/lib.rs")["reads"]["src/sign.rs"]);
}

#[test]
fn without_source_files_the_index_is_the_batch_and_probes_still_read_the_disk() {
    let files = crate_files();
    let root = project("no-source-files", &files);
    let answer = scan(&root, &["src/lib.rs"], &serde_json::json!({}));
    let _ = std::fs::remove_dir_all(&root);

    // `src/sign.rs` is not indexed, but its bytes are what the lookup saw.
    assert!(!calls(&answer, "crate::top", "crate::sign::any"));
    assert_eq!(
        sha256_hex(SIGN),
        answer.of("src/lib.rs")["reads"]["src/sign.rs"]
    );
}

#[test]
fn reads_too_large_for_one_line_go_out_in_parts() {
    // Long paths make the unattributed reads of a one-file request outgrow
    // one line; each part stays within the budget and together they hold
    // every file read for the index.
    let directory = format!(
        "src/{}/{}/{}",
        "d".repeat(250),
        "e".repeat(250),
        "f".repeat(250)
    );
    let mut owned: Vec<(String, String)> =
        vec![("src/lib.rs".to_owned(), "pub fn a() {}\n".to_owned())];
    for index in 0..400 {
        owned.push((
            format!("{directory}/value_{index:04}.rs"),
            format!("pub struct Value{index};\n"),
        ));
    }
    let files: Vec<(&str, &str)> = owned
        .iter()
        .map(|(path, contents)| (path.as_str(), contents.as_str()))
        .collect();
    let root = project("parts", &files);
    let answer = scan(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"source_files": sources(&files)}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert!(
        answer.longest_line < 256_100,
        "a line of {} bytes",
        answer.longest_line
    );
    for (path, contents) in &files[1..] {
        assert_eq!(
            sha256_hex(contents),
            answer.result["unattributed_reads"][*path],
            "{path}"
        );
    }
}
