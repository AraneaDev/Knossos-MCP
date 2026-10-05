# The routing skill

The [plugin](plugin.md) carries two pieces that orient a session, and they do
different jobs. The [session brief](session-brief.md) tells a session what the
repository looks like at the moment it starts. The skill, `knossos:graph`
(`skills/graph/SKILL.md`), tells it which questions to bring back to the graph
for the rest of that session, and which to answer the ordinary way.

Nothing invokes the skill by name. Claude Code loads a skill when its
description matches the question in front of it, so what arms it is the pointer
line the brief always emits:

```text
Ask before grepping for structure: the `knossos:graph` skill.
```

The brief always prints that line, past its character budget if it must (see
[budgets](session-brief.md#budgets)): a brief that dropped it to fit would leave
the skill unarmed for the whole session.

## What it routes to the graph

Six shapes of question, each with the tool that answers it:

| About to                                     | Ask instead                                |
| -------------------------------------------- | ------------------------------------------ |
| grep for callers of a symbol                 | `list_usages`                              |
| guess what a change breaks                   | `impact_analysis`                          |
| read several files to trace a request path   | `explain_flow`                             |
| decide where a new file belongs              | `suggest_location`                         |
| eyeball a diff for architectural risk        | `review_diff`                              |
| work out what a class is and what it touches | `find_component`, then `inspect_component` |

Each row is a question an agent would otherwise answer by reading files and
inferring from them, which pays for the same derivation once per session and
returns a claim with nothing behind it. The graph returns a file and a line.

Every project-scoped tool needs a `project_id`. The brief has already put one in
context; `list_projects` returns it when the brief did not run.

## What it keeps away from the graph

The second half of the skill matters as much as the first, because a tool that
gets called for the wrong question wastes a call and hands back the wrong shape.
It names five cases and why each one belongs elsewhere:

- **Exact string or regex search**: a config key, an error message, a TODO. The
  graph indexes structure, so use grep.
- **Reading a file about to be edited.** Edit matches against exact bytes, so
  the file has to be read in full.
- **Runtime values or behaviour.** Scanning never executes anything, so the
  graph cannot say what a variable holds.
- **Confirming an edge labelled `probable` or `possible`.** Those are leads to
  check against the source.
- **Anything structural while the verdict is `STALE`, `UNVERIFIED`, `NO GRAPH`
  or `NOT SCANNED`.** Run `scan_project` first, or accept an answer that may
  describe a codebase that no longer exists.

## Writing a fact back

`annotate_component` is the one way a session leaves something for the next one,
and annotations are keyed by canonical name rather than by node id, so they
survive a rescan. The skill sets three tests that all have to hold before a
session writes one: the fact cost real effort to learn, it is not visible by
reading the component itself, and it will still be true in a month.

That bar exists to protect the notes worth keeping. A note restating what the
graph already encodes, or describing the current branch, buries the one that
records why a boundary is process-enforced rather than conventional.

The tool previews by default and needs `execute: true` to write. `kind` is
`note` for a free-form fact; `intended_boundary`, `false_positive` and
`confirmed_dead` each change how other read surfaces behave, so they mean only
what they say. [Agent integration](../agents/agent-integration.md#component-annotations)
covers the mechanics.

## Where it lives, and when a change to it takes effect

The skill is `skills/graph/SKILL.md` in the repository.
`install-agent-plugin --execute` copies it into `.plugin/`, and Claude Code
caches that copy by the version in the plugin's manifest. A change to
`SKILL.md` without a release therefore needs an uninstall to force a fresh
copy; see [updating the plugin](plugin.md#update).
