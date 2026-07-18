CREATE TABLE contribution_cache (
    project_id TEXT NOT NULL,
    owner_key TEXT NOT NULL,
    file_path TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    scanner_id TEXT NOT NULL,
    scanner_version TEXT NOT NULL,
    configuration_hash TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (project_id, owner_key),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX contribution_cache_project_file_idx ON contribution_cache(project_id, file_path);
CREATE INDEX contribution_cache_project_scanner_idx ON contribution_cache(project_id, scanner_id, scanner_version);
