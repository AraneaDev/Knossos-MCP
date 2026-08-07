# Streamable HTTP transport and threat model

Status: local-first, serving MCP `2026-07-28` and the deprecated `2025-11-25`.
The existing stdio transport remains the recommended default.

The implementation exposes one `/mcp` endpoint through PHP's HTTP router and
reuses the same `ToolService` and JSON-RPC dispatch as stdio. POST responses use
`application/json`; server-sent event streams and server-initiated requests are
not implemented. GET therefore returns 405.

Which revision a request belongs to is decided by its `MCP-Protocol-Version`
header, and the two differ in ways that matter to this threat model:

- **`2026-07-28` has no sessions.** `Mcp-Session-Id` is ignored rather than
  minted or echoed, and both GET and DELETE answer 405. The session-fixation and
  replay surface below does not exist for these clients.
- **`2025-11-25` keeps sessions**, server-minted, stored only as SHA-256 hashes
  in SQLite, expiring after 30 minutes, capped at 1,000, and closable with
  authenticated DELETE. This path is retained only for clients that have not
  migrated and is scheduled for removal once the specification's twelve-month
  deprecation window closes (no earlier than 2027-07).

`2026-07-28` also requires each POST to mirror selected body fields into
headers — `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` for `tools/call`,
`resources/read`, and `prompts/get` — and requires the server to reject any
disagreement with `400` and JSON-RPC `-32020`. Knossos validates all three,
decoding the `=?base64?…?=` sentinel before comparing. This closes a
confused-deputy: an intermediary that routes or rate-limits on the header while
the server acts on the body would otherwise apply policy to one tool while a
different one executes.

That mirroring is also a new control available to operators. A reverse proxy in
front of Knossos can now allow-list read-only tools by `Mcp-Name`, or deny
`scan_project` and `remove_project` outright, without parsing request bodies —
and the server's own validation is what makes those header values trustworthy.
Note the specification's caveat: an intermediary enforcing policy this way
**should** first confirm that `MCP-Protocol-Version` names a revision that
requires header/body validation, and reject the request otherwise, rather than
trusting unvalidated headers from an older client.

The protocol requires Origin validation to mitigate DNS rebinding and advises
local servers to bind only to loopback with authentication. See the official
[transport security requirements](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http)
and [HTTP authorization specification](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).

## Threats and controls

| Threat                          | Control                                                                                                                                                                                                                         | Residual limitation                                                                                                                                           |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DNS rebinding / hostile browser | Exact Host and Origin allowlists on POST and DELETE; no wildcard or suffix matching.                                                                                                                                            | Non-browser clients may omit Origin, so Host/auth remain mandatory controls.                                                                                  |
| Accidental network exposure     | Documented/default bind is `127.0.0.1`; default hosts/origins are loopback only.                                                                                                                                                | PHP's router bind is operator-controlled; do not use `0.0.0.0` casually.                                                                                      |
| Unauthorized model/tool access  | Optional constant-time pre-shared Bearer check on every request. Non-loopback deployment requires a token and TLS proxy policy.                                                                                                 | This local profile is not an OAuth authorization server and exposes no OAuth discovery metadata. Use a conforming gateway for multi-user/Internet deployment. |
| CSRF                            | Origin rejection plus Authorization header; JSON content type only.                                                                                                                                                             | A compromised allowed client retains its granted tool authority.                                                                                              |
| Host/proxy confusion            | Exact single Host value; comma-combined/forwarded host values are rejected. Proxy must rewrite Host to an explicitly configured value.                                                                                          | Forwarded headers are intentionally not trusted.                                                                                                              |
| Session fixation/replay         | Not applicable under `2026-07-28`, which has no sessions. Under `2025-11-25`, initialization rejects client-supplied session IDs; 256-bit random IDs are hashed at rest, expire, and are capacity-limited.                      | Bearer/session theft within the TTL enables replay on the legacy path; terminate TLS at a trusted local proxy.                                                |
| Header/body confusion           | `2026-07-28` requests must mirror `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` into headers; each is validated against the body (Base64 sentinel decoded first) and any mismatch or omission returns 400 with `-32020`. | Legacy `2025-11-25` requests carry no such mirroring, so an intermediary must check the version header before enforcing policy on header values.              |
| Request/response flood          | 1 MiB request and response caps, strict JSON object framing, schema limits, scan/query/worker caps, no-store responses.                                                                                                         | PHP/web-server limits should be set at least as strictly upstream.                                                                                            |
| Slow/idle clients               | Web server handles socket timeouts; MCP sessions have fixed idle expiry.                                                                                                                                                        | PHP's development server is single-process and unsuitable for hostile production traffic.                                                                     |
| Concurrent scans                | Existing per-project SQLite writer leases serialize mutation while WAL readers keep the active snapshot available.                                                                                                              | PHP development server itself serializes requests; use a controlled multi-worker proxy/runtime for concurrency.                                               |
| Cancellation                    | Cancellation notifications receive 202 and do not corrupt state. Scan transactions remain atomic and worker timeouts apply.                                                                                                     | The stateless PHP request profile cannot interrupt an already-running request; clients must rely on configured timeouts.                                      |
| SSE/session stream abuse        | SSE and GET streams are unsupported and return 405; sessions carry lifecycle only.                                                                                                                                              | Clients requiring server notifications, resumability, or SSE must use another compliant deployment adapter.                                                   |
| Path/project-code attack        | Existing allowed-root canonicalization, read-only mounts, no target execution, worker isolation, and stable diagnostics apply unchanged.                                                                                        | Git history may expose author emails in results to an already authorized client.                                                                              |

## Running locally

Native:

```sh
KNOSSOS_ALLOWED_ROOTS=/absolute/project \
KNOSSOS_HTTP_BEARER_TOKEN='replace-with-a-random-secret' \
php -S 127.0.0.1:8080 bin/http-router.php
```

Docker (source remains read-only and data remains separate):

```sh
docker run --rm -p 127.0.0.1:8080:8080 \
  --entrypoint php \
  -e KNOSSOS_ALLOWED_ROOTS=/workspace \
  -e KNOSSOS_HTTP_BEARER_TOKEN='replace-with-a-random-secret' \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev -S 0.0.0.0:8080 /opt/knossos/bin/http-router.php
```

The loopback-only published port limits exposure; Docker's bridge is required
for host access to that port. For custom ports/hosts, set `KNOSSOS_HTTP_ALLOWED_HOSTS` and
`KNOSSOS_HTTP_ALLOWED_ORIGINS` to exact comma-separated values. Never place the
token in a URL. For non-loopback or multi-user access, put a TLS/OAuth-capable
gateway in front, restrict the upstream network, and keep exact Host/Origin
values rather than broad wildcards.

## Git subprocesses

`changed_files_impact`, `test_impact`, `review_diff`, and `change_impact` run
`git` inside the scanned project. Git reads that repository's `.git/config`,
which can name commands Git executes — `core.fsmonitor` during any index
refresh, `diff.external` during diff generation, `core.pager` for output.
`GitProcessRunner` forces all three off via `-c` overrides and runs the child
with an explicit environment (`GIT_CONFIG_NOSYSTEM=1`,
`GIT_CONFIG_GLOBAL=/dev/null`, no inherited variables).

This matters whenever a repository directory arrives with its own `.git/`
rather than from a fresh `clone`: CI artifacts, extracted archives, container
volumes, restored backups.
