CREATE TABLE boundary_memberships (
    boundary_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    node_id TEXT NOT NULL,
    last_scan_id TEXT NOT NULL,
    PRIMARY KEY (boundary_id, node_id),
    FOREIGN KEY (boundary_id) REFERENCES boundaries(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT
);

CREATE INDEX boundary_memberships_project_node_idx ON boundary_memberships(project_id, node_id);
CREATE INDEX boundary_memberships_project_boundary_idx ON boundary_memberships(project_id, boundary_id);
