# Project and database maintenance

Maintenance operations are local, bounded, and explicit. Project removal,
stale-scan cleanup, checkpoints, optimization, and backups default to a dry run.
Only the integrity check runs immediately because it is read-only.

Preview and then remove a persisted project:

```sh
knossos remove-project project_... --json
knossos remove-project project_... --execute --json
```

Preview abandoned, failed, or cancelled scans older than a threshold, then
remove only records that are not referenced by the active graph:

```sh
knossos cleanup-stale-scans project_... --older-than-hours=24 --json
knossos cleanup-stale-scans project_... --older-than-hours=24 --execute --json
```

Database-wide commands are:

```sh
knossos maintain-database integrity --json
knossos maintain-database checkpoint --execute --json
knossos maintain-database optimize --execute --json
knossos maintain-database backup --backup-name=before-upgrade.sqlite --execute --json
```

Backups use SQLite `VACUUM INTO`, remove transient writer leases from the copy,
and are atomically published beneath the database directory's `backups/`
folder. A backup name must be a plain `.sqlite` filename; directory components
and overwrites are rejected. Open a backup with Knossos or SQLite and run
`PRAGMA integrity_check` before relying on it for recovery.

The equivalent MCP tools are `remove_project`, `cleanup_stale_scans`, and
`maintain_database`. The two deletion tools carry destructive annotations and
require `execute: true` to mutate state. Maintenance takes advisory writer
leases for every bounded project before checkpoint, optimize, or backup work;
an active scan therefore causes a safe busy error instead of concurrent
maintenance.
