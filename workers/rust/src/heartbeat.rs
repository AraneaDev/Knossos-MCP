//! Keeping a long request audible to the core.
//!
//! The core takes a worker that stays silent past its inactivity timeout for
//! a hung one, so every pass of a scan beats between files.

use serde_json::{json, Value};

/// How long the worker may go without writing during a request before it
/// sends a `scan/heartbeat`, well inside the core's inactivity timeout.
const HEARTBEAT_EVERY: std::time::Duration = std::time::Duration::from_secs(1);

/// Sends `scan/heartbeat` when the request has been quiet for
/// [`HEARTBEAT_EVERY`].
pub(crate) struct Heartbeat {
    /// When the last heartbeat went out, or the request began.
    last: std::time::Instant,
}

impl Heartbeat {
    /// Start counting from now.
    pub(crate) fn new() -> Self {
        Self {
            last: std::time::Instant::now(),
        }
    }

    /// Send a heartbeat through `emit` if the request has been quiet too long.
    pub(crate) fn beat(&mut self, emit: &mut dyn FnMut(&Value)) {
        if self.last.elapsed() >= HEARTBEAT_EVERY {
            emit(&json!({"jsonrpc": "2.0", "method": "scan/heartbeat"}));
            self.last = std::time::Instant::now();
        }
    }
}
