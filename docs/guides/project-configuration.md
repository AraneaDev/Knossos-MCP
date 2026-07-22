# Checked-in project configuration

Knossos automatically reads either `knossos.json` or `knossos.jsonc` from the
scanned project root. Keeping both is an error. JSONC accepts comments and
trailing commas without changing string contents.

Add schema completion to editors with:

```json
{
    "$schema": "./schemas/project-config-v1.schema.json",
    "version": 1
}
```

The published schema is
[`project-config-v1.schema.json`](../../schemas/project-config-v1.schema.json).

## Settings

- `ignores`: at most 100 relative patterns; absolute paths and parent traversal
  are rejected.
- `limits.max_files` and `limits.max_file_bytes`: bounded discovery/worker
  limits.
- `limits.worker_timeout_ms`: finite per-request worker deadline from 1,000
  through 120,000 milliseconds; defaults to 30,000.
- `boundaries`: named project-relative path or PHP namespace matchers.
- `frameworks`: explicit static-analysis hints for supported frameworks.
- `snapshot_retention`: immutable history count from 0 through 20.
- `policies`: checked architecture boundary policies.
- `quality_budgets`: supported architecture regression budget keys.
- `dead_code_suppressions`: at most 200 canonical names, each either an exact
  match or prefixed with a trailing `*` wildcard. Matching components are
  omitted from `architecture_health` dead-code candidates; the count of
  suppressed candidates is reported as `bounds.suppressed_candidates`.

The configuration file itself cannot be ignored, because it participates in
the scanner configuration fingerprint and invalidates cached contributions when
changed.

## Precedence

Values resolve in this order:

1. Explicit CLI or MCP scan argument.
2. Checked-in project configuration.
3. Safe built-in default.

For example, `--snapshot-retention=0` overrides a configured value, and an
explicit empty MCP `boundaries` list disables configured boundaries for that
scan. Omitted arguments inherit project configuration.

Scan results report the configuration source, precedence rule, framework
hints, policy count, reviewed quality budgets, and effective worker timeout and
stream limits. Absolute project roots are not copied into configuration
metadata.

## Validation and safety

Unknown keys, unsupported versions/framework hints, excessive collections,
unsafe path matchers, invalid numeric bounds, and malformed policy/budget shapes
fail before discovery with stable `PROJECT_CONFIG_*` diagnostic prefixes. The
file is capped at one megabyte and is never executed.
