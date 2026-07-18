CREATE TABLE classifications (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    node_id TEXT NOT NULL,
    role TEXT NOT NULL,
    origin TEXT NOT NULL,
    confidence TEXT NOT NULL CHECK (confidence IN ('certain', 'probable', 'possible')),
    rule_id TEXT NOT NULL,
    file_id TEXT NULL,
    start_line INTEGER NULL CHECK (start_line IS NULL OR start_line >= 1),
    end_line INTEGER NULL CHECK (end_line IS NULL OR end_line >= start_line),
    attributes_json TEXT NOT NULL DEFAULT '{}',
    last_scan_id TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT,
    UNIQUE (project_id, node_id, role, rule_id)
);

CREATE INDEX classifications_project_role_idx ON classifications(project_id, role);
CREATE INDEX classifications_project_node_idx ON classifications(project_id, node_id);
CREATE INDEX classifications_project_rule_idx ON classifications(project_id, rule_id);
