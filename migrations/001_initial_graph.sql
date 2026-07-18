CREATE TABLE projects (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    root_realpath TEXT NOT NULL,
    config_json TEXT NOT NULL DEFAULT '{}',
    active_scan_id TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (active_scan_id) REFERENCES scans(id) DEFERRABLE INITIALLY DEFERRED
);

CREATE UNIQUE INDEX projects_root_uq ON projects(root_realpath);

CREATE TABLE scans (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    mode TEXT NOT NULL CHECK (mode IN ('full', 'incremental')),
    status TEXT NOT NULL CHECK (status IN ('running', 'complete', 'failed', 'cancelled')),
    scanner_set_hash TEXT NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX scans_project_status_idx ON scans(project_id, status);

CREATE TABLE files (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    relative_path TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    size INTEGER NOT NULL CHECK (size >= 0),
    mtime INTEGER NOT NULL CHECK (mtime >= 0),
    language TEXT NOT NULL,
    scanner_version TEXT NOT NULL,
    last_scan_id TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT,
    UNIQUE (project_id, relative_path)
);

CREATE INDEX files_project_language_idx ON files(project_id, language);
CREATE INDEX files_project_hash_idx ON files(project_id, content_hash);

CREATE TABLE nodes (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    canonical_name TEXT NOT NULL,
    display_name TEXT NOT NULL,
    parent_id TEXT NULL,
    file_id TEXT NULL,
    start_line INTEGER NULL CHECK (start_line IS NULL OR start_line >= 1),
    end_line INTEGER NULL CHECK (end_line IS NULL OR end_line >= start_line),
    origin TEXT NOT NULL,
    confidence TEXT NOT NULL CHECK (confidence IN ('certain', 'probable', 'possible')),
    attributes_json TEXT NOT NULL DEFAULT '{}',
    owner_key TEXT NOT NULL,
    last_scan_id TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES nodes(id) ON DELETE SET NULL,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT,
    UNIQUE (project_id, kind, canonical_name)
);

CREATE INDEX nodes_project_canonical_idx ON nodes(project_id, canonical_name);
CREATE INDEX nodes_project_display_idx ON nodes(project_id, display_name);
CREATE INDEX nodes_project_kind_idx ON nodes(project_id, kind);
CREATE INDEX nodes_project_owner_idx ON nodes(project_id, owner_key);
CREATE INDEX nodes_file_idx ON nodes(file_id);

CREATE TABLE edges (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    source_id TEXT NOT NULL,
    target_id TEXT NOT NULL,
    file_id TEXT NULL,
    start_line INTEGER NULL CHECK (start_line IS NULL OR start_line >= 1),
    end_line INTEGER NULL CHECK (end_line IS NULL OR end_line >= start_line),
    origin TEXT NOT NULL,
    confidence TEXT NOT NULL CHECK (confidence IN ('certain', 'probable', 'possible')),
    attributes_json TEXT NOT NULL DEFAULT '{}',
    owner_key TEXT NOT NULL,
    last_scan_id TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (target_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT,
    UNIQUE (project_id, kind, source_id, target_id, owner_key)
);

CREATE INDEX edges_project_source_idx ON edges(project_id, source_id, kind);
CREATE INDEX edges_project_target_idx ON edges(project_id, target_id, kind);
CREATE INDEX edges_project_kind_idx ON edges(project_id, kind);
CREATE INDEX edges_project_owner_idx ON edges(project_id, owner_key);
CREATE INDEX edges_file_idx ON edges(file_id);

CREATE TABLE diagnostics (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    scan_id TEXT NOT NULL,
    file_id TEXT NULL,
    severity TEXT NOT NULL CHECK (severity IN ('info', 'warning', 'error')),
    code TEXT NOT NULL,
    message TEXT NOT NULL,
    start_line INTEGER NULL CHECK (start_line IS NULL OR start_line >= 1),
    end_line INTEGER NULL CHECK (end_line IS NULL OR end_line >= start_line),
    owner_key TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (scan_id) REFERENCES scans(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE INDEX diagnostics_project_scan_idx ON diagnostics(project_id, scan_id);
CREATE INDEX diagnostics_project_owner_idx ON diagnostics(project_id, owner_key);

CREATE TABLE boundaries (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    name TEXT NOT NULL,
    matcher_json TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('explicit', 'inferred')),
    last_scan_id TEXT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE CASCADE,
    UNIQUE (project_id, name, source)
);

CREATE INDEX boundaries_project_idx ON boundaries(project_id);
