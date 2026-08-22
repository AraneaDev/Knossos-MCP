//! The newline-delimited JSON-RPC loop. Mirrors `workers/php/src/WorkerServer.php`.

use std::io::{BufRead, Write};
use std::path::{Component, Path, PathBuf};

use serde_json::{json, Value};

use crate::protocol::{Contribution, Manifest};

/// Default cap on one scanned file, overridden by `params.limits.max_file_bytes`.
const DEFAULT_MAX_FILE_BYTES: u64 = 2_000_000;

/// Default cap on files in one request, overridden by `params.limits.max_files`.
const DEFAULT_MAX_FILES: usize = 100_000;

/// Read requests until stdin ends or `shutdown` arrives, writing replies to `output`.
///
/// A malformed request becomes a JSON-RPC error reply rather than a crash: the
/// core owns the session, and a worker that dies on one bad frame takes every
/// other file in the batch with it.
///
/// # Errors
///
/// Returns the first I/O error from reading `input` or writing `output`.
pub fn run(input: impl BufRead, mut output: impl Write) -> std::io::Result<()> {
    for line in input.lines() {
        let line = line?;
        if line.trim().is_empty() {
            continue;
        }
        let request: Value = match serde_json::from_str(&line) {
            Ok(value) => value,
            Err(error) => {
                write_line(&mut output, &error_reply(&Value::Null, &error.to_string()))?;
                continue;
            }
        };
        let method = request.get("method").and_then(Value::as_str).unwrap_or("");
        if method == "cancel" {
            continue;
        }
        let id = request.get("id").cloned().unwrap_or(Value::Null);
        let mut emitted: Vec<Value> = Vec::new();
        let result = handle(&request, &mut |contribution: &Value| {
            emitted.push(json!({
                "jsonrpc": "2.0",
                "method": "scan/contribution",
                "params": contribution,
            }));
        });
        for notification in &emitted {
            write_line(&mut output, notification)?;
        }
        match result {
            Ok(value) => write_line(
                &mut output,
                &json!({"jsonrpc": "2.0", "id": id, "result": value}),
            )?,
            Err(message) => write_line(&mut output, &error_reply(&id, &message))?,
        }
        if method == "shutdown" {
            break;
        }
    }

    Ok(())
}

/// Dispatch one request, returning its `result` value or an error message.
///
/// `emit` receives each `scan/contribution` payload as it is produced.
///
/// # Errors
///
/// Returns a message for an unknown method, malformed params, or a path the
/// worker refuses.
pub fn handle(request: &Value, emit: &mut dyn FnMut(&Value)) -> Result<Value, String> {
    match request.get("method").and_then(Value::as_str) {
        Some("initialize") => serde_json::to_value(Manifest::new()).map_err(|e| e.to_string()),
        Some("scan") => scan(request.get("params").unwrap_or(&Value::Null), emit),
        Some("shutdown") => Ok(json!({"status": "bye"})),
        Some(other) => Err(format!("Unknown method: {other}")),
        None => Err("Method and object params are required.".to_owned()),
    }
}

/// Parse a bounded file set, emitting one owned contribution per input.
fn scan(params: &Value, emit: &mut dyn FnMut(&Value)) -> Result<Value, String> {
    let root = safe_root(params.get("root"))?;
    let limits = params.get("limits");
    let max_files = limit_of(limits, "max_files", DEFAULT_MAX_FILES as u64)? as usize;
    let max_file_bytes = limit_of(limits, "max_file_bytes", DEFAULT_MAX_FILE_BYTES)?;
    let files = params
        .get("files")
        .and_then(Value::as_array)
        .ok_or_else(|| "Rust scan files must be a bounded list.".to_owned())?;
    if files.len() > max_files {
        return Err("Rust scan files must be a bounded list.".to_owned());
    }

    let mut relatives: Vec<String> = Vec::with_capacity(files.len());
    for value in files {
        // A malformed path stays fatal: it names no file, so there is nothing to
        // attribute a diagnostic to, and echoing it into a contribution would
        // emit an owner key the graph rejects anyway.
        relatives.push(assert_scannable_path(value)?);
    }
    relatives.sort();

    let mut scanned = 0_usize;
    for relative in &relatives {
        let contribution = scan_one(&root, relative, max_file_bytes);
        emit(&serde_json::to_value(&contribution).map_err(|e| e.to_string())?);
        scanned += 1;
    }

    Ok(json!({"files_scanned": scanned, "parser": "rust.syn"}))
}

/// Facts for one file. Task 2 replaces the empty body with a real parse.
fn scan_one(_root: &Path, relative: &str, _max_file_bytes: u64) -> Contribution {
    Contribution::for_file(relative)
}

/// The scan root as an existing, canonical, absolute directory.
fn safe_root(value: Option<&Value>) -> Result<PathBuf, String> {
    let raw = value
        .and_then(Value::as_str)
        .filter(|text| !text.is_empty())
        .ok_or_else(|| "A project root is required.".to_owned())?;

    std::fs::canonicalize(raw).map_err(|error| format!("Unusable project root: {error}"))
}

/// One `limits` entry, or `fallback` when absent.
fn limit_of(limits: Option<&Value>, key: &str, fallback: u64) -> Result<u64, String> {
    match limits.and_then(|value| value.get(key)) {
        None | Some(Value::Null) => Ok(fallback),
        Some(value) => value
            .as_u64()
            .ok_or_else(|| format!("Limit {key} must be a non-negative integer.")),
    }
}

/// A requested path, refused unless it is relative and free of `..` segments.
///
/// This is the trust boundary. The core sends project-relative paths; anything
/// absolute or upward-traversing is a caller that is broken or hostile, and
/// neither is served by scanning it.
fn assert_scannable_path(value: &Value) -> Result<String, String> {
    let raw = value
        .as_str()
        .filter(|text| !text.is_empty())
        .ok_or_else(|| "A scan path must be a non-empty string.".to_owned())?;
    let path = Path::new(raw);
    if path.is_absolute() {
        return Err(format!("Refusing an absolute scan path: {raw}"));
    }
    for component in path.components() {
        match component {
            Component::Normal(_) | Component::CurDir => {}
            _ => return Err(format!("Refusing a traversing scan path: {raw}")),
        }
    }

    Ok(raw.replace('\\', "/"))
}

/// A JSON-RPC error reply carrying the caller's id.
fn error_reply(id: &Value, message: &str) -> Value {
    json!({"jsonrpc": "2.0", "id": id, "error": {"code": -32602, "message": message}})
}

/// Write one JSON message followed by a newline, the protocol's only framing.
fn write_line(output: &mut impl Write, message: &Value) -> std::io::Result<()> {
    serde_json::to_writer(&mut *output, message)?;
    output.write_all(b"\n")?;
    output.flush()
}
