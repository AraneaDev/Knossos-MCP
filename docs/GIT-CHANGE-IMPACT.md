# Git change signals and time-aware impact

`change_impact` starts with the same bounded reverse static dependency analysis
as `impact_analysis`, then reads recent Git history for the impacted components'
indexed files. It does not modify the worktree, index, refs, or repository
configuration. Git runs with optional locks disabled, argument-array process
execution, a hard timeout, and stdout/stderr byte caps.

Inputs include `since_days`, `max_commits`, static `max_depth`/relationship
filters, a result `limit`, and `timeout_ms`. Per-file signals are recent commit
count, distinct author email identifiers, and latest author timestamp. The risk
ranking exposes its simple factors: three points per commit, one per distinct
author, and a bounded static-proximity weight.

These are prioritization heuristics, not proof of risk, ownership, code quality,
or future failure. Renames are intentionally not followed in the first version,
and history outside the selected window is absent. If Git is unavailable or the
scanned root is not a repository, the tool returns the static impact with zero
change scores and a reason instead of failing the whole query.

For current working-tree or explicit file changes rather than historical risk,
use [`changed_files_impact`](CHANGED-FILES-IMPACT.md).
