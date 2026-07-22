-- Agent-recorded annotations. Keyed by canonical name, never by node id:
-- node rows are deleted and regenerated on every rescan, and annotations
-- must survive that. Removing the project cascades the cleanup.
CREATE TABLE annotations (
    project_id TEXT NOT NULL,
    canonical_name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN
        ('intended_boundary', 'confirmed_dead', 'false_positive', 'note')),
    value TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT 'agent',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (project_id, canonical_name, kind),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX annotations_project_idx ON annotations(project_id);
