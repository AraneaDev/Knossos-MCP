//! Validating a request's parameters.
//!
//! Every path the core sends is checked here before anything is read: this
//! is the worker's trust boundary, and it refuses exactly the shapes the
//! other packaged workers refuse.

use std::path::{Path, PathBuf};

use serde_json::Value;

/// Default cap on one scanned file, overridden by `params.limits.max_file_bytes`.
const DEFAULT_MAX_FILE_BYTES: u64 = 2_000_000;

/// Default cap on files in one request, overridden by `params.limits.max_files`.
const DEFAULT_MAX_FILES: usize = 100_000;

/// A `scan` request whose parameters passed validation.
pub(crate) struct ScanRequest {
    /// The canonical project root.
    pub(crate) root: PathBuf,
    /// The byte cap every file is read within.
    pub(crate) max_file_bytes: u64,
    /// The requested files, sorted and without duplicates.
    pub(crate) relatives: Vec<String>,
    /// Frameworks to enrich, by short name.
    pub(crate) frameworks: Vec<String>,
    /// The manifests that name the project's crates.
    pub(crate) config_files: Vec<String>,
    /// Every Rust file of the project, when the request lists them.
    pub(crate) source_files: Vec<String>,
}

impl ScanRequest {
    /// Validate a `scan` request's params, refusing the first one that is
    /// malformed.
    pub(crate) fn parse(params: &Value) -> Result<Self, String> {
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

        Ok(Self {
            root,
            max_file_bytes,
            relatives,
            frameworks,
            config_files,
            source_files,
        })
    }
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
pub(crate) fn assert_scannable_str(raw: &str) -> Result<String, String> {
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
