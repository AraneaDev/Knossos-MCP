//! The newline-delimited JSON-RPC loop. Mirrors `workers/php/src/WorkerServer.php`.

use std::collections::BTreeMap;
use std::io::{BufRead, Write};
use std::path::{Path, PathBuf};

use serde_json::{json, Value};

use crate::facts::Facts;
use crate::protocol::{Contribution, Manifest};
use crate::resolve::{module_path, module_path_with_binary_root};
use crate::visit::Declarations;

/// Default cap on one scanned file, overridden by `params.limits.max_file_bytes`.
const DEFAULT_MAX_FILE_BYTES: u64 = 2_000_000;

/// Default cap on files in one request, overridden by `params.limits.max_files`.
const DEFAULT_MAX_FILES: usize = 100_000;

/// Serialized bytes one `scan/input_hashes` notification carries at most, well
/// under the core's 1,000,000-byte line cap, and the same as the packaged PHP,
/// Python and TypeScript workers use.
const INPUT_HASHES_PART_BYTES: usize = 256_000;

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
            Ok(mut value) => {
                // The map is bounded by the batch and the crates probed, but
                // not by the line cap: long enough paths outgrow one frame.
                for part in split_input_hashes(&mut value, INPUT_HASHES_PART_BYTES) {
                    write_line(
                        &mut output,
                        &json!({
                            "jsonrpc": "2.0",
                            "method": "scan/input_hashes",
                            "params": {"input_hashes": part},
                        }),
                    )?;
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

/// One file awaiting its walk: parsed successfully, or already reduced to a
/// diagnostic-only contribution (unreadable, oversized, escaping, or
/// unparsable — each failure costs only its own file).
enum Prepared {
    /// Read, validated, and parsed; ready to walk.
    Parsed {
        /// Project-relative path.
        relative: String,
        /// The parsed syntax tree.
        parsed: syn::File,
        /// SHA-256 hex of the raw bytes `parsed` came from.
        content_hash: String,
    },
    /// A failed file, reduced to its final (diagnostic-only) contribution.
    Err {
        /// Project-relative path, kept alongside the contribution so pass 3
        /// can attribute `input_hashes` without parsing it back out of the
        /// owner key.
        relative: String,
        /// The diagnostic-only contribution itself.
        contribution: Contribution,
        /// Whether the filesystem refused the read (gone, not a regular file,
        /// over the byte cap, or resolving outside the root), as opposed to
        /// bytes that were read and failed to parse.
        read_failed: bool,
    },
}

/// Parse a bounded file set, emitting one owned contribution per input.
///
/// The request is scanned in three passes: every file is read and parsed
/// first (so one unreadable file never aborts the batch), then the scan-wide
/// declaration index is built from the successes — the cross-file view that
/// lets `impl` blocks attach to types in other files and call targets resolve
/// to their real module — and only then is each file walked and emitted, in
/// the sorted order the batch was accepted in.
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
        // emit an owner id the graph rejects anyway.
        relatives.push(assert_scannable_path(value)?);
    }
    relatives.sort();

    let frameworks = string_list(params.get("frameworks"), "frameworks")?;
    let config_files = string_list(params.get("config_files"), "config_files")?;
    for config in &config_files {
        assert_scannable_str(config)?;
    }
    let mut input_hashes: BTreeMap<String, Option<String>> = BTreeMap::new();
    let crates = cargo_crates(&root, &config_files, max_file_bytes, &mut input_hashes);
    let has_library_root = crates
        .iter()
        .any(|(root_file, _)| root_file.ends_with("src/lib.rs"));

    // Pass 1: read, validate, and parse every file.
    let mut prepared: Vec<Prepared> = Vec::with_capacity(relatives.len());
    for relative in &relatives {
        prepared.push(prepare_one(&root, relative, max_file_bytes));
    }

    // Pass 2: the scan-wide declaration index, scoped to this request's
    // files the same way the Python worker's module index is scoped to its
    // batch. A cached (unrequested) file's declarations are simply absent,
    // so a batch that excludes a type's declaring file degrades to the old
    // per-file behaviour without ever guessing.
    let mut declarations = Declarations::new();
    let mut test_modules = crate::visit::TestModules::new();
    for item in &prepared {
        if let Prepared::Parsed {
            relative, parsed, ..
        } = item
        {
            let module = module_path_for_file(relative, has_library_root);
            crate::visit::collect_declarations(&module, &parsed.items, &mut declarations);
            crate::visit::collect_test_modules(&module, &parsed.items, &mut test_modules);
        }
    }

    // Pass 3: walk and emit, in the batch's sorted order.
    let mut scanned = 0_usize;
    for item in prepared {
        let (relative, contribution) = match item {
            Prepared::Err {
                relative,
                contribution,
                read_failed,
            } => {
                if read_failed {
                    // The file is not what discovery hashed right now, and the
                    // contribution standing in for its facts is empty: null
                    // makes the core fail the scan for a discovered path rather
                    // than keep a graph without them.
                    record_read(&mut input_hashes, &relative, None);
                }
                (relative, contribution)
            }
            Prepared::Parsed {
                relative,
                parsed,
                content_hash,
            } => {
                let module = module_path_for_file(&relative, has_library_root);
                let display = module.rsplit("::").next().unwrap_or(&module).to_owned();
                let mut facts = Facts::new(&relative);
                facts.set_content_hash(content_hash);
                let span = proc_macro2::Span::call_site();
                facts.node("module", &module, &display, span, span);
                crate::visit::walk(
                    &mut facts,
                    &module,
                    &parsed,
                    &frameworks,
                    &declarations,
                    &test_modules,
                );
                if let Some((_, crate_name)) =
                    crates.iter().find(|(root_file, _)| root_file == &relative)
                {
                    facts.node_with_attributes(
                        "package",
                        crate_name,
                        crate_name,
                        span,
                        span,
                        BTreeMap::new(),
                    );
                    let package_id = crate::facts::reference("package", crate_name);
                    let module_id = crate::facts::reference("module", &module);
                    facts.edge("contains", &package_id, &module_id, "certain", span);
                }
                (relative, facts.finish())
            }
        };
        // The relative path comes from the `Prepared` item itself, not by
        // parsing it back out of the contribution's owner key, so a future
        // owner key format change can never desync this map from what was
        // actually read.
        if let Some(hash) = &contribution.content_hash {
            record_read(&mut input_hashes, &relative, Some(hash.clone()));
        }
        emit(&serde_json::to_value(&contribution).map_err(|e| e.to_string())?);
        scanned += 1;
    }

    Ok(json!({
        "files_scanned": scanned,
        "parser": "rust.syn",
        "input_hashes": input_hashes,
    }))
}

/// Record one read for `input_hashes`: a path already recorded with a different
/// value (two differing hashes, or a hash and a failed read or absent probe)
/// becomes `None`, since at least one of those reads disagrees with discovery
/// and either may have fed facts.
fn record_read(
    input_hashes: &mut BTreeMap<String, Option<String>>,
    relative: &str,
    value: Option<String>,
) {
    let value = match input_hashes.get(relative) {
        Some(existing) if *existing != value => None,
        _ => value,
    };
    input_hashes.insert(relative.to_owned(), value);
}

/// Read, validate, and parse one file into a [`Prepared`].
///
/// Every failure is per file. Aborting the request would discard the facts
/// every other file in the batch contributes, so one unreadable or
/// unparsable file costs only its own contribution.
fn prepare_one(root: &Path, relative: &str, max_file_bytes: u64) -> Prepared {
    let mut facts = Facts::new(relative);
    let joined = root.join(relative);
    // Mirrors `safe_file` in `workers/python/bin/worker.py`: `assert_scannable_path`
    // only checked the path's shape, so a symlink inside the project that points
    // outside it would otherwise be resolved untouched. Canonicalising and
    // re-checking containment here, at the point the file is first opened, is
    // what actually catches that. `safe_root` canonicalises `root`, so a plain
    // `starts_with` comparison is enough.
    let canonical = match std::fs::canonicalize(&joined) {
        Ok(canonical) => canonical,
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: true,
            };
        }
    };
    if !canonical.starts_with(root) {
        facts.diagnostic(
            "error",
            "RS_UNSCANNABLE_FILE",
            "Scan path escapes the project root.",
            1,
        );
        return Prepared::Err {
            relative: relative.to_owned(),
            contribution: facts.finish(),
            read_failed: true,
        };
    }
    match std::fs::metadata(&canonical) {
        Ok(metadata) if !metadata.is_file() => {
            facts.diagnostic(
                "error",
                "RS_UNSCANNABLE_FILE",
                "Scan path is not a regular file.",
                1,
            );
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: true,
            };
        }
        Ok(_) => {}
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: true,
            };
        }
    }
    // Read bytes, not a string: the hash must be of exactly what is on disk,
    // and a file that is not UTF-8 is still a file whose bytes were read.
    // Bounded to one byte past the cap, which is how the cap is enforced: a
    // size checked beforehand could belong to a file replaced before this
    // read, and a replacement must not be read unbounded.
    let bytes = match read_bounded(&canonical, max_file_bytes) {
        Ok(bytes) if bytes.len() as u64 > max_file_bytes => {
            facts.diagnostic(
                "error",
                "RS_UNSCANNABLE_FILE",
                "File exceeds the scan byte limit.",
                1,
            );
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: true,
            };
        }
        Ok(bytes) => bytes,
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: true,
            };
        }
    };
    let content_hash = sha256_hex(&bytes);
    facts.set_content_hash(content_hash.clone());
    let source = match String::from_utf8(bytes) {
        Ok(source) => source,
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: false,
            };
        }
    };
    match syn::parse_file(&source) {
        Ok(parsed) => Prepared::Parsed {
            relative: relative.to_owned(),
            parsed,
            content_hash,
        },
        Err(error) => {
            let line = error.span().start().line.max(1);
            facts.diagnostic("error", "RS_SYNTAX_ERROR", &error.to_string(), line);
            Prepared::Err {
                relative: relative.to_owned(),
                contribution: facts.finish(),
                read_failed: false,
            }
        }
    }
}

/// Read at most `max_file_bytes + 1` bytes of `path`, so a caller can tell a
/// file over the cap from one at it without reading the rest.
fn read_bounded(path: &Path, max_file_bytes: u64) -> std::io::Result<Vec<u8>> {
    use std::io::Read;
    let mut bytes = Vec::new();
    std::fs::File::open(path)?
        .take(max_file_bytes.saturating_add(1))
        .read_to_end(&mut bytes)?;
    Ok(bytes)
}

/// Lowercase SHA-256 hex, the form discovery records in the core.
fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    use std::fmt::Write;
    Sha256::digest(bytes)
        .iter()
        .fold(String::with_capacity(64), |mut hex, byte| {
            let _ = write!(hex, "{byte:02x}");
            hex
        })
}

/// The crate roots and names declared by the request's manifest `config_files`.
///
/// The crate name comes from a `[package]` table, read without a TOML parser
/// (the same leaf parse the PHP core gives Cargo.toml). The crate's package
/// node is attached to its library root `src/lib.rs`, or its binary root
/// `src/main.rs` when there is no library — the two files whose module path
/// is `crate` — via ordinary filesystem existence, matching where the file
/// vertices sit in the batch.
///
/// That existence check decides facts: whether a package node attaches, and
/// whether `src/main.rs` is the crate root or a binary beside a library. A
/// candidate the check finds absent, or not a regular file, is recorded in
/// `input_hashes` as `None`, so a discovered root that was briefly missing
/// while this ran fails the scan instead of leaving the package unattached in
/// a graph reported fresh. A stable tree is unaffected: discovery never reports
/// a path that is absent. A candidate found present records nothing here,
/// because this check reads no bytes of it: a present root the batch requests
/// is hashed when it is read, and one the batch does not request carries no
/// facts from this request.
fn cargo_crates(
    root: &Path,
    config_files: &[String],
    max_file_bytes: u64,
    input_hashes: &mut BTreeMap<String, Option<String>>,
) -> Vec<(String, String)> {
    let mut crates: Vec<(String, String)> = Vec::new();
    for config in config_files {
        // The manifest names the crate, so its bytes feed facts: recorded by
        // the hash of the raw bytes read, or null when the read failed or went
        // over the cap. Discovery hashes a Cargo.toml as a project unit, so
        // the core checks the entry.
        let bytes = match read_bounded(&root.join(config), max_file_bytes) {
            Ok(bytes) if bytes.len() as u64 <= max_file_bytes => bytes,
            _ => {
                record_read(input_hashes, config, None);
                continue;
            }
        };
        record_read(input_hashes, config, Some(sha256_hex(&bytes)));
        let Ok(contents) = String::from_utf8(bytes) else {
            continue;
        };
        let Some(name) = manifest_crate_name(&contents) else {
            continue;
        };
        let directory = match config.rsplit_once('/') {
            Some((directory, _)) => format!("{directory}/"),
            None => String::new(),
        };
        for candidate in [
            format!("{directory}src/lib.rs"),
            format!("{directory}src/main.rs"),
        ] {
            if root.join(&candidate).is_file() {
                crates.push((candidate, name.clone()));
            } else {
                record_read(input_hashes, &candidate, None);
            }
        }
    }

    crates
}

/// Resolve one requested file's module identity, disambiguating a binary
/// root only when the same Cargo package also has a library root.
fn module_path_for_file(relative: &str, has_library_root: bool) -> String {
    let binary_root = has_library_root && relative == "src/main.rs";
    if binary_root {
        module_path_with_binary_root(relative, true)
    } else {
        module_path(relative)
    }
}

/// The crate name from a Cargo.toml `[package]` table, or `None` for a
/// virtual workspace manifest. Scoped to that one table so a `name` under
/// `[[bin]]` or `[dependencies.foo]` is never mistaken for the crate's own.
fn manifest_crate_name(contents: &str) -> Option<String> {
    let mut in_package = false;
    for line in contents.lines() {
        let trimmed = line.trim();
        if trimmed.starts_with('[') {
            in_package = trimmed.starts_with("[package]");
            continue;
        }
        if !in_package {
            continue;
        }
        let Some(after_name) = trimmed.strip_prefix("name") else {
            continue;
        };
        let Some(value) = after_name.trim_start().strip_prefix('=') else {
            continue;
        };
        let value = value.trim();
        let name = value
            .strip_prefix('"')
            .and_then(|rest| rest.split('"').next())
            .or_else(|| {
                value
                    .strip_prefix('\'')
                    .and_then(|rest| rest.split('\'').next())
            })
            .unwrap_or("");
        if !name.is_empty() {
            return Some(name.to_owned());
        }
    }

    None
}

/// A bounded list of non-empty strings from a params field.
fn string_list(value: Option<&Value>, name: &str) -> Result<Vec<String>, String> {
    let Some(value) = value else {
        return Ok(Vec::new());
    };
    if value.is_null() {
        return Ok(Vec::new());
    }
    let Some(items) = value.as_array() else {
        return Err(format!("{name} must be a list of non-empty strings."));
    };
    let mut out = Vec::with_capacity(items.len());
    for item in items {
        let text = item
            .as_str()
            .filter(|text| !text.is_empty())
            .ok_or_else(|| format!("{name} must be a list of non-empty strings."))?;
        out.push(text.to_owned());
    }

    Ok(out)
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

/// A requested path, refused unless it is a normalized, project-relative path.
///
/// This is the trust boundary. The core sends project-relative paths; anything
/// absolute, NUL-containing, or backslash-containing is a caller that is broken
/// or hostile, and neither is served by scanning it. Backslashes are refused
/// outright rather than translated to `/`: on this platform `\` is not a path
/// separator, so `Path::components()` would parse `..\escape.rs` as one opaque
/// `Normal` segment, passing every structural check, and only turn into a real
/// `../escape.rs` traversal once the caller normalizes it afterwards. For the
/// same reason the segment check below splits the raw string itself rather than
/// walking `Path::components()`: that iterator silently collapses a leading
/// `./` and a doubled `/` before a `.` or empty segment would ever be seen,
/// which would let `./x` and `x//y` slip past unnoticed. This mirrors
/// `assert_scannable_path` in `workers/python/bin/worker.py`: the two workers
/// must refuse exactly the same shapes.
fn assert_scannable_path(value: &Value) -> Result<String, String> {
    let raw = value
        .as_str()
        .filter(|text| !text.is_empty())
        .ok_or_else(|| "A scan path must be a non-empty string.".to_owned())?;

    assert_scannable_str(raw)
}

/// The string form of [`assert_scannable_path`], for values already known to
/// be strings.
fn assert_scannable_str(raw: &str) -> Result<String, String> {
    if raw.contains('\0') || raw.contains('\\') {
        return Err(format!("Refusing an unnormalized scan path: {raw}"));
    }
    if Path::new(raw).is_absolute() {
        return Err(format!("Refusing an absolute scan path: {raw}"));
    }
    if raw
        .split('/')
        .any(|segment| matches!(segment, "" | "." | ".."))
    {
        return Err(format!("Refusing an unsafe scan path: {raw}"));
    }

    Ok(raw.to_owned())
}

/// Split a result's `input_hashes` object into parts that each fit one frame,
/// leaving the last part in the result and returning the others, in order, to
/// go out ahead of it as `scan/input_hashes` notifications.
///
/// The result's own field marks that the worker finished reporting, so it
/// always keeps one part, `{}` when nothing was read. A single entry longer
/// than the budget still travels alone. A result without the field (any
/// method but `scan`) is left untouched.
fn split_input_hashes(result: &mut Value, part_bytes: usize) -> Vec<Value> {
    let Some(Value::Object(map)) = result.get_mut("input_hashes") else {
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

#[cfg(test)]
mod tests {
    use super::{read_bounded, record_read, split_input_hashes};
    use serde_json::json;
    use std::collections::BTreeMap;

    #[test]
    fn input_hashes_split_into_parts_that_fit_their_budget() {
        let hash = "a".repeat(64);
        let pair = json!({"a/1": hash, "a/2": null});
        let length = pair.to_string().len();

        let mut whole = json!({"files_scanned": 2, "input_hashes": pair});
        assert!(split_input_hashes(&mut whole, length).is_empty());
        assert_eq!(pair, whole["input_hashes"]);

        let mut split = json!({"files_scanned": 2, "input_hashes": pair});
        assert_eq!(
            vec![json!({"a/1": hash})],
            split_input_hashes(&mut split, length - 1)
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
            split_input_hashes(&mut escaped, quoted.to_string().len() - 1)
        );
        let mut alone = json!({"input_hashes": {"a": null, long.clone(): hash}});
        assert_eq!(vec![json!({"a": null})], split_input_hashes(&mut alone, 20));
        assert_eq!(json!({long: hash}), alone["input_hashes"]);

        let mut empty = json!({"input_hashes": {}});
        assert!(split_input_hashes(&mut empty, 1).is_empty());
        assert_eq!(json!({}), empty["input_hashes"]);
        let mut other = json!({"status": "bye"});
        assert!(split_input_hashes(&mut other, 1).is_empty());
        assert_eq!(json!({"status": "bye"}), other);
    }

    #[test]
    fn repeated_reads_that_agree_keep_their_value() {
        let mut map = BTreeMap::new();
        record_read(&mut map, "src/lib.rs", Some("a".to_owned()));
        record_read(&mut map, "src/lib.rs", Some("a".to_owned()));
        record_read(&mut map, "src/gone.rs", None);
        record_read(&mut map, "src/gone.rs", None);

        assert_eq!(Some(&Some("a".to_owned())), map.get("src/lib.rs"));
        assert_eq!(Some(&None), map.get("src/gone.rs"));
    }

    #[test]
    fn reads_that_disagree_become_null_in_either_order() {
        let mut map = BTreeMap::new();
        record_read(&mut map, "probe-then-read.rs", None);
        record_read(&mut map, "probe-then-read.rs", Some("a".to_owned()));
        record_read(&mut map, "read-then-probe.rs", Some("a".to_owned()));
        record_read(&mut map, "read-then-probe.rs", None);
        record_read(&mut map, "two-hashes.rs", Some("a".to_owned()));
        record_read(&mut map, "two-hashes.rs", Some("b".to_owned()));
        record_read(&mut map, "two-hashes.rs", Some("a".to_owned()));

        assert_eq!(Some(&None), map.get("probe-then-read.rs"));
        assert_eq!(Some(&None), map.get("read-then-probe.rs"));
        assert_eq!(Some(&None), map.get("two-hashes.rs"));
    }

    #[test]
    fn a_bounded_read_stops_one_byte_past_the_cap() {
        let path = std::env::temp_dir().join("knossos-rust-read-bounded.rs");
        std::fs::write(&path, b"0123456789").unwrap();

        let over = read_bounded(&path, 4).unwrap();
        let at = read_bounded(&path, 10).unwrap();
        let _ = std::fs::remove_file(&path);

        assert_eq!(b"01234".to_vec(), over);
        assert_eq!(b"0123456789".to_vec(), at);
    }
}
