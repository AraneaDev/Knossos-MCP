# Supply-chain and release assurance

Knossos treats the runtime image as the release artifact and the larger quality
image as a development environment. Run the reproducible assurance gate with:

```sh
tools/quality-container full
```

The full profile builds both images and invokes `tools/supply-chain`. Reports
are written to `coverage/supply-chain/`:

- `runtime.cdx.json` and `quality.cdx.json` are CycloneDX SBOMs.
- `runtime-vulnerabilities.json` fails the gate for fixed HIGH or CRITICAL
  findings. The development-only quality image is reported separately because
  it deliberately contains Docker, compilers, linters, and audit tools that are
  not shipped in the runtime.
- `dockerfile-misconfigurations.json` enforces HIGH and CRITICAL container
  configuration findings. `.trivyignore` contains the reviewed, narrow
  exception for the quality stage to access a mounted Docker socket as root.
- `provenance.json` records the immutable image IDs and SHA-256 digests of the
  Dockerfile and dependency lock files.
- `provenance.sigstore.json` is the Cosign bundle produced and verified by the
  gate.

The quality image installs Trivy and Cosign from versioned release artifacts
only after verifying checked-in SHA-256 expectations in the Dockerfile. Local
and pull-request validation uses a freshly generated, encrypted ephemeral
Cosign key to prove the complete signing and verification path without storing
release credentials. Published releases should replace that local key with the
project's protected keyless or hardware-backed identity and retain transparency
log verification.

## Install, upgrade, and rollback lifecycle

`tools/release-lifecycle` creates a new named data volume, runs installation and
doctor checks, scans a read-only mixed-language fixture, creates an atomic
backup, repeats the scan as an idempotent upgrade/migration check, restores the
backup, and verifies that architecture queries still succeed. It always removes
its temporary container and volume. When invoked from the quality container,
`KNOSSOS_HOST_WORKSPACE` maps nested Docker mounts back to the host checkout.

These tests demonstrate the supported data lifecycle; they do not replace
retaining external backups before production upgrades. See
[maintenance](maintenance.md) for backup and integrity operations and
[installation](../guides/installation.md) for runtime deployment.

## Reviewed release checklist

1. Run the full pinned quality profile from a clean checkout.
2. Review runtime SBOM, vulnerability, configuration, benchmark, mutation, and
   coverage reports.
3. Confirm the runtime image ID matches the provenance subject.
4. Sign the final immutable release digest with the protected release identity
   and verify its transparency-log record.
5. Exercise backup, upgrade, restore, and architecture query verification
   against the intended release image.
