//! The source hash the binary carries, held to the definition the core shares.

use std::path::{Path, PathBuf};

use knossos_rust_worker::protocol::{Manifest, SOURCE_HASH};
use knossos_rust_worker::source_hash::compute;

/// This crate's directory, the tree `build.rs` hashed.
fn crate_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
}

/// The fixture tree and golden value the core's PHP test reads as well.
fn fixture() -> (PathBuf, String) {
    let fixtures = crate_root().join("../../tests/Fixtures");
    let expected = std::fs::read_to_string(fixtures.join("worker-source-hash.sha256"))
        .expect("golden source hash is missing");
    (
        fixtures.join("worker-source-hash"),
        expected.trim().to_owned(),
    )
}

#[test]
fn the_embedded_hash_is_the_hash_of_the_tree_it_was_built_from() {
    assert_eq!(Some(SOURCE_HASH.to_owned()), compute(&crate_root()));
}

#[test]
fn the_manifest_announces_the_embedded_hash() {
    assert_eq!(SOURCE_HASH, Manifest::new().source_hash);
    assert_eq!(64, SOURCE_HASH.len());
    assert!(SOURCE_HASH
        .bytes()
        .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte)));
}

#[test]
fn the_fixture_hashes_to_the_value_the_core_agrees_on() {
    let (tree, expected) = fixture();
    assert_eq!(Some(expected), compute(&tree));
}

#[test]
fn a_tree_without_sources_has_no_hash() {
    assert_eq!(None, compute(Path::new("/nonexistent/knossos-rust-worker")));
}
