-- Add the edges FK child-key indexes that node teardown depends on.
--
-- Migration 011 restored the single-column FK indexes for nodes,
-- classifications, and boundary_memberships, but missed edges: with
-- PRAGMA foreign_keys = ON, every row deleted from nodes runs an FK check
-- against edges.source_id and edges.target_id (ON DELETE CASCADE). The
-- existing edges_project_source_idx / edges_project_target_idx composites
-- lead with project_id, so they cannot serve a bare source_id/target_id
-- probe — EXPLAIN QUERY PLAN shows "SCAN edges USING COVERING INDEX"
-- for both checks, i.e. a full index scan per deleted node. On the
-- self-scan this made clearProjectGraph O(nodes x edges): the nodes
-- delete alone measured 20.6 s of the 24.2 s clear_graph phase, and
-- drops to ~120 ms once these indexes exist.
--
-- Plain CREATE INDEX statements are transactional, so this migration runs
-- under the migration runner's own transaction (no -- migrate:no-transaction
-- marker).
CREATE INDEX edges_source_idx ON edges(source_id);
CREATE INDEX edges_target_idx ON edges(target_id);
