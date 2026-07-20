# Changelog

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
