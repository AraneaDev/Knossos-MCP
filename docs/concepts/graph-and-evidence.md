# The graph and its evidence

Every answer Knossos gives comes from one stored graph of your project, and
every fact in it points back at a file and a line. This page explains what a
scan puts in that graph, how to read the evidence, confidence and origin that
come with each fact, what a static scan cannot prove, and what it never does to
your project. Which question each tool answers is on the pages that follow,
starting with [structure analysis](structure-analysis.md).

## What a scan produces

A scan walks the project, hands the source files to a worker per language, and
writes what the workers found into SQLite, in the data directory and never in
your project. You get these kinds of fact:

| Fact       | What it is                                                                                                                                      |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Component  | A node: a module, class, interface, trait, enum, function, method, property, package, route or similar. Each has a stable ID and a kind.        |
| Edge       | A relationship between two components: `calls`, `imports`, `extends`, `implements`, `constructs`, `injects`, `references`, `contains` and more. |
| File       | A source file the scan read, with its path, language, size, line count and content hash.                                                        |
| Boundary   | A named group of components, either declared by you or inferred from the project's manifests and namespaces.                                    |
| Role       | A classification of one component, such as `quality.test_module` or `application.service`, with the rule that assigned it.                      |
| Diagnostic | A warning or error about the scan itself: a file too large, a symlink skipped, a syntax error.                                                  |
| Snapshot   | One complete scan. The newest is the active graph. Earlier ones are archived for [history](history.md).                                         |

Run `knossos architecture-summary` on a scanned project to see the kinds and
counts for your own graph. A scan of a mixed PHP and TypeScript codebase
produces mostly methods, classes and functions, joined mostly by `calls`,
`contains` and `constructs` edges.

### Components and edges

A component is identified by its language, kind and canonical name, so the same
symbol keeps the same ID across rescans. An edge is an occurrence, not a
summary: its ID includes the place in the source where the relationship was
written. Two calls from one method to the same target, on different lines, are
two edges. That is why [`list_usages`](finding-components.md#list-usages) can
list call sites one by one.

`contains` edges record structure only: a class holds its methods.
The queries that ask what depends on what use a fixed set of dependency kinds
instead: `routes_to`, `calls`, `dispatches`, `handles`, `listens_to`,
`constructs`, `injects`, `binds`, `observes`, `depends_on`, `imports`,
`uses_middleware`, `references`, `extends`, `implements`, `returns`, `exports`,
`re_exports` and `uses_trait`. Pass `edge_kinds` to narrow them. `contains` is
rejected there.

When code refers to something outside the scanned tree, such as a framework
class or a package that is not installed, the edge still needs a target. The
scan creates a stand-in component whose kind starts with `external_`, marks it
`possible` and `unresolved`, and keeps the edge. Rankings leave these out unless
you ask for them with `include_external`.

### Boundaries and roles

A boundary groups components so you can ask questions about modules rather than
single symbols. You declare boundaries in
[`knossos.json`](../get-started/project-configuration.md) or with `--boundary`
on `knossos scan`. Without them, Knossos infers boundaries from what the
project already says about itself: one per manifest of a Cargo, Composer, Node or Python project (`composer:<name>`), one per top-level PHP namespace (`namespace:App`), one per top-level directory of TypeScript code (`module:src`) and one per top-level directory of Python code (`python-package:<directory>`). A TypeScript project's own config path names its boundary.
Every boundary carries a `source` of `explicit` or `inferred`, so an inferred
grouping is never mistaken for a decision someone made. A component can sit in
more than one boundary. `list_boundaries` shows them, and
[declared rules](architecture-rules.md) are written against them.

<!-- still:boundaries -->

Roles come from classification rules that run after the workers: file-name and
path conventions, framework conventions, manifest entry points and test
modules. A role tells later queries how to treat a component. A test module
stays out of the hub ranking, and a controller is not reported as dead code
just because nothing calls it.

## Evidence

Evidence is the file and line range that justifies a fact. Every component and
every edge carries one, and the scanner protocol rejects a fact without it.

```json
{
    "kind": "implements",
    "origin": "ast",
    "confidence": "certain",
    "evidence": {
        "path": "src/Scanner/Worker/ProcessScannerClient.php",
        "start_line": 10,
        "end_line": 10
    }
}
```

The path is relative to the project root and the lines are one-based. An answer
is therefore checkable: open the file at that line and the relationship is
there, or it is not. The same shape appears in `via` on impact results, on each
hop of a flow, on each row of `list_usages`, and in the `evidence` array of the
[response envelope](../reference/response-envelopes.md). Over MCP the default
`compact` verbosity keeps the first three evidence records and collapses `via`
to the edge kind. Ask for `verbosity: "full"` to get everything, which is what
`--json` on the CLI prints.

## Confidence and origin

Confidence says how sure the scanner was. Origin says where the fact came from.

| Confidence | Meaning                                                                                                 |
| ---------- | ------------------------------------------------------------------------------------------------------- |
| `certain`  | The scanner resolved it directly: a declaration, an import it could follow, a call on a typed receiver. |
| `probable` | The scanner inferred it, for example a call on a variable whose type came from an assignment.           |
| `possible` | The scanner could not resolve it. Stand-ins for external code are always `possible`.                    |

| Origin                 | Where the fact came from                                                              |
| ---------------------- | ------------------------------------------------------------------------------------- |
| `ast`                  | Parsed straight from source. Most of any graph.                                       |
| `framework_convention` | A recognised framework pattern, such as a route table or a decorator.                 |
| `derived`              | Worked out by Knossos from other facts: external stand-ins and most role assignments. |

The scanner protocol also accepts `composer` and `config` for custom scanners.
Every query that takes `min_confidence` filters weaker facts out and never
upgrades them. A path is only as strong as its weakest edge, which is why
impact and flow results report `path_confidence`.

## What static analysis cannot prove

A scan reads source text. It does not run anything, so anything decided while
the program runs is outside what it can see:

- reflection, dynamic dispatch and calls through a name held in a variable;
- wiring that lives in configuration, a container or a registry array;
- code generated or loaded at runtime, and dynamic imports;
- decorators, macros and monkey-patching whose effect depends on execution;
- anything in a dependency that is not part of the scan.

Each language page lists what its worker does and does not resolve. See
[TypeScript](../languages/typescript.md), [PHP and Laravel](../languages/php-laravel.md),
[Python](../languages/python.md) and [Rust](../languages/rust.md).

Knossos labels the limits instead of hiding them:

- **Confidence.** An edge the scanner had to guess is `probable` or `possible`,
  and you can filter on it.
- **Warnings.** Every result carries `warnings` that qualify it. Impact says it
  is a conservative blast radius, a flow says it is a plausible static path,
  and dead-code results say they are candidates only.
- **Absence proves nothing.** A flow query with no path returns an empty list.
  An unreferenced component is a candidate, and a component the scan could not
  type is demoted to `possible`. See [dead-code candidates](dead-code-candidates.md).
- **Bounds.** Every walk has limits on depth, nodes, edges and time. Hitting
  one sets `truncated` and names the reason in `bounds`, so a partial answer
  never passes for a complete one.
- **Diagnostics.** A file the scan skipped or could not parse becomes a
  diagnostic with a code, so a gap in the graph has an explanation.
- **Type-only imports.** An import that exists only for the compiler is dropped when looking for cycles, so a loop closed only by such imports is not reported. Impact, flow, hub and policy checks still count it.

Treat the graph as strong evidence about what the code says and silent about
what the runtime does.

## What the scan never does

The scan treats your project as untrusted input:

- It does not install dependencies. If `node_modules` or `vendor` is missing,
  references into them become `external_` stand-ins instead of an error.
- It does not import or run your code, and does not boot a framework or start a
  bundler. The workers parse: the TypeScript worker uses the compiler's
  analysis, the PHP worker a parser, the Python worker the standard-library
  `ast` module and the Rust worker a syntax parser.
- It does not follow symlinks. A link is skipped with a diagnostic, whether it
  points inside the project or out of it.
- It does not read what you told it to ignore. Version-control and dependency
  directories (`.git`, `node_modules`, `vendor`, virtual environments, build
  output) are skipped by default, `.gitignore` is honoured, and `ignores` in
  `knossos.json` adds to both. By default a file over 2,000,000 bytes is
  skipped with a warning, and a project over 100,000 files fails the scan
  instead of scanning part of it.
- It does not hand your environment to the workers. Each worker is a separate
  process with a neutral working directory, a short allow-list of environment
  variables, a request timeout and, for the PHP and TypeScript workers, a memory limit.
- It does not replace a good graph with a bad one. A scan that fails or is
  cancelled leaves the previous snapshot active, and `list_projects` shows the
  state as `latest_scan_failed` or `latest_scan_cancelled`.

Configuration and text files such as manifests, HTML files and READMEs are read
as text to find the paths they name, never executed. Git is only run read-only,
for the commit a scan matches and for the change signals in
[change review](change-review.md).

## Where to go next

- [Structure analysis](structure-analysis.md) walks the graph: impact, flow,
  cycles, hotspots and placement.
- [Finding components](finding-components.md) is the read surface.
- [Declared rules and budgets](architecture-rules.md) turns boundaries into
  checks.
- [Scan history](history.md) compares one snapshot with another.
