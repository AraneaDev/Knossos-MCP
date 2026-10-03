-- The quality metrics and fact counts of a retained snapshot, computed once.
--
-- A retained snapshot is immutable: its archive row is written once, with
-- INSERT OR IGNORE, and never updated. Recomputing its metrics on every call
-- decoded the whole archived fact set again each time, which made
-- architecture_trends (and the dashboard the Claude Code mod draws, which
-- reads it) take seconds on a real project for figures that cannot change.
--
-- Only retained archives are stored here, never the active snapshot: that
-- one is read from the live tables, which a running scan rewrites in place
-- before the project's active id moves.
--
-- A row answers only while everything it was computed from still matches:
-- `fingerprint` hashes the code that computes the metrics (so a changed
-- metric is recomputed rather than served stale), and `captured_at` and
-- `byte_size` repeat the archive row it was computed from. Deleting the
-- archive (retention pruning, a removed project) deletes the row with it.
CREATE TABLE snapshot_metrics (
    scan_id TEXT PRIMARY KEY,
    fingerprint TEXT NOT NULL,
    captured_at TEXT NOT NULL,
    byte_size INTEGER NOT NULL CHECK (byte_size >= 0),
    payload_json TEXT NOT NULL,
    FOREIGN KEY (scan_id) REFERENCES scan_snapshots(scan_id) ON DELETE CASCADE
);
