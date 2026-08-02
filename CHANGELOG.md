# Changelog

## [0.10.2](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.1...v0.10.2) (2026-08-02)


### Bug Fixes

* **mcp:** drop project staleness from server-scoped tools ([4e094bc](https://github.com/AraneaDev/Knossos-MCP/commit/4e094bcaf2484667ce6108e3cc02873b0cc820bf))
* **query,scanner:** address the review findings on flow, tokens and reads ([d1c3cd3](https://github.com/AraneaDev/Knossos-MCP/commit/d1c3cd31ab99a2e237caf38b7b33842bc910737e))
* **query:** dedupe flow paths and exclude script modules from dead code ([1c68473](https://github.com/AraneaDev/Knossos-MCP/commit/1c684735fe2f3798bff964863b054072cdf60eeb))
* **query:** drop test components from the agent brief's entry points ([63eb105](https://github.com/AraneaDev/Knossos-MCP/commit/63eb105846a607be36ed8af114f01dc4bc2593cd))
* **query:** grow diagrams along relationships and draw one arrow per edge ([0d77ed3](https://github.com/AraneaDev/Knossos-MCP/commit/0d77ed3c0753636cfb4297516bab687095e10307))
* **query:** let the architecture context summary reclaim spare budget ([0ca844e](https://github.com/AraneaDev/Knossos-MCP/commit/0ca844ed21a1f969c7dcef06f082af603cf8b5c3))
* **query:** rank locations by relevance density and match identifier words ([a0afb61](https://github.com/AraneaDev/Knossos-MCP/commit/a0afb6113c04f86586cf576f24966243c8de19e9))
* **query:** report the true usage total when results are capped ([a08f282](https://github.com/AraneaDev/Knossos-MCP/commit/a08f28284ac25c18b09d2736bfee4af15e998e31))
* **query:** stop the quality gate counting entry scripts as unreferenced ([aa4a084](https://github.com/AraneaDev/Knossos-MCP/commit/aa4a084dba3d085f6874a5c7fb2f34c9e8ff80e7))
* **query:** treat a returned interface as the contract a factory satisfies ([57fb18d](https://github.com/AraneaDev/Knossos-MCP/commit/57fb18d286ca3d5aff77f7392df0b3945b15e81c))
* **reconciliation:** resolve deferred receivers through traits and ancestors ([36c9570](https://github.com/AraneaDev/Knossos-MCP/commit/36c9570f86b1dbfd8318161d88195aaaef61511d))
* scanner and query accuracy findings from real-project runs ([185deaa](https://github.com/AraneaDev/Knossos-MCP/commit/185deaa4ac705e080f798e7ee7309dacaec85320))
* **scanner:** attribute PHP file-scope calls to a file module node ([f082738](https://github.com/AraneaDev/Knossos-MCP/commit/f082738311cf812e574a2c1c1faf311ecaa8aad5))
* **scanner:** key React and Vue roles on JSX and imports, not file extension ([8f3824e](https://github.com/AraneaDev/Knossos-MCP/commit/8f3824e4155f74a500e3707e4b266cac7c93dd94))
* **scanner:** mark Python and TypeScript script modules executable ([413299c](https://github.com/AraneaDev/Knossos-MCP/commit/413299c9608e2e42d7ba14cbe3271635c7c02c8e))
* **scanner:** resolve unqualified function calls against their namespace ([ca7aff7](https://github.com/AraneaDev/Knossos-MCP/commit/ca7aff78a3ee11acd92e3a6a126e9596f6ecb6f0))
* **scanner:** spell the byte-order mark out instead of importing codecs ([4d7cb9a](https://github.com/AraneaDev/Knossos-MCP/commit/4d7cb9abb982d7ab63baf0e8ce48f202017c7d7e))

## [0.10.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.0...v0.10.1) (2026-08-02)


### Bug Fixes

* **scanner:** resolve Python calls through their receiver and load the TypeScript compiler lazily ([#32](https://github.com/AraneaDev/Knossos-MCP/issues/32)) ([e9793dc](https://github.com/AraneaDev/Knossos-MCP/commit/e9793dc4d126e9d2da0cd4c2d2b8b45aa73ea096))

## [0.10.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.9.1...v0.10.0) (2026-08-02)


### Features

* **scanner:** resolve calls on values a method in another file returned ([5881a70](https://github.com/AraneaDev/Knossos-MCP/commit/5881a70f921529f4bf7c402563f51ca3de629a13))
* **scanner:** resolve cross-file receivers, and fix what auditing Knossos with Knossos found ([076e3ec](https://github.com/AraneaDev/Knossos-MCP/commit/076e3ec8d1bb122a864264c14d94df709e761d32))


### Bug Fixes

* address review findings and cover the paths the floors caught ([f2fd27c](https://github.com/AraneaDev/Knossos-MCP/commit/f2fd27c367509bd7390e71ee3591a87b8da4d22c))
* address the second review round ([02ba8ae](https://github.com/AraneaDev/Knossos-MCP/commit/02ba8aeccfdecaddfc88c5e2d60b375a72abf131))
* **php-scanner:** resolve receivers typed by a method's declared return type ([6a5bb60](https://github.com/AraneaDev/Knossos-MCP/commit/6a5bb6048cca18af5812b944208d58f608a13a53))
* **query:** count quality-gate budgets over the components health reports ([c2c973b](https://github.com/AraneaDev/Knossos-MCP/commit/c2c973b14990cb236539ca637ae714f616fff1ac))
* **query:** scan every edge when the quality gate checks boundary policies ([008258b](https://github.com/AraneaDev/Knossos-MCP/commit/008258b6807cd6560435434d5db4dfb4a311f93f))
* **query:** stop the repository-wide boundary from hiding every cross-boundary edge ([4a46eb0](https://github.com/AraneaDev/Knossos-MCP/commit/4a46eb06219322946a6bcec78e74607d4ce612c5))
* **runtime:** treat supported runtime versions as floors, not ranges ([6e39b3a](https://github.com/AraneaDev/Knossos-MCP/commit/6e39b3a96e0f049ac93b496fb8f68a33462d2ce3))


### Performance Improvements

* **query:** stream the boundary-policy check instead of collecting its edges ([722b7cd](https://github.com/AraneaDev/Knossos-MCP/commit/722b7cd0e5db466a7c9aee4b3b3f1e411002f2d7))
* **reconciliation:** report the commit as a phase of its own ([f3fd82d](https://github.com/AraneaDev/Knossos-MCP/commit/f3fd82de6da767bac2fc56228e2bcdb0fedb8018))
* **reconciliation:** write the graph difference instead of rewriting the graph ([9f5662d](https://github.com/AraneaDev/Knossos-MCP/commit/9f5662de817d87d582daefea416f419d73c3c477))
* **store:** cap the write-ahead log so its peak size is not permanent ([7ab1fae](https://github.com/AraneaDev/Knossos-MCP/commit/7ab1fae71845b647af8c7ff41bac86cd8864d2bf))
* **store:** compress archived snapshots and add a vacuum action ([b13c210](https://github.com/AraneaDev/Knossos-MCP/commit/b13c2108375a131c7a8929851bcb4ab5168b9f28))
* **store:** compress the snapshot as it is read rather than building it whole ([a3136d3](https://github.com/AraneaDev/Knossos-MCP/commit/a3136d37974b39fd5923725b8fa9209a5df7deba))
* **store:** verify graph integrity once per rewrite instead of per statement ([a788f84](https://github.com/AraneaDev/Knossos-MCP/commit/a788f841d2442b9b7d260c7b3b7ee36e55a49d05))

## [0.9.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.9.0...v0.9.1) (2026-08-01)


### Bug Fixes

* **boundary:** keep external packages out of path-prefix boundaries ([3562cae](https://github.com/AraneaDev/Knossos-MCP/commit/3562cae40239faa7931bbeaa1c0075d5d6974bbb))
* **boundary:** keep external packages out of path-prefix boundaries ([f48f9e6](https://github.com/AraneaDev/Knossos-MCP/commit/f48f9e6503654d7081f27cf3e22682d66e19f03f))

## [0.9.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.8.0...v0.9.0) (2026-07-30)


### Features

* **quality:** enforce docstring coverage, and fix what running Knossos over Knossos found ([#26](https://github.com/AraneaDev/Knossos-MCP/issues/26)) ([4e7027a](https://github.com/AraneaDev/Knossos-MCP/commit/4e7027aa5b252333238f786c344cb09570c16d2e))

## [0.8.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.7.0...v0.8.0) (2026-07-30)


### Features

* **discovery:** resolve allowed roots per request so one install serves any project ([2e1f9cf](https://github.com/AraneaDev/Knossos-MCP/commit/2e1f9cf5ca72b9efaa77d137a68ebc429182f0d3))
* **mcp:** serve protocol revision 2026-07-28 alongside 2025-11-25 ([5bda580](https://github.com/AraneaDev/Knossos-MCP/commit/5bda5808bf5f67f5456ea94501a23a70685e8f6a))
* **mcp:** support protocol 2026-07-28 and serve any project from one install ([0886ed1](https://github.com/AraneaDev/Knossos-MCP/commit/0886ed10d36855ece88ae129485d48a213b7d4f3))


### Bug Fixes

* **discovery:** detect narrowed roots by content, and preserve a "/" grant ([0dde7d3](https://github.com/AraneaDev/Knossos-MCP/commit/0dde7d3b089ecdad23d5e0a2e5c5d61532f82134))
* **discovery:** one grant per directory, and safe JSON on non-UTF-8 paths ([9392f2b](https://github.com/AraneaDev/Knossos-MCP/commit/9392f2b28758fffe5795e5d368c22e7c4eaca88a))
* **quality:** check every interface contract, not only those one level deep ([156286d](https://github.com/AraneaDev/Knossos-MCP/commit/156286db8469d11cf4693be51ffd1396619f5284))
* **quality:** run the covered suite unprivileged, and stop the deliberate 500 printing a SQLSTATE ([f32716c](https://github.com/AraneaDev/Knossos-MCP/commit/f32716c1eaa504d12d1ace80f76959df52bae48b))
* **tests:** declare nullable test-helper parameters explicitly ([3451996](https://github.com/AraneaDev/Knossos-MCP/commit/3451996d44ba3ff5a64523903829cf0b066222fb))

## [0.7.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.6.0...v0.7.0) (2026-07-27)


### Features

* **classification:** tag manifest entry points so scripts stop reading as dead ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Bug Fixes

* **classification:** drop unreachable guard and pin the path rules ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **cli:** render CLI diagnostics to an injectable stream ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **discovery:** keep a leading dot in a nested manifest's directory ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **docs-gate:** check every doc file instead of skipping an ignored path ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **health:** exclude contract declarations an implementation carries ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **health:** stop reporting engine-invoked members and tool config as dead ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **php-scanner:** edge calls on constructed and nullsafe receivers ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **quality:** fail on warnings so mutation scores stop reporting phantoms ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **query:** exclude constructors of referenced types from dead-code candidates ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **query:** stop reporting dead code from a truncated edge scan ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve member references through used traits ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve members through parents and interfaces ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve type references to interface, enum, and trait declarations ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **review:** harden the PR title check and stop overclaiming it ([5058f25](https://github.com/AraneaDev/Knossos-MCP/commit/5058f253b471aa64aa06def368a69abac1200507))
* **scanner:** emit references for callables used as values ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **scanner:** reference the class named by PHP static access ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** anchor the API documentation gate to real interface declarations ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** bound chaos-loop's --timeoutMs below Node's timer limit ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** raise the MCP request timeout in chaos-loop ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **ts-worker:** free a program cache slot before building the next program ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Build System

* **deps:** move the TypeScript worker to vitest 4 ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **deps:** take the patched brace-expansion in the quality lockfile, clearing GHSA-mh99-v99m-4gvg ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Continuous Integration

* require conventional pull request titles ([3db958e](https://github.com/AraneaDev/Knossos-MCP/commit/3db958e015971a94771937436681701d8db5d655))

## [0.6.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.5.1...v0.6.0) (2026-07-24)


### Features

* **mcp:** token-efficient response envelopes for agents ([#18](https://github.com/AraneaDev/Knossos-MCP/issues/18)) ([e2e4060](https://github.com/AraneaDev/Knossos-MCP/commit/e2e406034311e14c99c7051b5afbc7ba6a27a63b))

## [0.5.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.5.0...v0.5.1) (2026-07-24)


### Bug Fixes

* **mcp:** keep idle stdio connections warm with ping keepalives ([#16](https://github.com/AraneaDev/Knossos-MCP/issues/16)) ([a6e1955](https://github.com/AraneaDev/Knossos-MCP/commit/a6e1955f9aac705b6bf88faeb8c45453ccd5c5d9))

## [0.5.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.4.0...v0.5.0) (2026-07-23)


### Features

* **reconcile:** report per-phase timings through scan stage metrics ([7a03322](https://github.com/AraneaDev/Knossos-MCP/commit/7a0332254c46d3a6827f5314ab57558962513e1d))
* **scan:** persist failed and cancelled scans (audit batch 10) ([703e4a6](https://github.com/AraneaDev/Knossos-MCP/commit/703e4a69ef9a934f19bcd5d8be6ac12e55863898))


### Bug Fixes

* **boundary:** keep merged suffix display-only; stable id from primary rule name ([76e97f9](https://github.com/AraneaDev/Knossos-MCP/commit/76e97f9a1529b728045efb832f1ee4b7ca3b7494))
* **boundary:** merge inferred rules sharing an identical matcher ([18c5e1c](https://github.com/AraneaDev/Knossos-MCP/commit/18c5e1ca9e467c565b7f00f46ab422dd28c4db54))
* **boundary:** only symbol-shaped nodes seed inferred prefix rules ([a3bbc23](https://github.com/AraneaDev/Knossos-MCP/commit/a3bbc23f0a21864e045c87a7dbc55bce8914a102))
* **bundle:** validate untrusted imports and torn exports (audit batch 8) ([cd0287a](https://github.com/AraneaDev/Knossos-MCP/commit/cd0287adb343e0ecd5b221cdd9c43a49d1722077))
* **ci:** copy workers/python/tests into quality image so pytest collects them ([6b88580](https://github.com/AraneaDev/Knossos-MCP/commit/6b885805ba620d37b2a7ca09c0b6ce246af8ed5a))
* **cli:** option validation, git/doctor robustness, boundary anchoring (audit batch 7) ([b46aef3](https://github.com/AraneaDev/Knossos-MCP/commit/b46aef3f802102c878d58c630aca5aa98f62fa1e))
* **flow:** expand class endpoints to contained members in explain_flow ([0d329a8](https://github.com/AraneaDev/Knossos-MCP/commit/0d329a8cfdfc248676e916ecbf95d9eacd3e87a6))
* **mcp:** transport resilience, session safety, protocol conformance (audit batch 3) ([76f2d5d](https://github.com/AraneaDev/Knossos-MCP/commit/76f2d5dc6752ac6cfe6316983fad4bd95addad94))
* **query:** correctness, truncation honesty, and bounded fan-out (audit batch 2) ([6b3ab0f](https://github.com/AraneaDev/Knossos-MCP/commit/6b3ab0ff10ad85018c832b25903b970d2568b2ea))
* reconcile branch with upstream 0.4.0 (post-rebase) ([2ef14a1](https://github.com/AraneaDev/Knossos-MCP/commit/2ef14a18a933485e0d80b1373fc9bfaf95679569))
* **reconcile:** scope duplicate-symbol warning to non-shared kinds, once per id ([5e5d2be](https://github.com/AraneaDev/Knossos-MCP/commit/5e5d2beee83eb1c8375643907aaf48af5891f1bc))
* **reconcile:** start prepare phase timer before pre-transaction work ([768d29f](https://github.com/AraneaDev/Knossos-MCP/commit/768d29f411623c2b13838de6c10a22a796258f69))
* resolve four findings from testing the MCP against itself ([1843aed](https://github.com/AraneaDev/Knossos-MCP/commit/1843aed2d77d1e1b192d42814f6cb575bec95874))
* **scan:** fast-path staleness, discovery resilience, lock safety (audit batch 4) ([2283e6e](https://github.com/AraneaDev/Knossos-MCP/commit/2283e6ee431e5aaa72749685072e3bb849baa7c5))
* **suggest:** filter stop words and short tokens with permissive fallback ([ed85ec3](https://github.com/AraneaDev/Knossos-MCP/commit/ed85ec3b89e085948f293cd02f197a6d4936cd58))
* **test:** inject KNOSSOS_PHP_COVERAGE_DIR into php worker via env wrapper ([d7b2e80](https://github.com/AraneaDev/Knossos-MCP/commit/d7b2e809dc45ee391a370496556cbd89fcfae38b))
* **test:** repair audit-batch permission/DB tests exposed by non-root run ([6ca24d9](https://github.com/AraneaDev/Knossos-MCP/commit/6ca24d9d1435f6ad917498f6aa9711a3204dd792))
* **worker:** resolve send/crash/orphan/watch defects (audit batch 5) ([925b525](https://github.com/AraneaDev/Knossos-MCP/commit/925b525d3417d76c7354453577a343227ca4570a))
* **workers:** harden PHP/TS/Python scanners against hostile source (audit batch 6) ([9fd352e](https://github.com/AraneaDev/Knossos-MCP/commit/9fd352ed1c920a563e5abfb713591fbb09f0bd60))


### Performance Improvements

* **reconcile:** batch fact inserts; remove dead deleteFactsByOwner ([df9d516](https://github.com/AraneaDev/Knossos-MCP/commit/df9d516bb5b5d1945c707fdd03e4f9d78e76afc9))
* **reconcile:** clear_graph — 24179ms -&gt; 541ms on self-scan ([3c23d02](https://github.com/AraneaDev/Knossos-MCP/commit/3c23d02530d16d33b3f24504354c049e0febecd8))
* **store:** fix O(N^2) reconciliation and harden transactions (audit batch 1) ([9541d98](https://github.com/AraneaDev/Knossos-MCP/commit/9541d983bee5d11c5aa9e78441b38384713b0b74))

## [0.4.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.3.0...v0.4.0) (2026-07-23)


### Features

* **config:** checked-in dead_code_suppressions honored by architecture_health ([45f9206](https://github.com/AraneaDev/Knossos-MCP/commit/45f920652c9d328e91bccc66e21d75d9d06ab008))
* **mcp:** advertise and serve per-project resources (summary, boundaries, agent brief) ([f2c203e](https://github.com/AraneaDev/Knossos-MCP/commit/f2c203e1a5a3f9773edf93bbe53c64b5bd0183df))
* **mcp:** boundary legend in compact responses and single dead-code uncertainty warning ([554deba](https://github.com/AraneaDev/Knossos-MCP/commit/554deba5b63f61789127a6dfc609cbdbe71e6f96))
* **mcp:** honor a max_chars result budget on every read tool ([69d72a3](https://github.com/AraneaDev/Knossos-MCP/commit/69d72a325baf379073685ab348ee4b88f62e72a9))
* **mcp:** opt-in refresh_if_stale rescans stale graphs before answering read tools ([2e6541c](https://github.com/AraneaDev/Knossos-MCP/commit/2e6541cee838a30626d5c0cb7b0163435e06a6ce))
* **mcp:** orient and review_diff prompts ([3f8e053](https://github.com/AraneaDev/Knossos-MCP/commit/3f8e0534ff32a392c2e9f175b8f9b858eec7c6e5))
* **query:** annotations shape dead-code candidates and component dossiers ([86544c6](https://github.com/AraneaDev/Knossos-MCP/commit/86544c6db797ad5f351dc7314082f5dba9a80bf0))
* **query:** architecture_context optionally inlines RootGuard-contained source snippets ([858f69c](https://github.com/AraneaDev/Knossos-MCP/commit/858f69c94ee5fd118603528bfbda196d7fb32ba5))
* **query:** exclude external and test-role nodes from hubs and hotspots by default ([bf72640](https://github.com/AraneaDev/Knossos-MCP/commit/bf726401f3d7caf5e6979a1dd662ceb8752e4a0a))
* **query:** exclude interface-implementing methods from dead-code candidates and demote external-hierarchy methods ([9b5e577](https://github.com/AraneaDev/Knossos-MCP/commit/9b5e577c158a70d520dea5c848696752733cb706))
* **query:** export_agent_brief renders a markdown orientation brief for agent memory files ([81b95f4](https://github.com/AraneaDev/Knossos-MCP/commit/81b95f431e635321b37b81ae06d4a042e4585596))
* **query:** list_usages returns every usage occurrence with file:line evidence ([73524f5](https://github.com/AraneaDev/Knossos-MCP/commit/73524f5773ed246c5add27591b9fc5c85347c8a4))
* **query:** review_diff composes change impact, policy, gate, and cycle review in one call ([491c63e](https://github.com/AraneaDev/Knossos-MCP/commit/491c63e2cfd036056a550c4974705c11c68ff6c2))
* **query:** test_impact ranks test files that statically exercise a change ([ce47325](https://github.com/AraneaDev/Knossos-MCP/commit/ce47325f9f5a083d71162234d867972df68b1259))
* **store:** durable component annotations with annotate_component and list_annotations tools ([34675b6](https://github.com/AraneaDev/Knossos-MCP/commit/34675b6780511c08fb231d01bbc5fe1f909b22db))


### Bug Fixes

* address CodeRabbit review on PR [#11](https://github.com/AraneaDev/Knossos-MCP/issues/11) ([1a7ab3c](https://github.com/AraneaDev/Knossos-MCP/commit/1a7ab3cdaca521b4eabb9f8c3dcb7533251319c1))
* **cli:** wire the git working-tree provider into review-diff ([884b89d](https://github.com/AraneaDev/Knossos-MCP/commit/884b89de2e3700d86231ae93f25e66e320426d41))
* **mcp:** enforce max_chars against the full serialized envelope and always surface unmet budgets ([5b32e33](https://github.com/AraneaDev/Knossos-MCP/commit/5b32e33db9c7368ae347415d1b05277ff6d896d7))
* **mcp:** express BoundaryLegend list narrowing to phpstan ([c517132](https://github.com/AraneaDev/Knossos-MCP/commit/c5171328cd500f537c8f221122d6c2e345e1cd50))
* **query:** make export_agent_brief max_chars a hard bound and prove section omission in tests ([fcc1e89](https://github.com/AraneaDev/Knossos-MCP/commit/fcc1e89bc7a4cee3f8ef575247de11f4e6714f0b))
* **query:** refuse non-positive start lines in source excerpts; scope README parity claim to tools ([c0ba8eb](https://github.com/AraneaDev/Knossos-MCP/commit/c0ba8eb1438e2401c66afec42f0c89e489d204e2))
* **query:** review_diff degrades cycle-scan failures and unions sub-check warnings and evidence ([f33e957](https://github.com/AraneaDev/Knossos-MCP/commit/f33e9578c877c6f3d6041d82df24187428894876))
* **query:** scope ancestor metadata lookup by project in inheritedMethodContext ([1ad3363](https://github.com/AraneaDev/Knossos-MCP/commit/1ad336352a8ab8ae58002c97acfebc226eb29fbc))
* scope node uniqueness by language and log MCP server lifecycle ([4d16cfc](https://github.com/AraneaDev/Knossos-MCP/commit/4d16cfc8189a94794e0acfb8c224155f94ae3ed9))
* **test:** assert VERSION against version.txt instead of a release-breaking literal ([317e762](https://github.com/AraneaDev/Knossos-MCP/commit/317e76256b16d93fd63348f909231643d6e62b8b))


### Performance Improvements

* **scan:** skip graph rebuild and snapshot archiving when an incremental scan changed nothing ([1b75700](https://github.com/AraneaDev/Knossos-MCP/commit/1b757000971e53a003dba828c7cf5789b4e0e68e))
* **store:** batch node and edge upserts during reconciliation ([f98f562](https://github.com/AraneaDev/Knossos-MCP/commit/f98f56224ac25c3552d1d4fa7a92bfd272411cd5))

## [0.3.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.2.0...v0.3.0) (2026-07-20)


### Features

* **quality:** add a PHPUnit suite and Infection mutation testing ([8edcea9](https://github.com/AraneaDev/Knossos-MCP/commit/8edcea9291e74c29c0067a3d9260650978827bd1))
* **quality:** add a PHPUnit suite and Infection mutation testing ([c7e939b](https://github.com/AraneaDev/Knossos-MCP/commit/c7e939b3625486e5111ad8a2ffc4fb1a0d104875))

## [0.2.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.1.0...v0.2.0) (2026-07-20)


### Features

* **docker:** add compose profiles for CLI, MCP stdio, and loopback HTTP ([b5cfa19](https://github.com/AraneaDev/Knossos-MCP/commit/b5cfa19e2224ab319d4bd4ec583edf1a17e27057))
* **envelope:** add optional staleness, next_steps, meta enrichment fields ([b63a610](https://github.com/AraneaDev/Knossos-MCP/commit/b63a6108a373461f7f1d3370fa62087d5ca510ec))
* **mcp:** add NextStepPlanner for per-tool follow-up suggestions ([f237d6a](https://github.com/AraneaDev/Knossos-MCP/commit/f237d6ab457daaacd8ab610702f601377244f9dd))
* **mcp:** add ResultEnricher composing staleness, next_steps, verbosity, meta ([d14c0ad](https://github.com/AraneaDev/Knossos-MCP/commit/d14c0ad318be948c2033010086e7f1546485311c))
* **mcp:** commit portable project-scoped stdio registration ([3971886](https://github.com/AraneaDev/Knossos-MCP/commit/3971886945aaf5964006d7f893d212634f336b7b))
* **mcp:** enrich all tool results with staleness, next_steps, verbosity, meta ([953a7d5](https://github.com/AraneaDev/Knossos-MCP/commit/953a7d51abeb20c742494293abf4e9359b485936))
* **mcp:** intent-first tool descriptions and verbosity input; regenerate reference ([129cc31](https://github.com/AraneaDev/Knossos-MCP/commit/129cc312915a88eb4511857d8134e91de4860f4f))
* **query:** add read-only StalenessProbe for project freshness ([a83882b](https://github.com/AraneaDev/Knossos-MCP/commit/a83882ba3e1188c06e469e5a7cc2d541f42c9563))


### Bug Fixes

* **ci:** build pcov from a checksum-pinned tarball instead of pecl ([eccae29](https://github.com/AraneaDev/Knossos-MCP/commit/eccae2965124250529435d4aaacb8da6dad0c96b))
* **ci:** build pcov from a checksum-pinned tarball instead of pecl ([b7e9055](https://github.com/AraneaDev/Knossos-MCP/commit/b7e9055b6a36bc2488a456615e0d6bc5f2d428c5))
* **ci:** drop the MCP Observatory link waiver now that the badge resolves ([77d3a54](https://github.com/AraneaDev/Knossos-MCP/commit/77d3a545da6de20facc8520091ee47256f8550bd))
* **ci:** drop the MCP Observatory link waiver now that the badge resolves ([b9cffd0](https://github.com/AraneaDev/Knossos-MCP/commit/b9cffd05c1c5144afb16693bbebca525d2e6a36f))
* **ci:** stop binding host paths from inside the quality container ([188e93b](https://github.com/AraneaDev/Knossos-MCP/commit/188e93b727aaaf80224714ba521159e0e61e86f4))
* **cli:** emit architecture-summary JSON payload once ([7b0a147](https://github.com/AraneaDev/Knossos-MCP/commit/7b0a1470ed4544dc6ac87d5f56ecab68212c0f52))
* **discovery:** exclude build output and mutation-test sandboxes ([7982e46](https://github.com/AraneaDev/Knossos-MCP/commit/7982e46e0a50107b378e5b573d5c1e999cb9460f))
* **docker:** copy compose file and MCP registration into the quality image ([c61ab10](https://github.com/AraneaDev/Knossos-MCP/commit/c61ab10be875ee40cbd10b1186d36732dc6a41f8))
* **docker:** install pinned compose plugin in quality stage ([de40f44](https://github.com/AraneaDev/Knossos-MCP/commit/de40f44115038e316829c9af992862170c083806))
* **docker:** purge build headers so the runtime image clears the CVE gate ([6170cb6](https://github.com/AraneaDev/Knossos-MCP/commit/6170cb6fa8be77ce32253c627de41799c312a674))
* **query:** exclude test modules from dead-code nomination ([ab8a5e9](https://github.com/AraneaDev/Knossos-MCP/commit/ab8a5e93886a2b23540dfd8215a80ea203b280fb))
* **query:** report dead-code reasons as unfound, not proven absent ([e2bfbd2](https://github.com/AraneaDev/Knossos-MCP/commit/e2bfbd2a35174389f2c5bb728a417672f390e4a4))
* **ts-scanner:** prevent OOM and timeouts on real TypeScript projects ([7dcbc56](https://github.com/AraneaDev/Knossos-MCP/commit/7dcbc56ad0d02e3c1bbc3da95629e4575c95ffaa))
