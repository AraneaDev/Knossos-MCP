# Dead-code candidates

`architecture_health` (CLI: `architecture-health`) reports
`dead_code_candidates` alongside its hubs and hotspots: components with no
inbound edge among the selected edge kinds.

These are **candidates, not findings**. A zero in-degree is absence of static
evidence, not proof of absence — reflection, configuration, templates, registry
arrays, callbacks, dispatch tables, and framework conventions all reference code
without leaving a statically visible edge. The tool says so in its own
`warnings`, and every candidate carries a `confidence` and a `reason`.

## Confidence

| Confidence | Meaning                                                                                                                                                                    |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `probable` | No inbound reference, and nothing about the component suggests dynamic dispatch.                                                                                           |
| `possible` | No inbound reference, but the component is reached in ways a scan cannot see — a non-`ast` origin, a framework role, or a member of a type extending an external ancestor. |

The `reason` field names the specific ground, so a caller never has to infer why
a candidate was demoted.

## What is excluded before reporting

Several classes of component have a structurally zero in-degree and would drown
the real signal. Each exclusion is counted in `bounds` so the filtering is
auditable rather than invisible.

| `bounds` counter               | Excluded                                                                                                                               |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| `excluded_external_components` | Nodes resolved outside the project (`external_*` kinds, `external`/`unresolved` origins). Include with `include_external`.             |
| `excluded_test_components`     | Nodes classified `quality.test_module` — a runner discovers these by glob, so in-degree 0 is structural. Include with `include_tests`. |
| `excluded_inherited_methods`   | Methods declared by an internal ancestor: the interface or base class carries the contract, and the override is reached through it.    |
| `excluded_constructors`        | Engine-invoked members — constructors, destructors, magic/protocol methods — whose declaring type is referenced (see below).           |
| `suppressed_candidates`        | Canonical names matched by `dead_code_suppressions` in [project configuration](../guides/project-configuration.md).                    |
| _(not counted)_                | Components carrying an entry-point role — a controller, a command, a job, `application.entry_point`, or `tooling.config` (see below).  |
| `annotated_false_positives`    | Components carrying a `false_positive` [annotation](annotations.md).                                                                   |

### Why engine-invoked members are excluded

Instantiating a type is recorded as a `constructs` edge to the **class**, never
to its constructor. Every constructor in every graph therefore has an in-degree
of zero, however heavily the class is used — on one 109-file TypeScript project,
five of thirteen surviving candidates were constructors of classes the same
graph showed being instantiated.

Constructors are the most common case, not the only one. `__destruct` runs when
the last reference drops, `__toString` on a string cast, `__invoke` on a call,
and Python's protocol methods (`__repr__`, `__enter__`, `__eq__`) the same way.
None is ever written at a call site, so each is structurally unreferenced.

Such a member is excluded when its declaring type has any inbound reference.
When the type itself is unreferenced, both stay reportable: the type is the
unit worth deleting, and the member goes with it.

Recognised names are `constructor` (TypeScript/JavaScript) and any member
starting with `__` — the prefix PHP and Python both reserve for engine
dispatch, covering `__construct`, `__init__`, and every magic or protocol
method beside them. Ordinary members of the same type are unaffected.

### Why manifest entry points are excluded

`npm run build` invokes `scripts/build.mjs` by name, and Composer invokes
`bin/console` the same way. Nothing in the project imports either, so both
carry an in-degree of zero however central they are — five of the eight
candidates on that same 111-file scan were scripts of this kind.

Discovery reads each `package.json` and `composer.json` for the paths it names
as `bin`, `main`/`module`, and `scripts`, anchored to the manifest's own
directory so a monorepo package resolves correctly. Script values are shell
commands, so they are tokenised and only tokens shaped like a source file are
kept. That tokenising is loose on purpose: matching is by exact
project-relative path, so a token naming something no scanner emitted never
matches anything. Files that do match are classified `application.entry_point`
(rule `core.manifest.entrypoints.v1`).

A script the manifest does not name stays reportable — which is the useful
signal: after this exclusion the same scan reported exactly one script, and it
was a developer tool wired into nothing.

### Why tool configuration is excluded

ESLint reads `eslint.config.js`, Vitest reads `vitest.config.ts`, pytest reads
`conftest.py` — the tool finds each by filename and no project code imports it,
so its in-degree is zero in every project. A self-scan of a 111-file TypeScript
project returned eight candidates and all eight were configuration of this
shape.

Such modules are classified `tooling.config` (rule `core.tooling.config.v1`)
and, like test modules, are not reported. Recognition is by filename convention
only — `<tool>.config.<ext>`, `<tool>.conf.<ext>`, an `rc` dotfile, `gulpfile`,
`gruntfile`, or `conftest.py` — and deliberately narrow: a module that merely
reads configuration, such as `src/utils/config-loader.ts`, is ordinary source
and stays reportable.

## Acting on a candidate

- Confirm it is genuinely unused, then delete it.
- Record `confirmed_dead` with `annotate_component` when a human or agent has
  verified it but the deletion is not yet scheduled.
- Record `false_positive` when the component is reached in a way the scan cannot
  see. It is dropped from future candidate lists and the count moves to
  `bounds.annotated_false_positives`.
- Use `dead_code_suppressions` for whole families of such components — a
  generated namespace, a plugin directory — rather than annotating each one.

## Limits

- Candidates depend on the selected `edge_kinds` and `min_confidence`. Narrowing
  either produces more candidates, not fewer.
- The scan is bounded by `max_nodes`, `max_edges`, and `timeout_ms`; a truncated
  run reports `truncated` with the reason, and its candidate list is partial.
- `limit` caps the reported list; the counters in `bounds` describe the whole
  examined graph, not the reported slice.
