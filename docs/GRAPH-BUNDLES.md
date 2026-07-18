# Portable graph bundles

Graph bundles move an active architecture snapshot between Knossos databases
without copying project source, absolute roots, worker executables, caches, or
database pages.

```sh
bin/knossos export-bundle PROJECT_ID \
  --output=architecture.knossos.gz \
  --redaction=paths

bin/knossos import-bundle architecture.knossos.gz \
  --name="Imported architecture"
```

Exports refuse to overwrite an existing file. Incomplete writes are removed.
Imports create a new immutable synthetic project identity and never restore the
source root.

## Format and determinism

Version 1 is canonical JSON compressed as gzip. The decompressed contract is
published as [`graph-bundle-v1.schema.json`](../schemas/graph-bundle-v1.schema.json).
The manifest records format/schema version, redaction mode, canonical payload
SHA-256, byte and fact counts, and the source scan completion timestamp. Sorted
tables, recursively sorted object keys, a fixed compression level, and no
wall-clock export timestamp make repeated exports byte-for-byte identical.

The payload contains normalized files, nodes, edges, classifications,
boundaries/memberships, and diagnostics. Edges are occurrence-level facts:
repeated relations between the same source and target remain separate when
their evidence locations differ. It never contains source bytes, absolute
roots, contribution caches, retained database snapshots, commands, or
executable payloads.

## Redaction

- `none`: retain project-relative evidence paths and fact metadata.
- `paths`: deterministically replace relative paths while retaining graph
  attributes and names.
- `strict`: additionally remove fact attributes, diagnostic messages, and
  recognizable owner keys.

Redaction is deterministic so checksums and review diffs remain stable.

## Import safety

Before and during one database transaction, import enforces:

- gzip input at most 10 MB and decompressed JSON at most 50 MB;
- at most 200,000 total facts;
- exact top-level/manifest keys and supported schema/redaction versions;
- canonical payload checksum and declared byte/fact counts;
- relative traversal-free file paths and bounded strings/JSON attributes;
- valid confidence, severity, references, and foreign-key relationships.

Every project, scan, file, node, edge, role, boundary, and diagnostic identity is
deterministically remapped. Dangling references, duplicate facts, malformed
values, tampering, or an already imported bundle roll back without activating a
partial graph.
