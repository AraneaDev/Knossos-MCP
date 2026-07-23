-- Restore the foreign-key indexes that graph teardown depends on.
--
-- Migration 010 rebuilt the nodes table but recreated only the four
-- (project_id, …) composite indexes, silently dropping nodes_file_idx (present
-- since migration 001) and never adding a parent_id index. With
-- PRAGMA foreign_keys = ON, every row deleted from nodes runs the parent_id
-- ON DELETE SET NULL action and the file_id ON DELETE CASCADE action; deleting a
-- node also cascades into classifications(node_id) and boundary_memberships(node_id).
-- Without a single-column index leading with the referenced column, each of
-- those actions is a full table scan, so clearProjectGraph (run inside every
-- scan's write transaction) is O(N^2) — the measured cause of the 30 s
-- reconciliation stage (27.2 s → 0.23 s once indexed). The existing
-- (project_id, node_id) composites cannot serve a bare node_id FK probe because
-- project_id is their leftmost column.
--
-- Plain CREATE INDEX statements are transactional, so this migration runs under
-- the migration runner's own transaction (no -- migrate:no-transaction marker).
CREATE INDEX nodes_parent_idx ON nodes(parent_id);
CREATE INDEX nodes_file_idx ON nodes(file_id);
CREATE INDEX classifications_node_idx ON classifications(node_id);
CREATE INDEX boundary_memberships_node_idx ON boundary_memberships(node_id);
