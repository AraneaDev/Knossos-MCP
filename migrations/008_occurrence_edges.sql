-- Edges are occurrence-level facts. Their stable primary key includes the
-- evidence path, line range, and owner; repeated relations must therefore not
-- be collapsed by a relation-level uniqueness constraint.
CREATE TABLE edges_occurrence (
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
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT
);

INSERT INTO edges_occurrence (
    id, project_id, kind, source_id, target_id, file_id, start_line, end_line,
    origin, confidence, attributes_json, owner_key, last_scan_id
)
SELECT
    id, project_id, kind, source_id, target_id, file_id, start_line, end_line,
    origin, confidence, attributes_json, owner_key, last_scan_id
FROM edges;

DROP TABLE edges;
ALTER TABLE edges_occurrence RENAME TO edges;

CREATE INDEX edges_project_source_idx ON edges(project_id, source_id, kind);
CREATE INDEX edges_project_target_idx ON edges(project_id, target_id, kind);
CREATE INDEX edges_project_kind_idx ON edges(project_id, kind);
CREATE INDEX edges_project_owner_idx ON edges(project_id, owner_key);
CREATE INDEX edges_file_idx ON edges(file_id);
