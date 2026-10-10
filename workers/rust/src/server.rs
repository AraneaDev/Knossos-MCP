//! The newline-delimited JSON-RPC loop. Mirrors `workers/php/src/WorkerServer.php`.

use std::collections::{BTreeMap, BTreeSet};
use std::io::{BufRead, Write};

use serde_json::{json, Value};

use crate::cargo_manifest::cargo_crates;
use crate::facts::Facts;
use crate::index::{index_project, Prepared, ProjectIndex};
use crate::layout::Layout;
use crate::params::ScanRequest;
use crate::protocol::{Contribution, Manifest};
use crate::reads::FileReads;
use crate::visit::{Declarations, TestModules};

/// Serialized bytes one `scan/input_hashes` notification carries at most, well
/// under the core's 1,000,000-byte line cap, and the same as the packaged PHP,
/// Python and TypeScript workers use.
const INPUT_HASHES_PART_BYTES: usize = 256_000;

/// The result's path maps a `scan/input_hashes` part may carry a piece of.
const READ_MAP_FIELDS: [&str; 3] = ["input_hashes", "reads", "unattributed_reads"];

/// How long the worker may go without writing during a request before it
/// sends a `scan/heartbeat`, well inside the core's inactivity timeout.
const HEARTBEAT_EVERY: std::time::Duration = std::time::Duration::from_secs(1);

/// Sends `scan/heartbeat` when the request has been quiet for
/// [`HEARTBEAT_EVERY`].
pub(crate) struct Heartbeat {
    /// When the last heartbeat went out, or the request began.
    last: std::time::Instant,
}

impl Heartbeat {
    /// Start counting from now.
    fn new() -> Self {
        Self {
            last: std::time::Instant::now(),
        }
    }

    /// Send a heartbeat through `emit` if the request has been quiet too long.
    pub(crate) fn beat(&mut self, emit: &mut dyn FnMut(&Value)) {
        if self.last.elapsed() >= HEARTBEAT_EVERY {
            emit(&json!({"jsonrpc": "2.0", "method": "scan/heartbeat"}));
            self.last = std::time::Instant::now();
        }
    }
}

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
        // Notifications go out as they are produced, so a heartbeat sent
        // while the index is built reaches the core before the request ends.
        let mut failed: Option<std::io::Error> = None;
        let result = handle(&request, &mut |notification: &Value| {
            if failed.is_none() {
                failed = write_line(&mut output, notification).err();
            }
        });
        if let Some(error) = failed {
            return Err(error);
        }
        match result {
            Ok(mut value) => {
                // The maps grow with the project's Rust files, not with the
                // batch, and nothing bounds them by the line cap: long enough
                // paths, or enough files, outgrow one frame. The shared and
                // unattributed reads travel beside an empty `input_hashes`.
                for field in READ_MAP_FIELDS {
                    for part in split_read_map(&mut value, field, INPUT_HASHES_PART_BYTES) {
                        let mut params = json!({"input_hashes": {}});
                        params[field] = part;
                        write_line(
                            &mut output,
                            &json!({
                                "jsonrpc": "2.0",
                                "method": "scan/input_hashes",
                                "params": params,
                            }),
                        )?;
                    }
                }
                write_line(
                    &mut output,
                    &json!({"jsonrpc": "2.0", "id": id, "result": value}),
                )?;
            }
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
/// `emit` receives each notification as it is produced: a `scan/contribution`
/// per scanned file, and a `scan/heartbeat` while building the declaration
/// index takes long enough that the core could take the silence for a hang.
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
///
/// The request is scanned in three passes. Every Rust file of the project is
/// read and parsed first, the requested ones and, when the request lists
/// them in `source_files`, every other one (so one unreadable file never
/// aborts the batch). The crate-wide declaration index is built from the
/// successes: the view that lets `impl` blocks attach to types in other files
/// and call targets resolve to their real module, the same whichever files
/// the request names. Only then is each requested file walked and emitted,
/// in the sorted order the batch was accepted in, with the files its facts
/// were read from (see [`FileReads`]).
fn scan(params: &Value, emit: &mut dyn FnMut(&Value)) -> Result<Value, String> {
    let request = ScanRequest::parse(params)?;
    let mut input_hashes: BTreeMap<String, Option<String>> = BTreeMap::new();
    let layout = cargo_crates(
        &request.root,
        &request.config_files,
        request.max_file_bytes,
        &mut input_hashes,
    );
    let mut heartbeat = Heartbeat::new();
    let index = index_project(&request, &layout, &mut input_hashes, &mut heartbeat, emit);
    let walked = walk_requested(
        &request,
        &layout,
        index,
        &mut input_hashes,
        &mut heartbeat,
        emit,
    );

    emit_contributions(&request, walked, input_hashes, emit)
}

/// Pass 2: walk each requested file, noting what its facts were read from.
fn walk_requested(
    request: &ScanRequest,
    layout: &Layout,
    index: ProjectIndex,
    input_hashes: &mut BTreeMap<String, Option<String>>,
    heartbeat: &mut Heartbeat,
    emit: &mut dyn FnMut(&Value),
) -> Vec<(Contribution, BTreeSet<String>)> {
    let mut reads = FileReads::new(&request.root, request.max_file_bytes, layout);
    let ProjectIndex {
        prepared,
        declarations,
        test_modules,
    } = index;
    let mut walked: Vec<(Contribution, BTreeSet<String>)> = Vec::with_capacity(prepared.len());
    for item in prepared {
        heartbeat.beat(emit);
        walked.push(match item {
            // A file that could not be scanned holds no facts to read anything for.
            Prepared::Err { contribution, .. } => (contribution, BTreeSet::new()),
            Prepared::Parsed {
                relative,
                parsed,
                content_hash,
            } => {
                let module = layout.module_of(&relative);
                let (contribution, placed) = walk_one(
                    &relative,
                    &module,
                    &parsed,
                    content_hash,
                    &request.frameworks,
                    &declarations,
                    &test_modules,
                    layout,
                );
                let lookups = declarations.take_lookups();
                let keys = reads.of_file(&relative, &module, &lookups, &placed, input_hashes);
                (contribution, keys)
            }
        });
    }

    walked
}

/// Pass 3: emit, each read carrying the value `input_hashes` holds for it,
/// and return the request's result.
fn emit_contributions(
    request: &ScanRequest,
    walked: Vec<(Contribution, BTreeSet<String>)>,
    input_hashes: BTreeMap<String, Option<String>>,
    emit: &mut dyn FnMut(&Value),
) -> Result<Value, String> {
    let mut named: BTreeSet<String> = request.relatives.iter().cloned().collect();
    let shared: BTreeMap<String, Option<String>> = request
        .config_files
        .iter()
        .filter_map(|config| Some((config.clone(), input_hashes.get(config)?.clone())))
        .collect();
    named.extend(shared.keys().cloned());
    let scanned = walked.len();
    for (mut contribution, keys) in walked {
        for key in keys {
            let value = input_hashes.get(&key).cloned().flatten();
            contribution.reads.insert(key.clone(), value);
            named.insert(key);
        }
        emit(&json!({
            "jsonrpc": "2.0",
            "method": "scan/contribution",
            "params": serde_json::to_value(&contribution).map_err(|e| e.to_string())?,
        }));
    }
    // Every other file read for the index has a contribution of its own,
    // which names what it read: nobody in this request owes those reads.
    let unattributed: BTreeMap<&String, &Option<String>> = input_hashes
        .iter()
        .filter(|(path, _)| !named.contains(*path))
        .collect();

    Ok(json!({
        "files_scanned": scanned,
        "parser": "rust.syn",
        "input_hashes": input_hashes,
        "reads": shared,
        "unattributed_reads": unattributed,
    }))
}

/// Walk one parsed file into its contribution, with the files its `mod`
/// declarations load (see [`crate::visit::walk`]).
#[allow(clippy::too_many_arguments)]
fn walk_one(
    relative: &str,
    module: &str,
    parsed: &syn::File,
    content_hash: String,
    frameworks: &[String],
    declarations: &Declarations,
    test_modules: &TestModules,
    layout: &Layout,
) -> (Contribution, BTreeSet<String>) {
    let display = module.rsplit("::").next().unwrap_or(module).to_owned();
    let mut facts = Facts::new(relative);
    facts.set_content_hash(content_hash);
    let span = proc_macro2::Span::call_site();
    facts.node("module", module, &display, span, span);
    let placed = crate::visit::walk(
        &mut facts,
        module,
        parsed,
        frameworks,
        declarations,
        test_modules,
        layout,
    );
    if let Some((root_file, crate_name)) = layout
        .crates
        .iter()
        .find(|(root_file, _)| root_file == relative)
    {
        // A library is entered by its dependents, or by a host
        // outside the repository for a cdylib; nothing in the
        // graph imports its root.
        if root_file.ends_with("src/lib.rs") {
            facts.mark_executable();
        }
        facts.node_with_attributes(
            "package",
            crate_name,
            crate_name,
            span,
            span,
            BTreeMap::new(),
        );
        let package_id = crate::facts::reference("package", crate_name);
        let module_id = crate::facts::reference("module", module);
        facts.edge("contains", &package_id, &module_id, "certain", span);
    }

    (facts.finish(), placed)
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

/// Split one path map of a result (`input_hashes`, `reads` or
/// `unattributed_reads`) into parts that each fit one frame, leaving the last
/// part in the result and returning the others, in order, to go out ahead of
/// it in `scan/input_hashes` notifications.
///
/// The result's own field marks that the worker finished reporting, so it
/// always keeps one part, `{}` when nothing was read. A single entry longer
/// than the budget still travels alone. A result without the field (any
/// method but `scan`) is left untouched.
fn split_read_map(result: &mut Value, field: &str, part_bytes: usize) -> Vec<Value> {
    let Some(Value::Object(map)) = result.get_mut(field) else {
        return Vec::new();
    };
    let mut parts: Vec<serde_json::Map<String, Value>> = Vec::new();
    let mut part = serde_json::Map::new();
    // The serialized part: its braces, less the comma its last entry lacks.
    let mut bytes = 1_usize;
    for (relative, hash) in std::mem::take(map) {
        // `"path":"<64 hex>",` or `"path":null,`
        let key_bytes =
            serde_json::to_string(&relative).map_or(relative.len() + 2, |key| key.len());
        let entry_bytes = key_bytes + if hash.is_null() { 4 } else { 66 } + 2;
        if !part.is_empty() && bytes + entry_bytes > part_bytes {
            parts.push(std::mem::take(&mut part));
            bytes = 1;
        }
        part.insert(relative, hash);
        bytes += entry_bytes;
    }
    *map = part;

    parts.into_iter().map(Value::Object).collect()
}

#[cfg(test)]
mod tests {
    use super::split_read_map;
    use serde_json::json;

    #[test]
    fn input_hashes_split_into_parts_that_fit_their_budget() {
        let hash = "a".repeat(64);
        let pair = json!({"a/1": hash, "a/2": null});
        let length = pair.to_string().len();

        let mut whole = json!({"files_scanned": 2, "input_hashes": pair});
        assert!(split_read_map(&mut whole, "input_hashes", length).is_empty());
        assert_eq!(pair, whole["input_hashes"]);

        let mut split = json!({"files_scanned": 2, "input_hashes": pair});
        assert_eq!(
            vec![json!({"a/1": hash})],
            split_read_map(&mut split, "input_hashes", length - 1)
        );
        assert_eq!(json!({"a/2": null}), split["input_hashes"]);
        assert_eq!(2, split["files_scanned"]);

        // An entry longer than the budget travels alone; keys are measured
        // escaped, as they are written.
        let long = "x".repeat(300);
        let quoted = json!({"\"": null, "b": null});
        let mut escaped = json!({"input_hashes": quoted});
        assert_eq!(
            vec![json!({"\"": null})],
            split_read_map(&mut escaped, "input_hashes", quoted.to_string().len() - 1)
        );
        let mut alone = json!({"input_hashes": {"a": null, long.clone(): hash}});
        assert_eq!(
            vec![json!({"a": null})],
            split_read_map(&mut alone, "input_hashes", 20)
        );
        assert_eq!(json!({long: hash}), alone["input_hashes"]);

        let mut empty = json!({"input_hashes": {}});
        assert!(split_read_map(&mut empty, "input_hashes", 1).is_empty());
        assert_eq!(json!({}), empty["input_hashes"]);
        let mut other = json!({"status": "bye"});
        assert!(split_read_map(&mut other, "input_hashes", 1).is_empty());
        assert_eq!(json!({"status": "bye"}), other);
    }
}
