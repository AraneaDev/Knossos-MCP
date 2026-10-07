-- Which files each cached contribution read, so a change to any of them
-- invalidates the contribution (and, transitively, its own readers).
ALTER TABLE contribution_cache ADD COLUMN read_attribution INTEGER NOT NULL DEFAULT 0;
ALTER TABLE contribution_cache ADD COLUMN read_group TEXT;

CREATE TABLE contribution_reads (
    project_id TEXT NOT NULL,
    owner_key TEXT NOT NULL,
    read_path TEXT NOT NULL,
    read_hash TEXT,
    PRIMARY KEY (project_id, owner_key, read_path),
    FOREIGN KEY (project_id, owner_key) REFERENCES contribution_cache(project_id, owner_key) ON DELETE CASCADE
);
CREATE INDEX contribution_reads_by_path ON contribution_reads(project_id, read_path);

-- Reads shared by every file of one worker request (configs, global
-- declarations, package manifests), stored once per distinct set.
CREATE TABLE contribution_read_groups (
    project_id TEXT NOT NULL,
    group_id TEXT NOT NULL,
    read_path TEXT NOT NULL,
    read_hash TEXT,
    PRIMARY KEY (project_id, group_id, read_path),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX contribution_read_groups_by_path ON contribution_read_groups(project_id, read_path);
CREATE INDEX contribution_cache_read_group ON contribution_cache(project_id, read_group);

-- Entries written before this migration carry no read set and cannot be
-- trusted to be current; the next scan of every project is a full one.
DELETE FROM contribution_cache;
