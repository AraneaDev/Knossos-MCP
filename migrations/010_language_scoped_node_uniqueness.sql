-- migrate:no-transaction
-- StableId scopes node identity by language (GraphReconciler derives the
-- language from each fact's reference prefix), but the original nodes
-- uniqueness guard did not. A PHP `Error` and a JavaScript `Error` in one
-- project therefore aborted the scan with a UNIQUE violation. Rebuild nodes
-- with an explicit language column and a language-scoped guard.
--
-- The rebuild must run with foreign keys genuinely OFF (a no-op inside a
-- transaction, hence the marker): dropping the old table would otherwise
-- cascade-delete edges, classifications, boundary memberships, and
-- occurrence edges.
PRAGMA foreign_keys = OFF;
BEGIN;

CREATE TABLE nodes_new (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    language TEXT NOT NULL,
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
    UNIQUE (project_id, language, kind, canonical_name)
);

-- Backfill: external nodes carry their language-prefixed reference in
-- attributes; declared nodes take the language implied by the contributing
-- scanner. Unknown scanners keep their scanner id as a distinct language
-- bucket, which preserves uniqueness without inventing a shared value.
INSERT INTO nodes_new (
    id, project_id, language, kind, canonical_name, display_name, parent_id,
    file_id, start_line, end_line, origin, confidence, attributes_json,
    owner_key, last_scan_id
)
SELECT
    id,
    project_id,
    CASE
        WHEN kind LIKE 'external!_%' ESCAPE '!'
            AND json_extract(attributes_json, '$.reference') LIKE '%:%'
            THEN substr(
                json_extract(attributes_json, '$.reference'),
                1,
                instr(json_extract(attributes_json, '$.reference'), ':') - 1
            )
        WHEN substr(owner_key, 1, instr(owner_key, ':') - 1) = 'knossos.php' THEN 'php'
        WHEN substr(owner_key, 1, instr(owner_key, ':') - 1) = 'knossos.typescript' THEN 'ts'
        WHEN substr(owner_key, 1, instr(owner_key, ':') - 1) = 'knossos.python' THEN 'py'
        WHEN instr(owner_key, ':') > 0 THEN substr(owner_key, 1, instr(owner_key, ':') - 1)
        ELSE owner_key
    END,
    kind, canonical_name, display_name, parent_id,
    file_id, start_line, end_line, origin, confidence, attributes_json,
    owner_key, last_scan_id
FROM nodes;

DROP TABLE nodes;
ALTER TABLE nodes_new RENAME TO nodes;

CREATE INDEX nodes_project_canonical_idx ON nodes(project_id, canonical_name);
CREATE INDEX nodes_project_display_idx ON nodes(project_id, display_name);
CREATE INDEX nodes_project_kind_idx ON nodes(project_id, kind);
CREATE INDEX nodes_project_owner_idx ON nodes(project_id, owner_key);

COMMIT;
PRAGMA foreign_keys = ON;
