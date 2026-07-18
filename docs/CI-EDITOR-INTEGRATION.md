# CI and editor integration

Knossos uses the same pinned Docker runtime for local, editor, and CI scans. The
source tree is mounted read-only, derived graph state is stored separately, and
scans run with networking disabled.

## Stable exit codes

CLI commands use the following automation contract:

| Code | Meaning                                                                |
| ---: | ---------------------------------------------------------------------- |
|  `0` | The command completed and every evaluated gate passed.                 |
|  `1` | The command completed, but an evaluated health or quality gate failed. |
|  `2` | Usage, configuration, validation, runtime, or infrastructure error.    |

Do not treat code `2` as a quality finding. It means the result could not be
evaluated reliably and the job should fail visibly.

## Machine-readable reports

Run `quality-gate` with `--sarif --json`. Its JSON envelope contains SARIF 2.1.0
at `.data.sarif`; extract it without changing the original command status:

```sh
jq '.data.sarif' artifacts/quality-gate.json > artifacts/knossos.sarif
```

GitHub can upload this file with `github/codeql-action/upload-sarif`. GitLab
should retain it as an ordinary job artifact unless a separately configured
integration explicitly accepts SARIF; GitLab's SAST report schema is different.

`architecture-trends --release-from=SNAPSHOT --json` places deterministic
Markdown at `.data.release_notes.markdown`:

```sh
jq -r '.data.release_notes.markdown' artifacts/trends.json \
  > artifacts/architecture-change.md
```

## Reviewed baseline workflow

Quality budgets compare the active graph with a complete retained snapshot.
Persist `/data` between jobs and set `KNOSSOS_BASELINE_SNAPSHOT` to a snapshot
that was reviewed on the default branch. Never silently replace that variable
when a pull request fails; baseline adoption is an explicit repository or CI
configuration change.

The examples expect a checked-in `knossos-budgets.json`. Add
`--policies=/workspace/architecture-policies.json` when the repository also has
a checked-in policy file. See [architecture quality
budgets](QUALITY-BUDGETS.md) for both formats.

## Ready-to-adapt recipes

- [GitHub Actions](examples/github-architecture.yml) restores retained graph
  state, scans, preserves the gate exit code, uploads SARIF and Markdown, and
  saves history only on the default branch.
- [GitLab CI](examples/gitlab-architecture.yml) applies the same container,
  mounts, baseline, reports, and quality gate.
- [VS Code tasks](examples/vscode-tasks.json) runs the repository's pinned
  quality container and a read-only local scan. Copy it to
  `.vscode/tasks.json` if desired.

Pin third-party CI actions and images to immutable digests according to the
repository's supply-chain policy before production use. Limit write access to
the retained data cache, keep source read-only, and do not expose untrusted
forks to cache-save credentials.
