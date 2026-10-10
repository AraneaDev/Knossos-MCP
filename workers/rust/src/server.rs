//! The newline-delimited JSON-RPC loop. Mirrors `workers/php/src/WorkerServer.php`.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet, HashMap};
use std::io::{BufRead, Write};
use std::path::Path;

use serde_json::{json, Value};

use crate::facts::Facts;
use crate::layout::Layout;
use crate::nesting::{nesting_beyond, MAX_NESTING};
use crate::params::{
    assert_scannable_path, assert_scannable_str, limit_of, safe_root, string_list,
    DEFAULT_MAX_FILES, DEFAULT_MAX_FILE_BYTES,
};
use crate::protocol::{Contribution, Manifest};
use crate::reads::{read_bounded, read_safely, record_read, split_read_map, FileReads};
use crate::source_hash::sha256_hex;
use crate::visit::Declarations;

/// Serialized bytes one `scan/input_hashes` notification carries at most, well
/// under the core's 1,000,000-byte line cap, and the same as the packaged PHP,
/// Python and TypeScript workers use.
const INPUT_HASHES_PART_BYTES: usize = 256_000;

/// The result's path maps a `scan/input_hashes` part may carry a piece of.
const READ_MAP_FIELDS: [&str; 3] = ["input_hashes", "reads", "unattributed_reads"];

/// How long the worker may go without writing during a request before it
/// sends a `scan/heartbeat`, well inside the core's inactivity timeout.
const HEARTBEAT_EVERY: std::time::Duration = std::time::Duration::from_secs(1);

/// What one file gives the declaration index.
#[derive(Clone, Debug)]
struct FileIndex {
    /// Every path the file declares, see [`crate::visit::declaration_paths`].
    declarations: BTreeSet<String>,
    /// Every out-of-line `#[cfg(test)] mod name;` the file declares.
    test_modules: crate::visit::TestModules,
    /// Every `mod name;` the file declares at a path other than the module
    /// it loads, see [`crate::visit::declared_renames`].
    renames: BTreeMap<String, Option<String>>,
}

impl FileIndex {
    /// The index entries of `relative`, placed in `module`. A file the index
    /// does not answer for (see [`Layout::is_indexed`]) declares nothing to
    /// it, no names and no renamed modules, though its test modules still
    /// count.
    fn of(relative: &str, module: &str, items: &[syn::Item], layout: &Layout) -> Self {
        let mut test_modules = crate::visit::TestModules::new();
        crate::visit::collect_test_modules(relative, module, items, layout, &mut test_modules);
        let indexed = layout.is_indexed(relative, module);
        Self {
            declarations: if indexed {
                crate::visit::declaration_paths(module, items)
            } else {
                BTreeSet::new()
            },
            test_modules,
            renames: if indexed {
                crate::visit::declared_renames(relative, module, items, layout)
            } else {
                BTreeMap::new()
            },
        }
    }
}

/// The index entries of every file the last request read, by the file's path
/// and the hash of its bytes; `None` for bytes that do not parse. With the
/// [`Layout`] they were collected under, both decide the entries, so a hit is
/// what parsing would give.
type IndexMemo = HashMap<(String, String), Option<FileIndex>>;

thread_local! {
    /// The worker lives across the requests of a scan, and each request
    /// indexes the whole project: parsing only bytes it has not seen keeps
    /// a large workspace's later batches as cheap as reading it. The memo is
    /// kept for the layout it was built under and dropped when it changes.
    static INDEX_MEMO: RefCell<(Layout, IndexMemo)> = RefCell::new((Layout::default(), HashMap::new()));
}

/// Sends `scan/heartbeat` when the request has been quiet for
/// [`HEARTBEAT_EVERY`].
struct Heartbeat {
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
    fn beat(&mut self, emit: &mut dyn FnMut(&Value)) {
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
    relatives.dedup();

    let frameworks = string_list(params.get("frameworks"), "frameworks")?;
    let config_files = string_list(params.get("config_files"), "config_files")?;
    for config in &config_files {
        assert_scannable_str(config)?;
    }
    let source_files = string_list(params.get("source_files"), "source_files")?;
    for source in &source_files {
        assert_scannable_str(source)?;
    }
    let mut input_hashes: BTreeMap<String, Option<String>> = BTreeMap::new();
    let layout = cargo_crates(&root, &config_files, max_file_bytes, &mut input_hashes);

    // Pass 1: read every Rust file of the project and index it. Without
    // `source_files` the project is the batch, as it always was. An
    // unrequested file's tree is dropped once indexed, so the whole project
    // is never held parsed at once.
    let requested: BTreeSet<&str> = relatives.iter().map(String::as_str).collect();
    let project: BTreeSet<&str> = source_files
        .iter()
        .map(String::as_str)
        .chain(requested.iter().copied())
        .collect();
    let mut prepared: Vec<Prepared> = Vec::with_capacity(relatives.len());
    let mut declarations = Declarations::new();
    let mut test_modules = crate::visit::TestModules::new();
    let mut memo = INDEX_MEMO.with(|memo| {
        let (built_under, memo) = std::mem::take(&mut *memo.borrow_mut());
        if built_under == layout {
            memo
        } else {
            HashMap::new()
        }
    });
    let mut kept: IndexMemo = HashMap::new();
    let mut heartbeat = Heartbeat::new();
    for relative in project {
        heartbeat.beat(emit);
        let module = layout.module_of(relative);
        let (value, index) = if requested.contains(relative) {
            let item = prepare_one(&root, relative, max_file_bytes);
            let index = match &item {
                Prepared::Parsed { parsed, .. } => {
                    Some(FileIndex::of(relative, &module, &parsed.items, &layout))
                }
                Prepared::Err { .. } => None,
            };
            let value = read_value(&item);
            prepared.push(item);
            (value, index)
        } else {
            // An unrequested file is read and hashed on every request, as
            // its read must be recorded, but parsed only when this worker
            // has not indexed those bytes in that module before.
            match read_safely(&root, relative, max_file_bytes) {
                Ok(bytes) => {
                    let hash = sha256_hex(&bytes);
                    let index = match memo.remove(&(relative.to_owned(), hash.clone())) {
                        Some(index) => index,
                        None => parse_source(&bytes)
                            .ok()
                            .map(|parsed| FileIndex::of(relative, &module, &parsed.items, &layout)),
                    };
                    (Some(hash), index)
                }
                Err(_) => (None, None),
            }
        };
        if let Some(index) = &index {
            declarations.add_file(&index.declarations);
            declarations.add_renames(&index.renames);
            test_modules.extend(index.test_modules.iter().cloned());
        }
        if let Some(hash) = &value {
            kept.insert((relative.to_owned(), hash.clone()), index);
        }
        record_read(&mut input_hashes, relative, value);
    }
    // Only what this request indexed is kept, so the memo follows the
    // project rather than every version of it this process has seen.
    INDEX_MEMO.with(|memo| *memo.borrow_mut() = (layout.clone(), kept));

    // Pass 2: walk each requested file, noting what its facts were read from.
    let mut reads = FileReads::new(&root, max_file_bytes, &layout);
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
                    &frameworks,
                    &declarations,
                    &test_modules,
                    &layout,
                );
                let lookups = declarations.take_lookups();
                let keys = reads.of_file(&relative, &module, &lookups, &placed, &mut input_hashes);
                (contribution, keys)
            }
        });
    }

    // Pass 3: emit, each read carrying the value `input_hashes` holds for it.
    let mut named: BTreeSet<String> = relatives.iter().cloned().collect();
    let shared: BTreeMap<String, Option<String>> = config_files
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
    test_modules: &crate::visit::TestModules,
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

/// What one prepared file puts in `input_hashes`: the hash of the bytes read,
/// or `None` when the filesystem refused the read. A file whose read failed is
/// not what discovery hashed right now, and a requested one's contribution
/// stands in for its facts empty: null makes the core fail the scan for a
/// discovered path rather than keep a graph without them.
fn read_value(item: &Prepared) -> Option<String> {
    match item {
        Prepared::Parsed { content_hash, .. } => Some(content_hash.clone()),
        Prepared::Err {
            read_failed: true, ..
        } => None,
        Prepared::Err { contribution, .. } => contribution.content_hash.clone(),
    }
}

/// Read, validate, and parse one file into a [`Prepared`].
///
/// Every failure is per file. Aborting the request would discard the facts
/// every other file in the batch contributes, so one unreadable or
/// unparsable file costs only its own contribution.
fn prepare_one(root: &Path, relative: &str, max_file_bytes: u64) -> Prepared {
    let mut facts = Facts::new(relative);
    let bytes = match read_safely(root, relative, max_file_bytes) {
        Ok(bytes) => bytes,
        Err(message) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &message, 1);
            return Prepared::Err {
                contribution: facts.finish(),
                read_failed: true,
            };
        }
    };
    let content_hash = sha256_hex(&bytes);
    facts.set_content_hash(content_hash.clone());
    match parse_source(&bytes) {
        Ok(parsed) => Prepared::Parsed {
            relative: relative.to_owned(),
            parsed,
            content_hash,
        },
        Err((code, message, line)) => {
            facts.diagnostic("error", code, &message, line);
            Prepared::Err {
                contribution: facts.finish(),
                read_failed: false,
            }
        }
    }
}

/// Parse a file's bytes, or the diagnostic code, message and line saying why
/// they do not parse: not UTF-8, nested past [`MAX_NESTING`], or not Rust.
fn parse_source(bytes: &[u8]) -> Result<syn::File, (&'static str, String, usize)> {
    let source =
        std::str::from_utf8(bytes).map_err(|e| ("RS_UNSCANNABLE_FILE", e.to_string(), 1))?;
    if let Some(depth) = nesting_beyond(source, MAX_NESTING) {
        return Err((
            "RS_TOO_DEEP",
            format!("Delimiters nest {depth} levels deep, past the limit of {MAX_NESTING}."),
            1,
        ));
    }
    syn::parse_file(source).map_err(|error| {
        let line = error.span().start().line.max(1);
        ("RS_SYNTAX_ERROR", error.to_string(), line)
    })
}

/// The crate roots and names declared by the request's manifest `config_files`.
///
/// The crate name comes from a `[package]` table, read without a TOML parser
/// (the same leaf parse the PHP core gives Cargo.toml), and the name other
/// crates write for its library from `[lib]`, else the crate name. The crate's package
/// node is attached to its library root `src/lib.rs`, or its binary root
/// `src/main.rs` when there is no library — the two files whose module path
/// is `crate` — via ordinary filesystem existence, matching where the file
/// vertices sit in the batch.
///
/// That existence check decides facts: whether a package node attaches, and
/// whether `src/main.rs` is the crate root or a binary beside a library, and
/// whether the files under the package's `src/` belong to a crate at all. It
/// is recorded where it is used, as a read of every file below that `src/`
/// (see [`FileReads`]), so a root that appears, disappears or changes reaches
/// exactly those files. The manifests are read by every file of the request.
fn cargo_crates(
    root: &Path,
    config_files: &[String],
    max_file_bytes: u64,
    input_hashes: &mut BTreeMap<String, Option<String>>,
) -> Layout {
    let mut cargo = Layout::default();
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
                cargo.crates.push((candidate, name.clone()));
            }
        }
        let crate_name = name.replace('-', "_");
        let library = manifest_table_name(&contents, "[lib]")
            .map_or_else(|| crate_name.clone(), |name| name.replace('-', "_"));
        let root = if directory.is_empty() {
            "crate".to_owned()
        } else {
            crate_name.clone()
        };
        cargo.libraries.insert(library, root);
        cargo.packages.push((directory, crate_name));
    }

    cargo
}

/// The crate name from a Cargo.toml `[package]` table, or `None` for a
/// virtual workspace manifest. Scoped to that one table so a `name` under
/// `[[bin]]` or `[dependencies.foo]` is never mistaken for the crate's own.
fn manifest_crate_name(contents: &str) -> Option<String> {
    manifest_table_name(contents, "[package]")
}

/// The `name` key of one Cargo.toml table (`[package]`, `[lib]`), read
/// without a TOML parser, or `None` when the table or its name is absent.
fn manifest_table_name(contents: &str, table: &str) -> Option<String> {
    let mut in_package = false;
    for line in contents.lines() {
        let trimmed = line.trim();
        if trimmed.starts_with('[') {
            in_package = trimmed.starts_with(table);
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
