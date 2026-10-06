-- An `intentional` annotation marks a finding that is true and meant: a route
-- parked on purpose, a helper only tests use by design. SQLite cannot change
-- a CHECK constraint in place, so the table is rebuilt with the new kind.
-- Nothing references annotations, so dropping the old table cascades nothing.
CREATE TABLE annotations_new (
    project_id TEXT NOT NULL,
    canonical_name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN
        ('intended_boundary', 'confirmed_dead', 'false_positive', 'intentional', 'note')),
    value TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT 'agent',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (project_id, canonical_name, kind),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
INSERT INTO annotations_new SELECT project_id, canonical_name, kind, value, author, created_at, updated_at FROM annotations;
DROP TABLE annotations;
ALTER TABLE annotations_new RENAME TO annotations;
CREATE INDEX annotations_project_idx ON annotations(project_id);
