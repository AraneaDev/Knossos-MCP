//! Knossos Rust scanner worker.
//!
//! Parses target files with `syn`. It never compiles, executes, or resolves
//! dependencies of the project it scans: no `rustc`, no `cargo metadata`, no
//! build scripts. See `docs/reference/scanner-protocol-v1.md` for the contract.

#![warn(missing_docs)]
#![warn(clippy::missing_docs_in_private_items)]

pub mod protocol;
pub mod server;
