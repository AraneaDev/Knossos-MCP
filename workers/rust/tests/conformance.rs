//! End-to-end protocol tests driving the worker over its real stdio loop.

use std::io::Cursor;

use knossos_rust_worker::server::run;

/// Drive the worker with newline-delimited requests and return its replies.
fn exchange(requests: &[&str]) -> Vec<serde_json::Value> {
    let input = Cursor::new(requests.join("\n").into_bytes());
    let mut output: Vec<u8> = Vec::new();
    run(input, &mut output).expect("worker loop failed");
    String::from_utf8(output)
        .expect("worker wrote non-UTF-8")
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).expect("worker wrote non-JSON stdout"))
        .collect()
}

#[test]
fn initialize_returns_the_knossos_rust_manifest() {
    let replies = exchange(&[r#"{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}"#]);

    assert_eq!(1, replies.len());
    let manifest = &replies[0]["result"];
    assert_eq!("knossos.rust", manifest["id"]);
    assert_eq!("1.0", manifest["protocol_version"]);
    assert_eq!("1.0", manifest["output_schema_version"]);
    assert_eq!(serde_json::json!(["rust"]), manifest["languages"]);
    assert_eq!(serde_json::json!(["rs"]), manifest["file_extensions"]);
    assert_eq!(serde_json::json!(["partial_ast"]), manifest["capabilities"]);
}

#[test]
fn an_empty_scan_emits_no_contributions() {
    let root = std::env::temp_dir();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "scan",
        "params": {"root": root.to_str().unwrap(), "files": []},
    });
    let replies = exchange(&[&request.to_string()]);

    assert_eq!(1, replies.len(), "an empty scan must emit no contributions");
    assert_eq!(0, replies[0]["result"]["files_scanned"]);
}

#[test]
fn an_unknown_method_is_a_json_rpc_error_not_a_crash() {
    let replies = exchange(&[r#"{"jsonrpc":"2.0","id":3,"method":"nope","params":{}}"#]);

    assert_eq!(-32602, replies[0]["error"]["code"]);
    assert_eq!(3, replies[0]["id"]);
}

#[test]
fn shutdown_replies_then_stops_reading() {
    let replies = exchange(&[
        r#"{"jsonrpc":"2.0","id":4,"method":"shutdown","params":{}}"#,
        r#"{"jsonrpc":"2.0","id":5,"method":"initialize","params":{}}"#,
    ]);

    assert_eq!(1, replies.len(), "nothing may be read after shutdown");
    assert_eq!("bye", replies[0]["result"]["status"]);
}

#[test]
fn cancel_is_a_notification_with_no_reply() {
    let replies = exchange(&[r#"{"jsonrpc":"2.0","method":"cancel","params":{"id":1}}"#]);

    assert!(replies.is_empty(), "cancel must not be answered");
}

#[test]
fn a_scan_outside_the_root_is_refused() {
    let root = std::env::temp_dir();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 6,
        "method": "scan",
        "params": {"root": root.to_str().unwrap(), "files": ["../escape.rs"]},
    });
    let replies = exchange(&[&request.to_string()]);

    assert_eq!(-32602, replies[0]["error"]["code"]);
}
