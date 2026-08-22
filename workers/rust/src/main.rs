//! Binary entry point: drive the JSON-RPC loop over stdio.

#![warn(missing_docs)]
#![warn(clippy::missing_docs_in_private_items)]

use std::io::{stdin, stdout, BufWriter};
use std::process::ExitCode;

/// Read requests from stdin until it ends or `shutdown` arrives.
fn main() -> ExitCode {
    let stdin = stdin().lock();
    let stdout = BufWriter::new(stdout().lock());
    match knossos_rust_worker::server::run(stdin, stdout) {
        Ok(()) => ExitCode::SUCCESS,
        Err(error) => {
            // Logs go to stderr; stdout carries protocol frames only.
            eprintln!("knossos-rust-worker: {error}");
            ExitCode::FAILURE
        }
    }
}
