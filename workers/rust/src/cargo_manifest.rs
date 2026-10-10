//! Reading the request's Cargo manifests into the project's [`Layout`].
//!
//! Only the `name` keys of `[package]` and `[lib]` are read, without a TOML
//! parser. Each manifest is a read every file of the request depends on, so
//! it is recorded in `input_hashes` like any other.

use std::collections::BTreeMap;
use std::path::Path;

use crate::layout::Layout;
use crate::reads::{read_bounded, record_read};
use crate::source_hash::sha256_hex;

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
///
/// [`FileReads`]: crate::reads::FileReads
pub(crate) fn cargo_crates(
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
