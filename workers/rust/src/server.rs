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
    },
    /// A failed file, reduced to its final (diagnostic-only) contribution.
    Err(Contribution),
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
    let crates = cargo_crates(&root, &config_files);
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
    for item in &prepared {
        if let Prepared::Parsed { relative, parsed } = item {
            let module = module_path_for_file(relative, has_library_root);
            crate::visit::collect_declarations(&module, &parsed.items, &mut declarations);
        }
    }

    // Pass 3: walk and emit, in the batch's sorted order.
    let mut scanned = 0_usize;
    for item in prepared {
        let contribution = match item {
            Prepared::Err(contribution) => contribution,
            Prepared::Parsed { relative, parsed } => {
                let module = module_path_for_file(&relative, has_library_root);
                let display = module.rsplit("::").next().unwrap_or(&module).to_owned();
                let mut facts = Facts::new(&relative);
                let span = proc_macro2::Span::call_site();
                facts.node("module", &module, &display, span, span);
                crate::visit::walk(&mut facts, &module, &parsed, &frameworks, &declarations);
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
                facts.finish()
            }
        };
        emit(&serde_json::to_value(&contribution).map_err(|e| e.to_string())?);
        scanned += 1;
    }

    Ok(json!({"files_scanned": scanned, "parser": "rust.syn"}))
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
            return Prepared::Err(facts.finish());
        }
    };
    if !canonical.starts_with(root) {
        facts.diagnostic(
            "error",
            "RS_UNSCANNABLE_FILE",
            "Scan path escapes the project root.",
            1,
        );
        return Prepared::Err(facts.finish());
    }
    match std::fs::metadata(&canonical) {
        Ok(metadata) if metadata.len() > max_file_bytes => {
            facts.diagnostic(
                "error",
                "RS_UNSCANNABLE_FILE",
                "File exceeds the scan byte limit.",
                1,
            );
            return Prepared::Err(facts.finish());
        }
        Ok(_) => {}
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err(facts.finish());
        }
    }
    let source = match std::fs::read_to_string(&canonical) {
        Ok(source) => source,
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err(facts.finish());
        }
    };
    match syn::parse_file(&source) {
        Ok(parsed) => Prepared::Parsed {
            relative: relative.to_owned(),
            parsed,
        },
        Err(error) => {
            let line = error.span().start().line.max(1);
            facts.diagnostic("error", "RS_SYNTAX_ERROR", &error.to_string(), line);
            Prepared::Err(facts.finish())
        }
    }
}

/// The crate roots and names declared by the request's manifest `config_files`.
///
/// The crate name comes from a `[package]` table, read without a TOML parser
/// (the same leaf parse the PHP core gives Cargo.toml). The crate's package
/// node is attached to its library root `src/lib.rs`, or its binary root
/// `src/main.rs` when there is no library — the two files whose module path
/// is `crate` — via ordinary filesystem existence, matching where the file
/// vertices sit in the batch.
fn cargo_crates(root: &Path, config_files: &[String]) -> Vec<(String, String)> {
    let mut crates: Vec<(String, String)> = Vec::new();
    for config in config_files {
        let Ok(contents) = std::fs::read_to_string(root.join(config)) else {
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
