//! The first pass of a scan: reading every Rust file of the project and
//! building the crate-wide declaration index from it.
//!
//! Requested files are parsed and kept for the walk; every other file is
//! read and hashed, and parsed only when this worker has not indexed those
//! bytes before, so later requests of a scan stay as cheap as reading.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet, HashMap};
use std::path::Path;

use serde_json::Value;

use crate::facts::Facts;
use crate::heartbeat::Heartbeat;
use crate::layout::Layout;
use crate::nesting::{nesting_beyond, MAX_NESTING};
use crate::params::ScanRequest;
use crate::protocol::Contribution;
use crate::reads::{read_safely, record_read};
use crate::source_hash::sha256_hex;
use crate::visit::{Declarations, TestModules};

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
    /// Every name the file's visible `use` items re-export, see
    /// [`crate::visit::index_facts`].
    exports: crate::visit::ExportedNames,
    /// The field types of every struct the file declares, see
    /// [`crate::visit::index_facts`].
    fields: crate::visit::StructFields,
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
        let (exports, fields) = if indexed {
            crate::visit::index_facts(relative, module, items, layout)
        } else {
            Default::default()
        };
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
            exports,
            fields,
        }
    }
}

/// One file awaiting its walk: parsed successfully, or already reduced to a
/// diagnostic-only contribution (unreadable, oversized, escaping, or
/// unparsable — each failure costs only its own file).
pub(crate) enum Prepared {
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

/// What the first pass hands the walk.
pub(crate) struct ProjectIndex {
    /// Each requested file, parsed or reduced to its diagnostic, in order.
    pub(crate) prepared: Vec<Prepared>,
    /// The crate-wide declaration index, see [`Declarations`].
    pub(crate) declarations: Declarations,
    /// Every out-of-line `#[cfg(test)] mod name;` any file declared.
    pub(crate) test_modules: TestModules,
}

/// Pass 1: read every Rust file of the project and index it, returning the
/// requested files ready to walk with the declaration index and the test
/// modules every file declared. Without `source_files` the project is the
/// batch, as it always was. An unrequested file's tree is dropped once
/// indexed, so the whole project is never held parsed at once.
pub(crate) fn index_project(
    request: &ScanRequest,
    layout: &Layout,
    input_hashes: &mut BTreeMap<String, Option<String>>,
    heartbeat: &mut Heartbeat,
    emit: &mut dyn FnMut(&Value),
) -> ProjectIndex {
    let root = &request.root;
    let max_file_bytes = request.max_file_bytes;
    let requested: BTreeSet<&str> = request.relatives.iter().map(String::as_str).collect();
    let project: BTreeSet<&str> = request
        .source_files
        .iter()
        .map(String::as_str)
        .chain(requested.iter().copied())
        .collect();
    let mut prepared: Vec<Prepared> = Vec::with_capacity(request.relatives.len());
    let mut declarations = Declarations::new();
    let mut test_modules = TestModules::new();
    let mut memo = INDEX_MEMO.with(|memo| {
        let (built_under, memo) = std::mem::take(&mut *memo.borrow_mut());
        if built_under == *layout {
            memo
        } else {
            HashMap::new()
        }
    });
    let mut kept: IndexMemo = HashMap::new();
    for relative in project {
        heartbeat.beat(emit);
        let module = layout.module_of(relative);
        let (value, index) = if requested.contains(relative) {
            let item = prepare_one(root, relative, max_file_bytes);
            let index = match &item {
                Prepared::Parsed { parsed, .. } => {
                    Some(FileIndex::of(relative, &module, &parsed.items, layout))
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
            match read_safely(root, relative, max_file_bytes) {
                Ok(bytes) => {
                    let hash = sha256_hex(&bytes);
                    let index = match memo.remove(&(relative.to_owned(), hash.clone())) {
                        Some(index) => index,
                        None => parse_source(&bytes)
                            .ok()
                            .map(|parsed| FileIndex::of(relative, &module, &parsed.items, layout)),
                    };
                    (Some(hash), index)
                }
                Err(_) => (None, None),
            }
        };
        if let Some(index) = &index {
            declarations.add_file(&index.declarations);
            declarations.add_renames(&index.renames);
            declarations.add_exports(&index.exports);
            declarations.add_fields(&index.fields);
            test_modules.extend(index.test_modules.iter().cloned());
        }
        if let Some(hash) = &value {
            kept.insert((relative.to_owned(), hash.clone()), index);
        }
        record_read(input_hashes, relative, value);
    }
    // Only what this request indexed is kept, so the memo follows the
    // project rather than every version of it this process has seen.
    INDEX_MEMO.with(|memo| *memo.borrow_mut() = (layout.clone(), kept));

    ProjectIndex {
        prepared,
        declarations,
        test_modules,
    }
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
