//! Embed the hash of this crate's source so the core can spot a stale binary.

#[path = "src/source_hash.rs"]
mod source_hash;

use std::path::PathBuf;

fn main() {
    let root = PathBuf::from(
        std::env::var_os("CARGO_MANIFEST_DIR").expect("cargo sets CARGO_MANIFEST_DIR"),
    );
    let hash = source_hash::compute(&root).expect("the worker crate has a src/ directory");
    println!("cargo:rustc-env=KNOSSOS_SOURCE_HASH={hash}");
    for input in ["src", "Cargo.toml", "Cargo.lock", "build.rs"] {
        println!("cargo:rerun-if-changed={input}");
    }
}
