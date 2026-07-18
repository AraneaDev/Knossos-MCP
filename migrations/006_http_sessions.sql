CREATE TABLE http_sessions (
    id TEXT PRIMARY KEY,
    initialized INTEGER NOT NULL DEFAULT 0 CHECK (initialized IN (0, 1)),
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE INDEX http_sessions_expiry_idx ON http_sessions(expires_at);
