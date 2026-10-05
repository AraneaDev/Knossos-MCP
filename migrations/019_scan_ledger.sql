-- What each recorded scan changed, so a reader that did not run the scan
-- can still tell what the graph held before it.
--
-- A turn brief compares the graph before its scan with the graph after it.
-- When another writer (the live watcher, the pane's rescan, a second
-- session's turn brief) already scanned the turn's edits, the graph before
-- the brief's own scan is no longer the graph before the turn. The ledger
-- keeps, per scan, the snapshot it started from and the one it produced,
-- the content hash every changed file had before it (null for a file it
-- added), and, when the writer computed them, the policy violations each of
-- those files held before it. Read in order from the snapshot a turn started
-- at, the first entry that changed a file says what that file was before
-- the turn, whoever scanned it.
--
-- Entries are pruned per project to the newest few hundred; a removed
-- project takes its entries with it.
CREATE TABLE scan_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id TEXT NOT NULL,
    from_snapshot TEXT NULL,
    to_snapshot TEXT NOT NULL,
    recorded_at INTEGER NOT NULL,
    changes_json TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX scan_ledger_project_idx ON scan_ledger(project_id, id);
