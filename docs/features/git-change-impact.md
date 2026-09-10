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

When history was read, `git.available` is true and `git.reason` is `null`.
Otherwise `git.reason` says what stopped it, and the value distinguishes two
different failures. `unmatched_target` and `ambiguous_target` mean the request
never resolved to one component, so there is no blast radius to score and
`risk_ranking` is empty: the first when `symbol` matched nothing, the second when
it matched several. Every other value still carries the full static impact with
zero change scores, either `provider_unavailable` when no Git provider is
configured, or the Git error message itself, truncated to 500 characters. That
last case is free-form, so read `reason` as a diagnostic string rather than a
closed set of codes.

For current working-tree or explicit file changes rather than historical risk,
use [`changed_files_impact`](changed-files-impact.md).
