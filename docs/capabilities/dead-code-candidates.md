# Dead-code candidates

`architecture_health` (CLI: `architecture-health`) reports
`dead_code_candidates` alongside its hubs and hotspots: components with no
inbound edge among the selected edge kinds.

These are **candidates, not findings**. A zero in-degree is absence of static
evidence, not proof of absence: reflection, configuration, templates, registry
arrays, callbacks, dispatch tables, and framework conventions all reference code
without leaving a statically visible edge. The tool says so in its own
`warnings`, and every candidate carries a `reachability`, a `confidence` and a
`reason`.

## Reachability

Two components can both be unreachable from anything a user can open and still
ask for opposite action, so each candidate says which case it is.

| `reachability` | Meaning                                                       | Action                                                                                     |
| -------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `unreferenced` | Nothing references it, tests included.                        | Delete it, once you have ruled out the dynamic dispatch the confidence column warns about. |
| `test_only`    | Every inbound reference comes from code classified as a test. | The component and the test guarding it can both go.                                        |

Candidates are ordered `unreferenced` first, then `test_only`, each by
canonical name, so `limit` slices along the class boundary rather than along the
alphabet. The summary names both counts, so a truncated list still says how many
test-only findings are waiting behind a higher `limit`.

`test_only` is usually the more valuable half. A component nothing references
may be waiting on a caller nobody has written yet; one whose own test is its
only caller is finished work that no product path reaches, and it still costs
review, refactors, and CI time. A scan of a 588-file React project found ten
such files: 908 lines of production code and 1,089 lines of test, still
receiving maintenance a week earlier, reachable from no screen.

Passing `include_tests` asks for test code to count as part of the architecture,
which collapses the distinction: nothing is reported as `test_only`, and a
component its test reaches is simply referenced.

Components reached by convention rather than by an edge (controllers, commands,
entry points, tool configuration) are excluded rather than given a class of
their own. They are counted in `bounds.excluded_convention_discovered`; see
[what is excluded](#what-is-excluded-before-reporting) below.

## Confidence

| Confidence | Meaning                                                                                                                                                                   |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `probable` | No inbound reference, and nothing about the component suggests dynamic dispatch.                                                                                          |
| `possible` | No inbound reference, but the component is reached in ways a scan cannot see: a non-`ast` origin, a framework role, or a member of a type extending an external ancestor. |

The `reason` field names the specific ground, so a caller never has to infer why
a candidate was demoted.

## What is excluded before reporting

Several classes of component have a structurally zero in-degree and would drown
the real signal. Each exclusion is counted in `bounds` so the filtering is
auditable rather than invisible.

| `bounds` counter                 | Excluded                                                                                                                                             |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `excluded_external_components`   | Nodes resolved outside the project (`external_*` kinds, `external`/`unresolved` origins). Include with `include_external`.                           |
| `excluded_test_components`       | Nodes classified `quality.test_module`: a runner discovers these by glob, so in-degree 0 is structural. Include with `include_tests`.                |
| `excluded_inherited_methods`     | Methods declared by an internal ancestor: the interface or base class carries the contract, and the override is reached through it.                  |
| `excluded_contract_methods`      | The mirror: declarations an internal implementation carries, when the declaring type is used (see below).                                            |
| `excluded_constructors`          | Engine-invoked members (constructors, destructors, magic/protocol methods) whose declaring type is referenced (see below).                           |
| `excluded_entry_scripts`         | Modules a scanner marked as executable scripts (a shebang, a `__main__` guard, PHP file-scope code) whose bodies something outside the graph enters. |
| `excluded_type_declarations`     | Modules and symbols declared in a `.d.ts` / `.d.mts` (see below).                                                                                    |
| `suppressed_candidates`          | Canonical names matched by `dead_code_suppressions` in [project configuration](../guides/project-configuration.md).                                  |
| `excluded_convention_discovered` | Components carrying an entry-point role: a controller, a command, a job, `application.entry_point`, or `tooling.config` (see below).                 |
| `annotated_false_positives`      | Components carrying a `false_positive` [annotation](agent-integration.md#component-annotations).                                                     |

### Why engine-invoked members are excluded

Instantiating a type is recorded as a `constructs` edge to the **class**, never
to its constructor. Every constructor in every graph therefore has an in-degree
of zero, however heavily the class is used. On one 109-file TypeScript project,
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
starting with `__`: the prefix PHP and Python both reserve for engine
dispatch, covering `__construct`, `__init__`, and every magic or protocol
method beside them. Ordinary members of the same type are unaffected.

### Why type declarations are excluded

A `.d.ts` describes an implementation that lives elsewhere and emits nothing
that runs. Call sites resolve to the `.mjs` behind it, so every symbol in the
declaration file carries an in-degree of zero by construction. Acting on
that report would delete the declaration while the implementation stays,
breaking every typed call site.

The question worth asking about `color-debt.d.mts#measureTree` is whether
`color-debt.mjs#measureTree` is used, and that one is reported on its own
merits. The scanner marks both the module node and every declaration inside it
with `declaration_file`, and both are excluded.

### Why contract declarations are excluded

A call edges to an interface method only when its receiver is typed as the
interface. `foreach ($this->rules as $rule) { $rule->classify($node); }` types
nothing, so the declaration carries an in-degree of zero while every
implementation runs on every scan. This repository reported six such contracts
at once.

A candidate method is excluded when an internal type that implements or extends
the declaring type declares a member of the same name, and the declaring type
is used by something other than those `implements`/`extends` edges. Being
implemented is not evidence that a contract is used, so those edges are
discounted; when nothing else references the type, the type and its members all
stay reportable, on the same reasoning the constructor exclusion uses: the type
is the unit worth deleting.

This is the mirror of `excluded_inherited_methods`, which drops the override on
the grounds that the ancestor carries the contract. Together they report a
hierarchy once, through whichever end is genuinely unreachable.

### Why manifest entry points are excluded

`npm run build` invokes `scripts/build.mjs` by name, and Composer invokes
`bin/console` the same way. Nothing in the project imports either, so both
carry an in-degree of zero however central they are. Five of the eight
candidates on that same 111-file scan were scripts of this kind.

Discovery reads each `package.json` and `composer.json` for the paths it names
as `bin`, `main`/`module`, and `scripts`, and each Azure Functions
`function.json` for its `scriptFile`, anchored to the manifest's own directory
so a monorepo package resolves correctly. Script values are shell
commands, so they are tokenised and only tokens shaped like a source file are
kept. That tokenising is loose on purpose: matching is by exact
project-relative path, so a token naming something no scanner emitted never
matches anything. Files that do match are classified `application.entry_point`
(rule `core.manifest.entrypoints.v1`).

Discovery also reads every `.html` file for its `<script src>` attributes,
every `.yml`/`.yaml` file for tokens shaped like a source path, and every tool
config module for the keys naming files the tool loads. A single-page
application is entered through its HTML shell, a Compose file mounts a config
by path, and Vitest loads `setupFiles` before every test; none of those is an
import, so each named file carried an in-degree of zero while being the reason
the thing runs. A root-absolute `src` names the web root, so `public/` and
`static/` readings are offered alongside the plain one, and YAML is tokenised
as text rather than parsed. Both are loose on purpose and safe for the same
reason the Composer script tokenising is: a token that names no file any
scanner emitted matches nothing. A tool config is the exception and is read
key by key, because it names files to exclude as well as files to load, and an
excluded path is exactly the kind that turns out to be dead.

`function.json` earns its place for a second reason. A handler directory
holding both `index.js` and a stale `index.ts` resolves the wrong way: asked
where a sibling's `require('../management')` points, TypeScript's own module
resolution answers with the `.ts`, so the fossil absorbs the dependency and the
handler the host actually executes is left looking dead. The manifest settles
which of the two runs on the runtime's authority rather than the type checker's.

A script the manifest does not name stays reportable, which is the useful
signal: after this exclusion the same scan reported exactly one script, and it
was a developer tool wired into nothing.

### Why tool configuration is excluded

ESLint reads `eslint.config.js`, Vitest reads `vitest.config.ts`, pytest reads
`conftest.py`: the tool finds each by filename and no project code imports it,
so its in-degree is zero in every project. A self-scan of a 111-file TypeScript
project returned eight candidates and all eight were configuration of this
shape.

Such modules are classified `tooling.config` (rule `core.tooling.config.v1`)
and, like test modules, are not reported. Recognition is by filename convention
only (`<tool>.config.<ext>`, `<tool>.conf.<ext>`, an `rc` dotfile, `gulpfile`,
`gruntfile`, or `conftest.py`) and deliberately narrow: a module that merely
reads configuration, such as `src/utils/config-loader.ts`, is ordinary source
and stays reportable.

## Acting on a candidate

- Confirm it is genuinely unused, then delete it.
- Record `confirmed_dead` with `annotate_component` when a human or agent has
  verified it but the deletion is not yet scheduled.
- Record `false_positive` when the component is reached in a way the scan cannot
  see. It is dropped from future candidate lists and the count moves to
  `bounds.annotated_false_positives`.
- Use `dead_code_suppressions` for whole families of such components (a
  generated namespace, a plugin directory) rather than annotating each one.

## Limits

- Candidates depend on the selected `edge_kinds` and `min_confidence`. Narrowing
  either produces more candidates, not fewer.
- The scan is bounded by `max_nodes`, `max_edges`, and `timeout_ms`; a truncated
  run reports `truncated` with the reason, and its candidate list is partial.
- `limit` caps the reported list; the counters in `bounds` describe the whole
  examined graph, not the reported slice.
- A reference from a non-code file suppresses the candidate but contributes no
  edge, so `list_usages` will not name the HTML shell, the Compose file or the
  tool config as a dependant. The claiming file is recorded on the role as
  `named_by`.
- A tool config is read only for the keys that name files it loads
  (`setupFiles`, `globalSetup`, `entry`, and their siblings). A path reached
  some other way (built from a variable, or under a key not on that list) is
  still invisible.
