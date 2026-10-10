//! The hash of the source a worker binary is built from.
//!
//! `build.rs` includes this file to embed the hash at compile time, and the
//! core computes the same value over its checkout (`WorkerSourceHash` in PHP)
//! to tell a binary built from older source apart from a fresh one. Both follow
//! one definition, held together by a shared fixture and golden value:
//!
//! - The inputs are `Cargo.toml`, `Cargo.lock` and `build.rs` at the crate
//!   root when they are regular files, plus every regular file below `src/`.
//!   A symlinked directory below `src/` is not descended into.
//! - Each input is named by its path relative to the crate root, with `/`
//!   separators, and the names are sorted by their bytes.
//! - The hash is the lowercase hex SHA-256 of, for each input in that order,
//!   its name, a NUL byte, the lowercase hex SHA-256 of its bytes, and `\n`.
//! - A crate root without a `src/` directory has no hash.

use std::fmt::Write as _;
use std::fs;
use std::path::Path;

use sha2::{Digest, Sha256};

/// The crate-root files that feed the build besides `src/`.
const ROOT_INPUTS: [&str; 3] = ["Cargo.toml", "Cargo.lock", "build.rs"];

/// The source hash of the crate rooted at `root`, or `None` without a `src/`.
#[must_use]
pub fn compute(root: &Path) -> Option<String> {
    if !root.join("src").is_dir() {
        return None;
    }
    let mut inputs: Vec<String> = ROOT_INPUTS
        .iter()
        .filter(|name| root.join(name).is_file())
        .map(|name| (*name).to_owned())
        .collect();
    collect(root, "src", &mut inputs);
    inputs.sort_unstable();

    let mut digest = Sha256::new();
    for name in &inputs {
        let bytes = fs::read(root.join(name)).ok()?;
        digest.update(name.as_bytes());
        digest.update([0]);
        digest.update(sha256_hex(&bytes).as_bytes());
        digest.update(b"\n");
    }
    Some(hex(&digest.finalize()))
}

/// Add every regular file below `relative` to `inputs`, by relative name.
fn collect(root: &Path, relative: &str, inputs: &mut Vec<String>) {
    let Ok(entries) = fs::read_dir(root.join(relative)) else {
        return;
    };
    for entry in entries.flatten() {
        let name = format!("{relative}/{}", entry.file_name().to_string_lossy());
        let Ok(kind) = entry.file_type() else {
            continue;
        };
        if kind.is_dir() {
            collect(root, &name, inputs);
        } else if root.join(&name).is_file() {
            inputs.push(name);
        }
    }
}

/// Lowercase SHA-256 hex of `bytes`, the form discovery records in the core.
#[must_use]
pub(crate) fn sha256_hex(bytes: &[u8]) -> String {
    hex(&Sha256::digest(bytes))
}

/// Lowercase hex of `bytes`.
fn hex(bytes: &[u8]) -> String {
    bytes.iter().fold(String::new(), |mut out, byte| {
        let _ = write!(out, "{byte:02x}");
        out
    })
}
