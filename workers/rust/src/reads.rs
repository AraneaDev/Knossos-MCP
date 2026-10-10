//! What a scan reads, and what each contribution's facts were read from.
//!
//! Every file is read within the byte cap and inside the project root. Each
//! read is recorded in the result's `input_hashes` by the hash of the bytes
//! read, and each requested file names the other files its facts depend on,
//! so the core can tell which contributions a change on disk reaches.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use crate::layout::Layout;
use crate::params::assert_scannable_str;
use crate::source_hash::sha256_hex;

/// The files each requested file's facts were read from.
///
/// A file's facts depend on other files in three ways, and each is a read:
///
/// - Its module path follows from which packages are crates: a file under a
///   package's `src/` reads that package's `src/lib.rs` and `src/main.rs`,
///   whose presence decides it. So does the module each of its `mod`
///   declarations names, from the path of the file it loads.
/// - Whether it is test code follows from a `#[cfg(test)] mod name;` in a
///   module above it, so it reads every file of every module above its own.
/// - Every name its walk asked the declaration index about, found or not,
///   follows from the files of every module above that name.
///
/// "The files of a module" are every path a file there could have (see
/// [`crate::layout::module_files`]), which are exactly the files the index
/// holds there: one that is missing is recorded as `None`, so adding it
/// reaches the reader. A path the project does not hold is read from disk as
/// any other file is, within the byte cap, and recorded in `input_hashes`. Everything recorded
/// here is a direct read: what a declaring file itself read is its own.
pub(crate) struct FileReads<'a> {
    /// The canonical project root.
    root: &'a Path,
    /// The byte cap a probed file is read within.
    max_file_bytes: u64,
    /// The project's crates, see [`Layout`].
    layout: &'a Layout,
    /// The files of each module asked about so far, so each is probed once.
    by_module: BTreeMap<String, Vec<String>>,
}

impl<'a> FileReads<'a> {
    /// Nothing probed yet, for files under `root` read within `max_file_bytes`.
    pub(crate) fn new(root: &'a Path, max_file_bytes: u64, layout: &'a Layout) -> Self {
        Self {
            root,
            max_file_bytes,
            layout,
            by_module: BTreeMap::new(),
        }
    }

    /// The files `relative`, placed in `module`, read beyond itself after a
    /// walk that asked the index about `lookups` and placed the files its
    /// `mod` declarations load (`placed`). Every path returned is in
    /// `input_hashes`.
    pub(crate) fn of_file(
        &mut self,
        relative: &str,
        module: &str,
        lookups: &BTreeSet<String>,
        placed: &BTreeSet<String>,
        input_hashes: &mut BTreeMap<String, Option<String>>,
    ) -> BTreeSet<String> {
        let mut modules: BTreeSet<&str> =
            crate::layout::modules_above(module).into_iter().collect();
        for name in lookups {
            modules.extend(crate::layout::modules_above(name));
        }
        let mut keys = BTreeSet::new();
        for (directory, _) in &self.layout.packages {
            let source = format!("{directory}src/");
            if std::iter::once(relative)
                .chain(placed.iter().map(String::as_str))
                .any(|file| file.starts_with(&source))
            {
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

    /// Every path a file placed in `module` could have.
    fn of_module(&mut self, module: &str) -> &[String] {
        self.by_module.entry(module.to_owned()).or_insert_with(|| {
            let mut files = self.layout.module_files(module);
            files.retain(|file| assert_scannable_str(file).is_ok());
            files
        })
    }
}

/// Record one read for `input_hashes`: a path already recorded with a different
/// value (two differing hashes, or a hash and a failed read or absent probe)
/// becomes `None`, since at least one of those reads disagrees with discovery
/// and either may have fed facts.
pub(crate) fn record_read(
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

/// The raw bytes of one project file, or why the filesystem refused them:
/// gone, not a regular file, over the byte cap, or resolving outside the root.
///
/// Mirrors `safe_file` in `workers/python/bin/knossos_python/safe_io.py`:
/// `assert_scannable_path` only checked the path's shape, so a symlink inside
/// the project that points outside it would otherwise be resolved untouched.
/// Canonicalising and re-checking containment here, at the point the file is
/// first opened, is what actually catches that. `safe_root` canonicalises
/// `root`, so a plain `starts_with` comparison is enough.
///
/// Bytes, not a string: the hash must be of exactly what is on disk, and a
/// file that is not UTF-8 is still a file whose bytes were read. Bounded to
/// one byte past the cap, which is how the cap is enforced: a size checked
/// beforehand could belong to a file replaced before this read, and a
/// replacement must not be read unbounded.
pub(crate) fn read_safely(
    root: &Path,
    relative: &str,
    max_file_bytes: u64,
) -> Result<Vec<u8>, String> {
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

/// Read at most `max_file_bytes + 1` bytes of `path`, so a caller can tell a
/// file over the cap from one at it without reading the rest.
pub(crate) fn read_bounded(path: &Path, max_file_bytes: u64) -> std::io::Result<Vec<u8>> {
    use std::io::Read;
    let mut bytes = Vec::new();
    std::fs::File::open(path)?
        .take(max_file_bytes.saturating_add(1))
        .read_to_end(&mut bytes)?;
    Ok(bytes)
}

#[cfg(test)]
mod tests {
    use super::{read_bounded, record_read};
    use std::collections::BTreeMap;

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
