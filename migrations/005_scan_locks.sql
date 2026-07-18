CREATE TABLE scan_locks (
    project_id TEXT PRIMARY KEY,
    owner_token TEXT NOT NULL,
    acquired_at INTEGER NOT NULL
);
