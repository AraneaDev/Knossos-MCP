<?php

declare(strict_types=1);

namespace Knossos\Cli;

final class CliHelpRenderer
{
    public function render(): void
    {
        fwrite(STDOUT, <<<'TEXT'
Knossos architecture intelligence

Usage:
  knossos version [--json]
  knossos doctor [--db=PATH] [--json]
  knossos list-projects [--limit=N] [--offset=N] [--include-roots]
                        [--db=PATH] [--json]
  knossos list-snapshots <project-id> [--limit=N] [--offset=N] [--json]
  knossos snapshot-diff <project-id> <from-snapshot> [to-snapshot]
                        [--max-changes=N] [--json]
  knossos quality-gate <project-id> <baseline-snapshot> --budgets=FILE
                       [--policies=FILE] [--sarif] [--propose-baseline] [--json]
  knossos architecture-trends <project-id> [--limit=N]
                              [--release-from=SNAPSHOT] [--json]
  knossos remove-project <project-id> [--execute] [--db=PATH] [--json]
  knossos cleanup-stale-scans <project-id> [--older-than-hours=N]
                              [--execute] [--db=PATH] [--json]
  knossos maintain-database <integrity|checkpoint|optimize|backup>
                            [--backup-name=NAME.sqlite] [--execute] [--json]
  knossos scan <path> [--mode=auto|full|incremental] [--name=NAME]
                      [--boundary=NAME:path:PREFIX] [--snapshot-retention=N]
                      [--worker-timeout-ms=N]
                      [--db=PATH] [--json]
  knossos watch <path> [--poll-ms=N] [--debounce-ms=N] [--max-queue=N]
                       [--db=PATH] [--json]
  knossos export-bundle <project-id> --output=FILE
                        [--redaction=none|paths|strict] [--db=PATH] [--json]
                        [--redaction=none|paths|strict] [--db=PATH] [--json]
  knossos import-bundle <file> [--name=NAME] [--db=PATH] [--json]
  knossos find-component <project-id> <name> [--limit=N] [--db=PATH] [--json]
  knossos inspect-component <project-id> <component> [--max-relationships=N]
                            [--max-children=N] [--min-confidence=LEVEL] [--json]
  knossos list-usages <project-id> <symbol> [--edge-kind=KIND]
                      [--min-confidence=LEVEL] [--limit=N] [--json]
  knossos architecture-summary <project-id> [--limit=N] [--db=PATH] [--json]
  knossos file-metrics <project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N] [--json]
  knossos explain-flow <project-id> <from> <to> [--max-depth=N] [--max-paths=N]
                       [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos impact-analysis <project-id> <symbol> [--max-depth=N] [--limit=N]
                          [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos dependency-cycles <project-id> [--edge-kind=KIND] [--limit=N]
                            [--min-confidence=LEVEL] [--max-nodes=N]
                            [--max-edges=N] [--timeout-ms=N] [--json]
  knossos architecture-health <project-id> [--edge-kind=KIND] [--limit=N]
                              [--min-confidence=LEVEL] [--max-nodes=N]
                              [--max-edges=N] [--timeout-ms=N] [--include-external]
                              [--include-tests] [--json]
  knossos check-architecture <project-id> --policies=FILE [--limit=N]
                             [--min-confidence=LEVEL] [--max-edges=N]
                             [--timeout-ms=N] [--json]
  knossos suggest-location <project-id> <feature-description> [--limit=N]
                           [--max-members=N] [--max-edges=N]
                           [--timeout-ms=N] [--ranking-mode=MODE] [--json]
  knossos change-impact <project-id> <symbol> [--since-days=N]
                        [--max-commits=N] [--max-depth=N] [--limit=N]
                        [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos changed-files-impact <project-id> [FILE...] [--working-tree]
                               [--base-ref=REF] [--max-depth=N] [--limit=N]
                               [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos test-impact <project-id> [FILE...] [--working-tree]
                      [--base-ref=REF] [--max-depth=N] [--limit=N]
                      [--edge-kind=KIND] [--min-confidence=LEVEL] [--json]
  knossos review-diff <project-id> [FILE...] [--base-ref=REF]
                      [--policies=FILE] [--budgets=FILE]
                      [--baseline-snapshot=SNAPSHOT] [--max-depth=N]
                      [--limit=N] [--min-confidence=LEVEL] [--timeout-ms=N]
                      [--json]
  knossos architecture-context <project-id> [FILE...] [--task=TEXT]
                               [--max-chars=N] [--timeout-ms=N]
                               [--include-source] [--json]
  knossos export-diagram <project-id> [--format=mermaid|plantuml]
                         [--boundary=ID_OR_NAME] [--edge-kind=KIND]
                         [--direction=LR|TB] [--max-nodes=N] [--max-edges=N]
  knossos export-agent-brief <project-id> [--max-chars=N] [--out=FILE] [--json]
  knossos list-boundaries <project-id> [--source=explicit|inferred] [--limit=N]
  knossos search-architecture <project-id> <query> [--kind=KIND] [--role=ROLE]
                              [--boundary=ID] [--confidence=LEVEL] [--limit=N]
  knossos annotate-component <project-id> <component> <kind> [value]
                             [--remove] [--execute] [--db=PATH] [--json]
  knossos list-annotations <project-id> [--component=NAME] [--kind=KIND]
                           [--limit=N] [--offset=N] [--db=PATH] [--json]
  knossos serve --allow-root=PATH [--allow-root=PATH] [--db=PATH]

Docker supplies PHP, Node, Composer, and SQLite; mount source read-only at an
allowed path and graph data read-write at /data.
TEXT . PHP_EOL);
    }
}
