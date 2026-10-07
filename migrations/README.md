# Migrations

Applied migrations are checksummed by `MigrationRunner`, which compares a
sha256 of the entire file (comments included) against the recorded value and
refuses to run when they differ. An applied migration is therefore immutable:
corrections go here, not into the file.

## Corrections

- **013_edges_fk_child_indexes.sql** credits "Migration 011" with restoring the
  single-column FK indexes for `nodes`, `classifications`, and
  `boundary_memberships`. That was migration **012**; 011 adds the
  `annotations` table.
- **021_contribution_reads.sql** says the next scan of every project after it
  is a full one. The scan rescans every file, because the migration empties the
  contribution cache, but its mode stays incremental. Scans after it reuse the
  cache again.
