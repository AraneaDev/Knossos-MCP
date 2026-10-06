<?php

declare(strict_types=1);

namespace Knossos\Cli;

/**
 * The canonical `knossos help` text.
 *
 * Also the source of docs/reference/cli.md, which is generated from it and checked
 * in CI — so this text and the published reference cannot drift apart.
 */
final class CliHelpRenderer
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Destination for the help text; defaults to
     *                              the process STDOUT.
     *
     * Injectable for the same reason CliErrorRenderer's stream is: fwrite() to
     * the STDOUT constant bypasses PHP's output buffering, so a test cannot
     * capture it with ob_start(). Rendering into an in-memory stream makes the
     * emitted text assertable instead of leaving render() covered by a smoke
     * test that proves only "did not throw".
     */
    public function __construct($stream = null)
    {
        /** @var resource $target */
        $target = $stream ?? STDOUT;
        $this->stream = $target;
    }

    /** Print the canonical help text, which is also the source of the generated CLI reference. */
    public function render(): void
    {
        fwrite($this->stream, <<<'TEXT'
Knossos architecture intelligence

Usage:
  knossos version [--json]
  knossos doctor [--db=PATH] [--json]
  knossos list-projects [--limit=N] [--offset=N] [--include-roots]
                        [--db=PATH] [--json]
  knossos list-snapshots <path|project-id> [--limit=N] [--offset=N] [--json]
  knossos snapshot-diff <path|project-id> <from-snapshot> [to-snapshot]
                        [--max-changes=N] [--json]
  knossos quality-gate <path|project-id> <baseline-snapshot> --budgets=FILE
                       [--policies=FILE] [--sarif] [--propose-baseline] [--json]
  knossos architecture-trends <path|project-id> [--limit=N]
                              [--release-from=SNAPSHOT] [--json]
  knossos remove-project <project-id> [--execute] [--db=PATH] [--json]
  knossos cleanup-stale-scans <project-id> [--older-than-hours=N]
                              [--execute] [--db=PATH] [--json]
  knossos maintain-database <integrity|checkpoint|optimize|vacuum|backup>
                            [--backup-name=NAME.sqlite] [--execute] [--json]
  knossos scan <path> [--mode=auto|full|incremental] [--name=NAME]
                      [--boundary=NAME:path:PREFIX] [--snapshot-retention=N]
                      [--worker-timeout-ms=N]
                      [--worker-memory-mb=N]
                      [--db=PATH] [--json]
  knossos watch <path> [--poll-ms=N] [--debounce-ms=N] [--max-queue=N]
                       [--shared] [--db=PATH] [--json]
  knossos export-bundle <project-id> --output=FILE
                        [--redaction=none|paths|strict] [--db=PATH] [--json]
  knossos import-bundle <file> [--name=NAME] [--db=PATH] [--json]
  knossos find-component <path|project-id> <name> [--limit=N] [--db=PATH] [--json]
  knossos inspect-component <path|project-id> <component> [--max-relationships=N]
                            [--max-children=N] [--min-confidence=LEVEL] [--json]
  knossos list-usages <path|project-id> <symbol> [--edge-kind=KIND]
                      [--min-confidence=LEVEL] [--limit=N] [--json]
  knossos architecture-summary <path|project-id> [--limit=N] [--db=PATH] [--json]
  knossos file-metrics <path|project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N] [--json]
  knossos explain-flow <path|project-id> <from> <to> [--max-depth=N] [--max-paths=N]
                       [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos impact-analysis <path|project-id> <symbol> [--max-depth=N] [--limit=N]
                          [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos dependency-cycles <path|project-id> [--edge-kind=KIND] [--limit=N]
                            [--min-confidence=LEVEL] [--max-nodes=N]
                            [--max-edges=N] [--timeout-ms=N]
                            [--include-self-loops] [--json]
  knossos architecture-health <path|project-id> [--edge-kind=KIND] [--limit=N]
                              [--min-confidence=LEVEL] [--max-nodes=N]
                              [--max-edges=N] [--timeout-ms=N] [--include-external]
                              [--include-tests] [--candidate-confidence=LEVEL]
                              [--candidate-offset=N] [--candidate-timeout=MS] [--json]
  knossos dead-code <path|project-id> [--reachability=unreferenced|test-only]
                    [--db=PATH] [--json]
  knossos check-architecture <path|project-id> --policies=FILE [--limit=N]
                             [--min-confidence=LEVEL] [--max-edges=N]
                             [--timeout-ms=N] [--json]
  knossos suggest-location <path|project-id> <feature-description> [--limit=N]
                           [--max-members=N] [--max-edges=N]
                           [--timeout-ms=N] [--ranking-mode=MODE] [--json]
  knossos change-impact <path|project-id> <symbol> [--since-days=N]
                        [--max-commits=N] [--max-depth=N] [--limit=N]
                        [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos changed-files-impact <path|project-id> [FILE...] [--working-tree]
                               [--base-ref=REF] [--max-depth=N] [--limit=N]
                               [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos test-impact <path|project-id> [FILE...] [--working-tree]
                      [--base-ref=REF] [--max-depth=N] [--limit=N]
                      [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos review-diff <path|project-id> [FILE...] [--base-ref=REF]
                      [--policies=FILE] [--budgets=FILE]
                      [--baseline-snapshot=SNAPSHOT] [--max-depth=N]
                      [--limit=N] [--min-confidence=LEVEL] [--timeout-ms=N]
                      [--json]
  knossos architecture-context <path|project-id> [FILE...] [--task=TEXT]
                               [--max-chars=N] [--timeout-ms=N]
                               [--include-source] [--json]
  knossos export-diagram <path|project-id> [--format=mermaid|plantuml]
                         [--boundary=ID_OR_NAME] [--edge-kind=KIND]
                         [--direction=LR|TB] [--max-nodes=N] [--max-edges=N]
  knossos export-agent-brief <path|project-id> [--max-chars=N] [--out=FILE] [--json]
  knossos session-brief [path] [--db=FILE] [--json]
  knossos turn-brief [path] [--files=PATH]... [--policies=FILE] [--no-policies]
                     [--since=SNAPSHOT] [--reuse-scan] [--db=FILE] [--json]
  knossos rescan [path] [--db=FILE] [--json]
  knossos dashboard [path] [--fan-in-threshold=N] [--db=FILE] [--json]
  knossos component-detail [path] <name> [--db=FILE] [--json]
  knossos file-detail <file> [--db=FILE] [--json]
  knossos session-changes [path] --since=SNAPSHOT [--db=FILE] [--json]
  knossos boundary-couplings [path] --from=BOUNDARY --to=BOUNDARY [--db=FILE] [--json]
  knossos graph-search [path] --query=TEXT [--db=FILE] [--json]
  knossos file-context <file> [--db=FILE] [--json]
  knossos branch-diff [path] [--db=FILE] [--json]
  knossos churn [path] [--db=FILE] [--json]
  knossos blast-radius [path] --component=NAME [--db=FILE] [--json]
  knossos path-between [path] --from=NAME --to=NAME [--db=FILE] [--json]
  knossos annotate [path] --component=NAME [--kind=KIND] [--value=TEXT] [--remove]
                   [--execute] [--db=FILE] [--json]
  knossos session-head [path] [--json]
  knossos session-diff [path] --rev=SHA --file=PATH [--json]
  knossos install-agent-plugin [--scope=user] [--data-dir=DIR] [--execute]
  knossos install-agent-plugin --out=DIR --data=HOSTPATH [--image=NAME]
  knossos list-boundaries <path|project-id> [--source=explicit|inferred] [--limit=N]
  knossos search-architecture <path|project-id> <query> [--kind=KIND] [--role=ROLE]
                              [--boundary=ID] [--confidence=LEVEL] [--limit=N]
  knossos annotate-component <path|project-id> <component> <kind> [value]
                             [--remove] [--execute] [--db=PATH] [--json]
  knossos list-annotations <path|project-id> [--component=NAME] [--kind=KIND]
                           [--limit=N] [--offset=N] [--db=PATH] [--json]
  knossos serve [--allow-root=PATH]... [--db=PATH]
                (roots also come from KNOSSOS_ALLOWED_ROOTS and the roots
                 file beside the database, re-read on every request)
  knossos allow-root <path> [--execute]

Docker supplies PHP, Node, Composer, and SQLite; mount source read-only at an
allowed path and graph data read-write at /data.
TEXT . PHP_EOL);
    }
}
