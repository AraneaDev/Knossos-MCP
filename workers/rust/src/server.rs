//! The newline-delimited JSON-RPC loop. Mirrors `workers/php/src/WorkerServer.php`.

use std::collections::{BTreeMap, BTreeSet};
use std::io::{BufRead, Write};
use std::path::{Path, PathBuf};

use serde_json::{json, Value};

use crate::facts::Facts;
use crate::protocol::{Contribution, Manifest};
use crate::resolve::module_path_in_crate;
use crate::visit::Declarations;

/// Default cap on one scanned file, overridden by `params.limits.max_file_bytes`.
const DEFAULT_MAX_FILE_BYTES: u64 = 2_000_000;

/// Default cap on files in one request, overridden by `params.limits.max_files`.
const DEFAULT_MAX_FILES: usize = 100_000;

/// Serialized bytes one `scan/input_hashes` notification carries at most, well
/// under the core's 1,000,000-byte line cap, and the same as the packaged PHP,
/// Python and TypeScript workers use.
const INPUT_HASHES_PART_BYTES: usize = 256_000;

/// The result's path maps a `scan/input_hashes` part may carry a piece of.
const READ_MAP_FIELDS: [&str; 3] = ["input_hashes", "reads", "unattributed_reads"];

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
    let cargo = cargo_crates(&root, &config_files, max_file_bytes, &mut input_hashes);

    // Pass 1: read and parse every Rust file of the project. Without
    // `source_files` the project is the batch, as it always was.
    let requested: BTreeSet<&str> = relatives.iter().map(String::as_str).collect();
    let project: BTreeSet<&str> = source_files
        .iter()
        .map(String::as_str)
        .chain(requested.iter().copied())
        .collect();
    let mut prepared: Vec<Prepared> = Vec::with_capacity(relatives.len());
    let mut declarations = Declarations::new();
    let mut test_modules = crate::visit::TestModules::new();
    let mut files_by_module: BTreeMap<String, Vec<String>> = BTreeMap::new();
    for relative in project {
        let item = prepare_one(&root, relative, max_file_bytes);
        record_read(&mut input_hashes, relative, read_value(&item));
        let module = module_path_for_file(relative, &cargo.crates);
        if let Prepared::Parsed { parsed, .. } = &item {
            crate::visit::collect_declarations(&module, &parsed.items, &mut declarations);
            crate::visit::collect_test_modules(&module, &parsed.items, &mut test_modules);
        }
        files_by_module
            .entry(module)
            .or_default()
            .push(relative.to_owned());
        // An unrequested file's tree is dropped here: only its declarations
        // are kept, so the whole project is never held parsed at once.
        if requested.contains(relative) {
            prepared.push(item);
        }
    }

    // Pass 2: walk each requested file, noting what its facts were read from.
    let mut reads = FileReads {
        root: &root,
        max_file_bytes,
        packages: &cargo.packages,
        files_by_module: &files_by_module,
        by_module: BTreeMap::new(),
    };
    let mut walked: Vec<(Contribution, BTreeSet<String>)> = Vec::with_capacity(prepared.len());
    for item in prepared {
        walked.push(match item {
            // A file that could not be scanned holds no facts to read anything for.
            Prepared::Err { contribution, .. } => (contribution, BTreeSet::new()),
            Prepared::Parsed {
                relative,
                parsed,
                content_hash,
            } => {
                let module = module_path_for_file(&relative, &cargo.crates);
                let contribution = walk_one(
                    &relative,
                    &module,
                    &parsed,
                    content_hash,
                    &frameworks,
                    &declarations,
                    &test_modules,
                    &cargo.crates,
                );
                let lookups = declarations.take_lookups();
                let keys = reads.of_file(&relative, &module, &lookups, &mut input_hashes);
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
        emit(&serde_json::to_value(&contribution).map_err(|e| e.to_string())?);
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

/// Walk one parsed file into its contribution.
#[allow(clippy::too_many_arguments)]
fn walk_one(
    relative: &str,
    module: &str,
    parsed: &syn::File,
    content_hash: String,
    frameworks: &[String],
    declarations: &Declarations,
    test_modules: &crate::visit::TestModules,
    crates: &[(String, String)],
) -> Contribution {
    let display = module.rsplit("::").next().unwrap_or(module).to_owned();
    let mut facts = Facts::new(relative);
    facts.set_content_hash(content_hash);
    let span = proc_macro2::Span::call_site();
    facts.node("module", module, &display, span, span);
    crate::visit::walk(
        &mut facts,
        module,
        parsed,
        frameworks,
        declarations,
        test_modules,
    );
    if let Some((root_file, crate_name)) =
        crates.iter().find(|(root_file, _)| root_file == relative)
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

    facts.finish()
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

/// The files each requested file's facts were read from.
///
/// A file's facts depend on other files in three ways, and each is a read:
///
/// - Its module path follows from which packages are crates: a file under a
///   package's `src/` reads that package's `src/lib.rs` and `src/main.rs`,
///   whose presence decides it.
/// - Whether it is test code follows from a `#[cfg(test)] mod name;` in a
///   module above it, so it reads every file of every module above its own.
/// - Every name its walk asked the declaration index about, found or not,
///   follows from the files of every module above that name.
///
/// "The files of a module" are the ones the project holds there and every
/// path a file there could have (see [`crate::layout::module_files`]): one
/// that is missing is recorded as `None`, so adding it reaches the reader. A
/// path the project does not hold is read from disk as any other file is,
/// within the byte cap, and recorded in `input_hashes`. Everything recorded
/// here is a direct read: what a declaring file itself read is its own.
struct FileReads<'a> {
    /// The canonical project root.
    root: &'a Path,
    /// The byte cap a probed file is read within.
    max_file_bytes: u64,
    /// Every package that names its crate, see [`Cargo::packages`].
    packages: &'a [crate::layout::Package],
    /// Every file of the project read for the index, by its module path.
    files_by_module: &'a BTreeMap<String, Vec<String>>,
    /// The files of each module asked about so far, so each is probed once.
    by_module: BTreeMap<String, Vec<String>>,
}

impl FileReads<'_> {
    /// The files `relative`, placed in `module`, read beyond itself after a
    /// walk that asked the index about `lookups`. Every path returned is in
    /// `input_hashes`.
    fn of_file(
        &mut self,
        relative: &str,
        module: &str,
        lookups: &BTreeSet<String>,
        input_hashes: &mut BTreeMap<String, Option<String>>,
    ) -> BTreeSet<String> {
        let mut modules: BTreeSet<&str> =
            crate::layout::modules_above(module).into_iter().collect();
        for name in lookups {
            modules.extend(crate::layout::modules_above(name));
        }
        let mut keys = BTreeSet::new();
        for (directory, _) in self.packages {
            if relative.starts_with(&format!("{directory}src/")) {
                for root_file in ["src/lib.rs", "src/main.rs"] {
                    keys.insert(format!("{directory}{root_file}"));
                }
            }
        }
        for module in modules {
            keys.extend(self.of_module(module).iter().cloned());
        }
        keys.remove(relative);
        for key in &keys {
            if !input_hashes.contains_key(key) {
                let value = read_safely(self.root, key, self.max_file_bytes)
                    .ok()
                    .map(|bytes| sha256_hex(&bytes));
                record_read(input_hashes, key, value);
            }
        }

        keys
    }

    /// The files the project holds in `module` and every path a file there
    /// could have.
    fn of_module(&mut self, module: &str) -> &[String] {
        self.by_module.entry(module.to_owned()).or_insert_with(|| {
            let mut files = crate::layout::module_files(module, self.packages);
            files.extend(
                self.files_by_module
                    .get(module)
                    .into_iter()
                    .flatten()
                    .cloned(),
            );
            files.retain(|file| assert_scannable_str(file).is_ok());
            files.sort();
            files.dedup();
            files
        })
    }
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
    let source = match String::from_utf8(bytes) {
        Ok(source) => source,
        Err(error) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &error.to_string(), 1);
            return Prepared::Err {
                contribution: facts.finish(),
                read_failed: false,
            };
        }
    };
    if let Some(depth) = nesting_beyond(&source, MAX_NESTING) {
        facts.diagnostic(
            "error",
            "RS_TOO_DEEP",
            &format!("Delimiters nest {depth} levels deep, past the limit of {MAX_NESTING}."),
            1,
        );
        return Prepared::Err {
            contribution: facts.finish(),
            read_failed: false,
        };
    }
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
                contribution: facts.finish(),
                read_failed: false,
            }
        }
    }
}

/// The raw bytes of one project file, or why the filesystem refused them:
/// gone, not a regular file, over the byte cap, or resolving outside the root.
///
/// Mirrors `safe_file` in `workers/python/bin/worker.py`: `assert_scannable_path`
/// only checked the path's shape, so a symlink inside the project that points
/// outside it would otherwise be resolved untouched. Canonicalising and
/// re-checking containment here, at the point the file is first opened, is
/// what actually catches that. `safe_root` canonicalises `root`, so a plain
/// `starts_with` comparison is enough.
///
/// Bytes, not a string: the hash must be of exactly what is on disk, and a
/// file that is not UTF-8 is still a file whose bytes were read. Bounded to
/// one byte past the cap, which is how the cap is enforced: a size checked
/// beforehand could belong to a file replaced before this read, and a
/// replacement must not be read unbounded.
fn read_safely(root: &Path, relative: &str, max_file_bytes: u64) -> Result<Vec<u8>, String> {
    let canonical = std::fs::canonicalize(root.join(relative)).map_err(|e| e.to_string())?;
    if !canonical.starts_with(root) {
        return Err("Scan path escapes the project root.".to_owned());
    }
    if !std::fs::metadata(&canonical)
        .map_err(|e| e.to_string())?
        .is_file()
    {
        return Err("Scan path is not a regular file.".to_owned());
    }
    let bytes = read_bounded(&canonical, max_file_bytes).map_err(|e| e.to_string())?;
    if bytes.len() as u64 > max_file_bytes {
        return Err("File exceeds the scan byte limit.".to_owned());
    }

    Ok(bytes)
}

/// Deepest delimiter nesting a file may have before it is not parsed.
///
/// Parsing, walking and dropping a syntax tree recurse once per level, and
/// running out of stack aborts the process instead of unwinding, so a file
/// nested far past real code would take every other file of the scan with it.
/// The pre-scan counts `(`, `[` and `{` only. Other recursion (generic angle
/// brackets, unary chains, long `else if` chains) is covered by the 64 MB
/// worker stack, not by the pre-scan.
const MAX_NESTING: usize = 256;

/// The depth of `(`, `[` and `{` nesting in `source` once it passes `limit`,
/// or `None` while it stays within it.
///
/// A small state machine skips comments (block comments nest), string, raw
/// string and character literals, and lifetimes, so delimiters inside them do
/// not count. It may over-count on odd input; the limit sits far above real code.
fn nesting_beyond(source: &str, limit: usize) -> Option<usize> {
    let bytes = source.as_bytes();
    let mut depth = 0usize;
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'(' | b'[' | b'{' => {
                depth += 1;
                if depth > limit {
                    return Some(depth);
                }
            }
            b')' | b']' | b'}' => depth = depth.saturating_sub(1),
            b'/' if bytes.get(i + 1) == Some(&b'/') => {
                while i < bytes.len() && bytes[i] != b'\n' {
                    i += 1;
                }
                continue;
            }
            b'/' if bytes.get(i + 1) == Some(&b'*') => {
                let mut level = 1usize;
                i += 2;
                while i < bytes.len() && level > 0 {
                    if bytes[i] == b'/' && bytes.get(i + 1) == Some(&b'*') {
                        level += 1;
                        i += 2;
                    } else if bytes[i] == b'*' && bytes.get(i + 1) == Some(&b'/') {
                        level -= 1;
                        i += 2;
                    } else {
                        i += 1;
                    }
                }
                continue;
            }
            b'"' => {
                i = skip_quoted(bytes, i + 1, b'"');
                continue;
            }
            b'r' if is_raw_string_start(bytes, i) => {
                let mut hashes = 0;
                let mut j = i + 1;
                while bytes.get(j) == Some(&b'#') {
                    hashes += 1;
                    j += 1;
                }
                // `j` is the opening quote; look for a quote plus as many hashes.
                j += 1;
                while j < bytes.len() {
                    if bytes[j] == b'"' && (1..=hashes).all(|k| bytes.get(j + k) == Some(&b'#')) {
                        j += 1 + hashes;
                        break;
                    }
                    j += 1;
                }
                i = j;
                continue;
            }
            b'\'' => {
                let next = bytes.get(i + 1).copied();
                let is_lifetime = next.is_some_and(|c| c == b'_' || c.is_ascii_alphabetic())
                    && bytes.get(i + 2) != Some(&b'\'');
                if !is_lifetime {
                    i = skip_quoted(bytes, i + 1, b'\'');
                    continue;
                }
            }
            _ => {}
        }
        i += 1;
    }
    None
}

/// Whether the `r` at `at` opens a raw string (`r"`, `r#"`), not an identifier.
fn is_raw_string_start(bytes: &[u8], at: usize) -> bool {
    let after_ident = at > 0 && (bytes[at - 1] == b'_' || bytes[at - 1].is_ascii_alphanumeric());
    // A `b` prefix (`br"..."`) still opens a raw string.
    if after_ident && !(bytes[at - 1] == b'b' && !(at > 1 && is_ident_byte(bytes[at - 2]))) {
        return false;
    }
    let mut j = at + 1;
    while bytes.get(j) == Some(&b'#') {
        j += 1;
    }
    bytes.get(j) == Some(&b'"')
}

/// Whether `byte` can be part of an identifier.
fn is_ident_byte(byte: u8) -> bool {
    byte == b'_' || byte.is_ascii_alphanumeric()
}

/// The index after the `quote` that closes a literal whose body starts at
/// `from`, honouring backslash escapes. An unterminated literal ends the source.
fn skip_quoted(bytes: &[u8], from: usize, quote: u8) -> usize {
    let mut i = from;
    while i < bytes.len() {
        match bytes[i] {
            b'\\' => i += 2,
            byte if byte == quote => return i + 1,
            _ => i += 1,
        }
    }
    bytes.len()
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

/// What the request's manifests say about the project's crates.
struct Cargo {
    /// Each crate root that exists, with its package's name: `src/lib.rs`, or
    /// `src/main.rs` beside or instead of it, below a manifest that names its
    /// package.
    crates: Vec<(String, String)>,
    /// Every manifest that names its package, by directory (empty for the
    /// project root, otherwise ending in `/`) and the crate name as Rust code
    /// spells it, whether or not its roots exist.
    packages: Vec<crate::layout::Package>,
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
) -> Cargo {
    let mut cargo = Cargo {
        crates: Vec::new(),
        packages: Vec::new(),
    };
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
        cargo.packages.push((directory, name.replace('-', "_")));
    }

    cargo
}

/// Resolve one requested file's module identity.
///
/// A file under a workspace member's `src/` is rooted at that crate's name,
/// the deepest member claiming it winning; anything else is rooted at `crate`
/// as before. A binary root is disambiguated only when the same package also
/// has a library root.
fn module_path_for_file(relative: &str, crates: &[(String, String)]) -> String {
    let has_library = |directory: &str| {
        crates
            .iter()
            .any(|(root_file, _)| root_file == &format!("{directory}src/lib.rs"))
    };
    let member = crates
        .iter()
        .filter_map(|(root_file, name)| {
            let directory = root_file
                .strip_suffix("src/lib.rs")
                .or_else(|| root_file.strip_suffix("src/main.rs"))?;
            (!directory.is_empty() && relative.starts_with(&format!("{directory}src/")))
                .then_some((directory, name))
        })
        .max_by_key(|(directory, _)| directory.len());
    match member {
        Some((directory, name)) => {
            let inner = &relative[directory.len()..];
            module_path_in_crate(
                inner,
                &name.replace('-', "_"),
                has_library(directory) && inner == "src/main.rs",
            )
        }
        None => module_path_in_crate(
            relative,
            "crate",
            has_library("") && relative == "src/main.rs",
        ),
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
    use super::{read_bounded, record_read, split_read_map};
    use serde_json::json;
    use std::collections::BTreeMap;

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

#[cfg(test)]
mod nesting_tests {
    use super::nesting_beyond;

    /// Nesting limit low enough to write each case by hand.
    const LIMIT: usize = 3;

    #[test]
    fn the_limit_itself_passes_and_one_past_it_trips() {
        assert_eq!(None, nesting_beyond("(((x)))", LIMIT));
        assert_eq!(Some(4), nesting_beyond("((((x))))", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{[({x})]}", LIMIT));
    }

    #[test]
    fn a_lifetime_is_not_a_character_literal() {
        assert_eq!(None, nesting_beyond("fn f<'a>(x:&'a str){((x))}", LIMIT));
        assert_eq!(Some(4), nesting_beyond("fn f<'a>(((( x))))", LIMIT));
    }

    #[test]
    fn character_literals_hide_their_delimiters() {
        let source = "fn f(){ let a='{'; let b='\\''; let c='\"'; ((x)) }";
        assert_eq!(None, nesting_beyond(source, LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ let a='x'; ((((x)))) }", LIMIT));
    }

    #[test]
    fn string_literals_hide_their_delimiters() {
        assert_eq!(None, nesting_beyond("{ let s = \"\\\"((((\"; (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ let s = \"a\"; (((x))) }", LIMIT));
    }

    #[test]
    fn raw_strings_hide_their_delimiters() {
        assert_eq!(None, nesting_beyond("{ r#\"((\"((\"#; (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ br\"((((\"; (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ r#\"a\"#; (((x))) }", LIMIT));
    }

    #[test]
    fn an_identifier_r_does_not_open_a_raw_string() {
        assert_eq!(
            Some(4),
            nesting_beyond("{ let r = 1; let var = r; (((x))) }", LIMIT)
        );
    }

    #[test]
    fn comments_hide_their_delimiters_and_block_comments_nest() {
        assert_eq!(None, nesting_beyond("{ // ((((\n (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ /* ((((( */ (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ /* /* ((( */ ((( */ (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("/* a */ ((((x))))", LIMIT));
    }

    #[test]
    fn unbalanced_closers_do_not_go_below_zero() {
        assert_eq!(None, nesting_beyond("))))))(((x)))", LIMIT));
        assert_eq!(None, nesting_beyond("}}}}{((x))", LIMIT));
    }
}
