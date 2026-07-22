# Language API reference

This file is generated from enforced PHP interface docblocks and the isolated TypeScript and Python worker surfaces.

## PHP extension interfaces

### `Knossos\Classification\ClassificationRule`

- `id(): string` — Return the stable identifier recorded as classification provenance
- `classify(Knossos\Scanner\Protocol\NodeFact $node): array` — Classify one graph node without mutating it

### `Knossos\Cli\CliCommand`

- `supports(string $command): bool` — Reports whether this handler owns the requested CLI command name
- `run(string $command, array $positionals, array $options, Knossos\Cli\CliCommandContext $context): int` — Executes a supported CLI command using parsed positional arguments and options

### `Knossos\Git\GitHistoryProvider`

- `history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array` — Return bounded, read-only change history for project-relative files

### `Knossos\Git\GitProcessRunnerInterface`

- `run(array $command, int $timeoutMs, string $operation): string` — Run a bounded, timeout-controlled Git command

### `Knossos\Git\GitWorkingTreeProvider`

- `changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array` — Return bounded changed paths and explicit renames without modifying Git

### `Knossos\Query\SemanticRanker`

- `id(): string` — Return the stable provider identifier included in ranking provenance
- `rank(string $featureDescription, array $candidates, int $timeoutMs): array` — Score bounded candidate text without changing deterministic base factors

### `Knossos\Scan\ProjectScanner`

- `scan(string $root, ?string $name = null, ?int $maxFiles = null, ?int $maxFileBytes = null, ?array $explicitBoundaries = null, ?string $mode = null, ?Knossos\Scan\CancellationToken $cancellation = null, ?int $snapshotRetention = null, ?int $workerTimeoutMs = null): Knossos\Query\ResultEnvelope` — Missing documentation

### `Knossos\Scanner\ScannerClient`

- `initialize(): Knossos\Scanner\Protocol\ScannerManifest` — Negotiate the worker contract before any project input is sent
- `discover(array $project): array` — Discover language configuration within one validated project root
- `scan(array $request): iterable` — Stream owned facts for a bounded, validated scan request
- `cancel(string $requestId): void` — Request cooperative cancellation of an in-flight worker operation
- `shutdown(): void` — Shut down the worker and release its complete process tree

### `Knossos\Store\GraphRepository`

- `transaction(callable $operation): mixed` — Execute an operation atomically and return its result
- `saveProject(string $id, string $name, string $rootRealpath, array $config = []): void` — Create or update project identity and non-secret configuration metadata
- `findProject(string $id): ?array` — Find one project by stable ID
- `createScan(string $id, string $projectId, string $mode, string $scannerSetHash): void` — Record the start of a scan before graph reconciliation
- `completeScan(string $projectId, string $scanId): void` — Atomically make a successfully reconciled scan active
- `archiveActiveSnapshot(string $projectId, string $configHash, int $retention): void` — Retain the active snapshot under the configured bounded history policy
- `clearProjectGraph(string $projectId): void` — Remove replaceable active graph facts while preserving project identity
- `saveFile(string $id, string $projectId, string $relativePath, string $contentHash, int $size, int $mtime, string $language, string $scannerVersion, string $scanId, int $lineCount = 0): void` — Persist one scanned file and its content/provenance fingerprints
- `saveNode(string $id, string $projectId, string $language, string $kind, string $canonicalName, string $displayName, ?string $parentId, ?string $fileId, ?int $startLine, ?int $endLine, string $origin, string $confidence, array $attributes, string $ownerKey, string $scanId): void` — Persist one evidence-backed graph node
- `saveEdge(string $id, string $projectId, string $kind, string $sourceId, string $targetId, ?string $fileId, ?int $startLine, ?int $endLine, string $origin, string $confidence, array $attributes, string $ownerKey, string $scanId): void` — Persist one occurrence-level, evidence-backed directed graph edge
- `saveDiagnostic(string $id, string $projectId, string $scanId, ?string $fileId, string $severity, string $code, string $message, ?int $startLine, ?int $endLine, string $ownerKey): void` — Persist one bounded scanner or reconciliation diagnostic
- `saveClassification(string $id, string $projectId, string $nodeId, string $role, string $origin, string $confidence, string $ruleId, ?string $fileId, ?int $startLine, ?int $endLine, array $attributes, string $scanId): void` — Persist one deterministic role classification with rule provenance
- `saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void` — Persist an explicit or inferred architecture boundary
- `saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void` — Associate a node with one boundary for the active scan
- `replaceContributionCache(string $projectId, array $entries): void` — Replace all incremental contribution-cache entries for a project
- `findNodesByName(string $projectId, string $name, int $limit = 20): array` — Return bounded exact and display-name component matches
- `outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array` — Return bounded outgoing adjacency rows for one node
- `incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array` — Return bounded incoming adjacency rows for one node
- `deleteFactsByOwner(string $projectId, string $ownerKey): void` — Delete every replaceable fact owned by one scanner contribution key

## Isolated worker APIs

| Runtime | Contract | Responsibility |
| --- | --- | --- |
| TypeScript | `TypeScriptScanner.discover` | Performs bounded compiler-backed discovery and scanning without executing |
| TypeScript | `TypeScriptScanner.scan` | Performs bounded compiler-backed discovery and scanning without executing |
| Python | `PythonAstFactCollector` | Coordinate one AST traversal and delegate fact enrichment. |
| Python | `scan` | Parse a bounded file set and emit one owned contribution per input. |
| Python | `discover` | Discover sorted Python configuration and package markers below a safe root. |
| Python | `handle` | Validate and dispatch one NDJSON JSON-RPC worker request. |
