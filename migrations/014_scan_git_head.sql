-- The commit a scan was taken at, when its root was a Git repository.
-- Nullable: gitless projects and gitless containers are first-class, and a
-- scan taken before this migration has no HEAD to backfill.
ALTER TABLE scans ADD COLUMN git_head TEXT NULL;
