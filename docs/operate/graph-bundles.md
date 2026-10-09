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

A bundle is canonical JSON compressed as gzip, format version 2. The
decompressed structure is described by
[`graph-bundle-v2.schema.json`](../../schemas/graph-bundle-v2.schema.json).
The manifest records the format and version, redaction mode, canonical payload
SHA-256, byte and fact counts, and the source scan completion timestamp. Sorted
tables, recursively sorted object keys, a fixed compression level, and a `created_at` that is the
source scan's finish time rather than the export time make repeated `none` exports byte-for-byte
identical. Redacted exports are not: each one draws its own salt (see below).

The payload contains normalized files, nodes, edges, classifications,
boundaries/memberships, and diagnostics. Edges are occurrence-level facts:
repeated relations between the same source and target remain separate when
their evidence locations differ. It never contains source bytes, absolute
roots, contribution caches, retained database snapshots, commands, or
executable payloads.

## Redaction

- `none`: retain project-relative evidence paths and fact metadata.
- `paths`: replace every discovered path, in every column, with a salted token.
- `strict`: everything `paths` does, and also remove fact attributes, diagnostic
  messages, the project name and recognizable content hashes.

In `paths` mode the replaced paths are every discovered file path, every
directory that holds a file (two or more segments deep), every directory a
boundary names, the same directories spelled with dots, and every Python module
or package name derived from a path, including a namespace package that has no
file of its own. They are replaced wherever they occur as a whole token, that
is, not inside a longer name: in file paths, component names
(`src/billing/invoice.ts#Invoice` keeps `#Invoice`), owner keys and
scanner-local IDs (the `<scanner>:<kind>:` prefix stays), attributes,
diagnostic messages, boundary names and boundary matchers. A path inside an
absolute or `./` path, or between brackets, quotes or other punctuation, is
replaced too, and so is a path written with `\` separators or with
JSON-escaped `\/` slashes. The project's absolute root, as stored and as it
resolves, becomes `redacted-root` wherever it occurs, `file://` URLs included.
Attribute keys lose file and directory paths, but not module names, so a key
such as `main` stays, and the `scanner` attribute keeps its value. Redaction
matches paths as they are stored, case-sensitively: a percent-encoded path, or
a path split across a line break, is not recognised. A file becomes `redacted/<token>.<ext>` with its
lower-cased extension kept, a directory `redacted-dir/<token>`, and a Python
module `redacted_<token>`. Every ID and every reference to one is re-keyed as
well, because a stable ID is derived from the path.

`paths` mode keeps:

- identifiers the code declares: PHP namespaces and classes, Rust module paths,
  TypeScript symbol names, even where your directory layout mirrors them;
- text that is not a discovered path, such as an import specifier as written
  (`./billing/invoice`) or the name of an explicit boundary you chose;
- one-segment directory names such as `src`, which occur as ordinary words,
  unless a boundary names them;
- file basenames that a scanner writes into a free-text diagnostic message,
  such as `invalid syntax (invoice.py, line 3)`;
- the project name, file sizes, line counts, content hashes and fact metadata.

`strict` mode replaces the attributes of nodes, edges and roles with `{}`, every
diagnostic message with `[redacted]`, the project name with `redacted` (import
with `--name` to give the imported project a name), and every content hash with
a salted hash, because the hash of a well-known file names that file. It keeps
file sizes, line counts and lower-cased extensions, and together those can still
identify a well-known file, such as a vendored library at a known version.

The salt is 32 random bytes drawn for each export and never written to the
bundle, its manifest or a log. Without it, a token cannot be checked against a
guessed path. As a consequence, two redacted exports of one snapshot differ,
token by token and in their checksums, and importing both creates two projects.
Only `none` exports are reproducible.

## Import safety

Before and during one database transaction, import enforces:

- gzip input at most 10 MB and decompressed JSON at most 8 MB;
- at most 2,000,000 structural JSON tokens, counted before decoding;
- at most 200,000 total facts;
- exact top-level/manifest keys and supported schema/redaction versions;
- canonical payload checksum and declared byte/fact counts;
- relative traversal-free file paths and bounded strings/JSON attributes;
- valid confidence, severity, references, and foreign-key relationships.

Every project, scan, file, node, edge, role, boundary, and diagnostic identity is
deterministically remapped. Dangling references, duplicate facts, malformed
values, tampering, or an already imported bundle roll back without activating a
partial graph.
