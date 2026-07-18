# Troubleshooting and migration guide

## First-response workflow

Start with the packaged runtime and structured output:

```sh
docker run --rm knossos-mcp:dev doctor --json
```

If scanning fails, keep the source mount read-only, enable JSON output, and
capture the stable diagnostic prefix from stderr. Consult the [fault recovery
matrix](RECOVERY-MATRIX.md) before deleting or rebuilding data. A worker error
normally preserves the last active snapshot; a corrupt derived contribution
cache is rebuilt by the next full scan.

For stale results, compare the stored scanner and configuration fingerprints,
then request one explicit full scan:

```sh
docker run --rm --network none \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev scan /workspace --mode=full --json
```

Do not repeatedly force full mode as a substitute for investigating a stable
diagnostic. Normal operation should use `auto`, which verifies fingerprints and
selects safe incremental work.

## Common symptoms

| Symptom or code           | Meaning                                                               | Safe action                                                                               |
| ------------------------- | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `KNOSSOS_SCAN_BUSY`       | Another writer owns the project scan lease.                           | Wait for that scan or terminate its owning process cleanly, then retry.                   |
| `KNOSSOS_SCAN_CANCELLED`  | The client or signal cancelled work.                                  | Retry; the prior active snapshot remains available.                                       |
| `KNOSSOS_DISCOVERY_ERROR` | Root, symlink, ignore, count, or byte limits rejected input.          | Correct the path/configuration; do not expand allowed roots blindly.                      |
| `KNOSSOS_STORAGE_ERROR`   | SQLite is locked, full, unwritable, or corrupt.                       | Free capacity/release the lock, run integrity, and restore an atomic backup if required.  |
| Worker diagnostic prefix  | A scanner crashed, timed out, or violated its protocol/output limits. | Inspect stderr diagnostics and the affected language file; other snapshots remain intact. |
| MCP `-32002`              | The client called a tool before initialization completed.             | Fix client lifecycle framing; send `notifications/initialized` first.                     |

## Versioned database migration

Knossos migrations are forward-only, ordered, and recorded in
`schema_migrations`. Before replacing an image:

1. Record the current immutable image digest and run `doctor --json`.
2. Create and externally retain an atomic SQLite backup using
   `maintain-database backup --execute`.
3. Start the new image against a copy or staging volume first. Startup applies
   pending migrations transactionally and records their versions.
4. Run doctor, a representative scan, and an architecture summary before
   promoting the volume.
5. If verification fails, stop the new process and restore the pre-upgrade
   backup with the previous image. Never copy a live WAL/database pair by hand.

The full quality profile automates this clean install, idempotent upgrade, and
verified rollback sequence through `tools/release-lifecycle`. Schema downgrades
are deliberately unsupported; rollback restores the complete pre-migration
backup. Bundle format and scanner SDK compatibility are versioned independently
and reject unsupported versions before mutating active data.

## Protocol and configuration migration

- Regenerate and review [CLI](CLI-REFERENCE.md) and [MCP](MCP-REFERENCE.md)
  references whenever command options or tool schemas change.
- Keep `knossos.json` on its declared schema version. Unknown keys and future
  versions fail closed; see [project configuration](PROJECT-CONFIGURATION.md).
- Third-party workers must pass the [scanner SDK](SCANNER-SDK.md) conformance
  runner before their protocol or output schema version is adopted.
- Exported graph bundles are immutable transfer artifacts, not database
  backups. Import validates their format, checksums, limits, and identities
  before one atomic activation.
