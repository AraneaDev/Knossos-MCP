---
name: knossos
description: Use when you need to know what depends on something, what a change would break, where a new file belongs, how a request reaches a given component, or who calls a function, in a repository that Knossos has scanned. Answers come from a static graph with file and line evidence instead of from reading the source tree. Also covers recording durable architectural facts for later sessions.
---

# Asking the graph instead of reading the tree

Knossos scans a repository once and answers structural questions from a graph
where every fact points at a file and a line. The point is to stop re-deriving
the same structure by reading the same files.

## Route these questions to the graph

| About to                                     | Ask instead                                                |
| -------------------------------------------- | ---------------------------------------------------------- |
| grep for callers of a symbol                 | `list_usages`, exact call sites with line numbers          |
| guess what a change breaks                   | `impact_analysis`, dependants with confidence and evidence |
| read several files to trace a request path   | `explain_flow`                                             |
| decide where a new file belongs              | `suggest_location`                                         |
| eyeball a diff for architectural risk        | `review_diff`                                              |
| work out what a class is and what it touches | `find_component`, then `inspect_component`                 |

Every project-scoped tool needs a `project_id`. The session brief puts it in
context already. If it is not there, `list_projects` returns it.

## Do not route these

Reaching for the graph here wastes a call and returns the wrong shape:

- Exact string or regex search, a config key, an error message, a TODO. Use grep.
- Reading a file you are about to edit. Edit needs the exact bytes, so use Read.
- Anything about runtime values or behaviour. The graph is static and never
  executes anything, so it cannot tell you what a variable holds.
- Confirming an edge labelled `probable` or `possible`. Those are leads to check
  in the source, not facts.
- Anything structural while the session brief says `STALE`, `UNVERIFIED`,
  `NO GRAPH` or `NOT SCANNED`. Run `scan_project` first, or accept that the
  answer may describe a codebase that no longer exists.

## Writing a fact back

`annotate_component` is the only way one session leaves something for the next.
Notes survive rescans, because annotations are keyed by canonical name rather
than by node id.

Write one when all three hold:

1. The fact cost real effort to learn.
2. It is not visible by reading the component itself.
3. It will still be true in a month.

Worth writing:

- `ToolCatalog`, "the extension seam is here; `ToolService` is the dispatcher and should not grow arms"
- `ProcessScannerClient`, "the worker boundary is process-enforced, not convention; in-process calls will pass tests and fail in production"

Not worth writing, and actively harmful because they bury the ones above:

- Anything you learned by reading the file once. The next session can read it too.
- Anything about the current task, branch, or PR. Notes have no expiry.
- Restatements of what the graph already encodes, "this class implements that
  interface". The graph knows.
- Speculation, or anything you have not verified.

Mechanics: the tool previews by default. Pass `execute: true` to actually write.
`kind` is `note` for free-form facts. The other three kinds change how other
read surfaces behave, so use them only for their actual meaning:
`intended_boundary` marks a deliberate placement, `false_positive` stops a
component being re-flagged, `confirmed_dead` records verified unused code.
