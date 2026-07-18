CREATE TABLE scan_snapshots (
    scan_id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    scanner_set_hash TEXT NOT NULL,
    config_hash TEXT NOT NULL,
    complete INTEGER NOT NULL CHECK (complete IN (0, 1)),
    fact_count INTEGER NOT NULL CHECK (fact_count >= 0),
    byte_size INTEGER NOT NULL CHECK (byte_size >= 0),
    payload_json TEXT NOT NULL,
    captured_at TEXT NOT NULL,
    FOREIGN KEY (scan_id) REFERENCES scans(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX scan_snapshots_project_captured_idx
    ON scan_snapshots(project_id, captured_at DESC, scan_id DESC);
