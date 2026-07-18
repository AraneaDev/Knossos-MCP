# Optional semantic location ranking

`suggest_location` defaults to `ranking_mode: deterministic`. This mode is
offline, reproducible, inspectable, and always available.

Library integrators may inject an implementation of
`Knossos\Query\SemanticRanker` into `ArchitectureQueryService` and request
`semantic_if_available`. The provider receives only the bounded feature text
and candidate boundary text and must return one finite normalized score from 0
through 1 for every candidate within the supplied timeout. Knossos adds at most
20 semantic points to the deterministic factor score and reports the provider,
applied mode, and semantic factor.

The packaged CLI/MCP runtime deliberately configures no external provider, so
an opt-in semantic request reports `provider_unavailable` and returns the exact
deterministic ordering. Missing candidates, extra candidates, non-numeric or
out-of-range scores, exceptions, and deadline overruns produce the same exact
fallback with a bounded reason. Project code is never sent or executed by the
core; a custom provider is responsible for its own data-handling policy.
