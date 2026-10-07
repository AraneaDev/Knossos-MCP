//! Binary entry point: drive the JSON-RPC loop over stdio.

#![warn(missing_docs)]
#![warn(clippy::missing_docs_in_private_items)]

use std::io::{stdin, stdout, BufWriter};
use std::process::ExitCode;

/// Stack for the worker thread. The main thread's default 8 MB is what a deeply
/// nested file used to overflow.
const WORKER_STACK_BYTES: usize = 64 * 1024 * 1024;

/// Read requests from stdin until it ends or `shutdown` arrives.
fn main() -> ExitCode {
    let worker = std::thread::Builder::new()
        .stack_size(WORKER_STACK_BYTES)
        .spawn(|| {
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
        });
    match worker.map(std::thread::JoinHandle::join) {
        Ok(Ok(code)) => code,
        Ok(Err(panic)) => std::panic::resume_unwind(panic),
        Err(error) => {
            eprintln!("knossos-rust-worker: cannot start worker thread: {error}");
            ExitCode::FAILURE
        }
    }
}
