FROM node:26-trixie-slim@sha256:14bf3eac4bf209d906d3c41256597d3ab1f926b2e93a79e9bdfe1efd32454239 AS node_runtime

RUN npm install --global npm@12.0.2 --ignore-scripts --no-audit --no-fund

FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer_runtime

# The Rust worker is compiled here and copied in as a binary, so the shipped
# runtime image carries no Rust toolchain. Same Debian release as the runtime
# stage, so the glibc the binary links against is the one it runs on.
FROM rust:1-slim-trixie@sha256:bce1476d4be4d78b83705bc5f428b86d640eeeea33e9dadafbc037b5703a53bf AS rust_builder
WORKDIR /build
COPY workers/rust ./
RUN cargo build --release --locked

# Everything slow and large lives in the stages from here to quality_tools:
# system packages, PHP extensions, composer and npm dependencies, the Rust
# toolchain and the compiled Rust worker. Their inputs are the files
# tools/quality-deps-key hashes, so CI builds and publishes them once per key
# and each lane adds this commit's source on top in the thin runtime and
# quality stages below. A file copied into one of these stages that the key
# does not hash would reuse a stale image; QualityDepsKeyTest holds the two to
# each other.
FROM php:8.5-cli-trixie@sha256:9ebdf4c28ab12c02085e171c31e22ac5f7bbb6a9f6927e3bc3dfe7ee23df51e0 AS runtime_deps

# The compiler toolchain the base image carries for `docker-php-ext-install` is
# build-only, and it drags in `libc6-dev` -> `linux-libc-dev`. Kernel headers are
# never executed in a container, but Trivy still reports every kernel CVE against
# them, so the runtime image fails the HIGH/CRITICAL gate for something it cannot
# be exploited through. Purge the build deps once the extension is compiled, the
# way the quality stage already does.
# The `apt-get upgrade` here is deliberate, for the same reason DL3008 is ignored: the
# supply-chain gate scans this image with `--ignore-unfixed --severity HIGH,CRITICAL
# --exit-code 1`, so the build fails the moment Debian ships a fix the digest-pinned base
# has not been rebuilt against. That is not hypothetical -- CVE-2026-53615 (util-linux)
# was fixed in 2.41.5-0+deb13u1 while `php:8.5-cli-trixie` still carried 2.41-5, in the
# newest tag as well as in the pinned digest. Upgrading here lets a rebuild pick the fix
# up on its own instead of needing a per-CVE package pin every time.
#
# No `hadolint ignore=DL3005` is needed: hadolint v2.14.0, the version this repository
# pins, no longer implements that rule -- a bare `RUN apt-get update && apt-get upgrade -y`
# reports only DL3009 under it. A suppression here would suppress nothing and would read
# as if the linter still objected.
#
# The upgrade only helps when this layer is actually rebuilt. Its text never changes, so
# a layer cache serves the first build's result indefinitely: CI's buildx cache kept a
# 2026-09-13 layer into October, past an openssl and a pcre2 fix, and the gate failed on
# both. CI passes the date here, so the first build each day runs the upgrade again and
# the rest of that day still hit the cache. Every RUN after an ARG sees it, so a changed
# value is a cache miss from here on.
ARG APT_REFRESH=unset
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install --no-install-recommends -y git libatomic1 libffi-dev libsqlite3-dev python3 unzip \
    && docker-php-ext-install pdo_sqlite ffi \
    # docker-php-ext-ffi.ini only loads the extension; the CLI default ffi.enable=preload refuses
    # FFI::cdef, so the enabling line goes in a separate file the extension install cannot overwrite.
    && printf 'ffi.enable=true\n' > /usr/local/etc/php/conf.d/zz-ffi-enable.ini \
    && apt-get purge -y --auto-remove libffi-dev libsqlite3-dev libc6-dev $PHPIZE_DEPS \
    && apt-get install --no-install-recommends -y libffi8 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node_runtime /usr/local/ /usr/local/
COPY --from=composer_runtime /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/knossos

# The autoloader is written later, by the stage that copies the source: an
# optimised classmap lists the classes under src/, and src/ is not here yet.
# `dump-autoload --optimize` there produces what `install --optimize-autoloader`
# did when the source was present.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader

COPY workers/php/composer.json workers/php/composer.lock ./workers/php/
RUN composer install \
    --working-dir=workers/php \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader

COPY workers/typescript/package.json workers/typescript/package-lock.json ./workers/typescript/
RUN npm ci \
    --prefix workers/typescript \
    --omit=dev \
    --ignore-scripts \
    --no-audit \
    --no-fund

COPY --from=rust_builder /build/target/release/knossos-rust-worker ./workers/rust/bin/
RUN chmod 0755 /opt/knossos/workers/rust/bin/knossos-rust-worker \
    && mkdir -p /data \
    && chown www-data:www-data /data

ENV KNOSSOS_DATA_DIR=/data
ENV NODE_OPTIONS=--max-old-space-size=1024

STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD ["php", "/opt/knossos/bin/knossos", "version", "--json"]

# The shipped image: the dependencies above plus this commit's source. Nothing
# here installs anything, so a source change rebuilds only these layers.
FROM runtime_deps AS runtime

# The labels carry the release version, which release-please bumps in every
# release pull request. They sit in the two source stages, never in a
# dependency stage, and tools/quality-deps-key leaves this block out of the
# key, so a version bump rebuilds only the thin layers.
# x-release-please-start-version
LABEL org.opencontainers.image.title="Knossos" \
      org.opencontainers.image.description="Local evidence-backed architecture intelligence over MCP" \
      org.opencontainers.image.version="0.21.2"
# x-release-please-end

COPY workers/php/src ./workers/php/src
COPY workers/php/bin ./workers/php/bin
COPY workers/typescript/src ./workers/typescript/src
COPY workers/typescript/bin ./workers/typescript/bin

COPY workers/python/bin ./workers/python/bin

COPY bin ./bin
COPY src ./src
COPY migrations ./migrations
COPY schemas ./schemas
# `install-agent-plugin` materialises the plugin out of the installation it is
# run from, reading the manifest, the hook scripts and the skill as templates,
# so an image without them ships a command that cannot do its job. The quality
# stage shellchecks the hook scripts as well, and cannot see a file the image
# does not carry. `types` is the mod's API declaration, which the plugin
# materialises and the quality stage's type-check reads. Keep this list in step
# with PluginCommand's DIRECTORIES, MANIFEST and COPIES, and with the scripts it
# reads by name.
COPY .claude-plugin ./.claude-plugin
COPY hooks ./hooks
COPY skills ./skills
COPY types ./types
RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && composer dump-autoload --working-dir=workers/php --no-dev --optimize --no-interaction --no-scripts \
    && chmod 0755 \
    /opt/knossos/bin/knossos \
    /opt/knossos/workers/php/bin/worker \
    /opt/knossos/workers/typescript/bin/worker.js \
    /opt/knossos/workers/python/bin/worker.py \
    && rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
    && rm -f /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack

USER www-data

ENTRYPOINT ["/opt/knossos/bin/knossos"]
CMD ["help"]

# The quality stage's tooling, on top of the same dependencies the runtime
# stage ships. Every tree an install creates is handed to the knossos user in
# the same RUN that created it (or by COPY --chown), never by a recursive chown
# afterwards: a later chown rewrites every file it touches into a new layer, and
# the one that used to end this stage stored a second 1.17 GB copy of rustup,
# cargo, node_modules and vendor.
FROM runtime_deps AS quality_tools

USER root

# The quality stage installs its tooling as root (Trivy, the docker socket, and
# pcov all need it), but the permission-error tests -- DoctorService's
# unwritable-data-dir path, MigrationRunner's unreadable-migration path, and
# ProjectDiscoverer's permission-denied path -- skip themselves entirely when the
# suite runs as root, so those branches would never execute. tools/quality drops
# to this dedicated non-root user for `composer test` (see run_test_suite there),
# which owns the tree so PHPUnit's cache and coverage output stay writable.
# It is created before anything is installed, so every later step can hand
# what it creates to it directly. /usr/local/cargo and /usr/local/rustup arrive
# owned by it: `cargo audit` needs to write its advisory-db clone under
# CARGO_HOME, and `cargo test`/clippy/llvm-cov all run as this user.
RUN useradd --system --create-home --home-dir /home/knossos --shell /usr/sbin/nologin knossos

# pcov is built from a checksum-pinned GitHub tarball rather than installed with
# `pecl install`. pecl.php.net is repeatedly unreachable from GitHub-hosted
# runners ("No releases available for package"), which broke the image build for
# reasons unrelated to the change under test. Every other third-party binary in
# this stage is already fetched by URL and verified by SHA-256; pcov now matches.
# GitHub's release and codeload edges occasionally return a transient 5xx, so
# every download retries before the checksum turns any partial response into a
# hard failure.
#
# The pinned Python tools live in their own venv, so a Debian-installed Python
# package (python3-packaging and friends) can never block their install.
RUN apt-get update \
    && apt-get install --no-install-recommends -y ca-certificates curl docker-cli python3-venv shellcheck $PHPIZE_DEPS \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /tmp/pcov.tar.gz \
        https://codeload.github.com/krakjoe/pcov/tar.gz/refs/tags/v1.0.12 \
    && printf '%s  %s\n' fdd07cad8e2ff42f0c9f095d84aeef11dab0fde7a008805f61883cbcb1b3f12b /tmp/pcov.tar.gz > /tmp/pcov.sha256 \
    && sha256sum --check --strict /tmp/pcov.sha256 \
    && mkdir -p /tmp/pcov \
    && tar -xzf /tmp/pcov.tar.gz -C /tmp/pcov --strip-components=1 \
    && cd /tmp/pcov \
    && phpize \
    && ./configure --enable-pcov \
    && make -j"$(nproc)" \
    && make install \
    && cd / \
    && rm -rf /tmp/pcov /tmp/pcov.tar.gz /tmp/pcov.sha256 \
    && docker-php-ext-enable pcov \
    && docker-php-ext-install pcntl \
    && python3 -m venv /opt/quality-python \
    && /opt/quality-python/bin/pip install --no-cache-dir \
        coverage==7.14.3 mypy==2.3.0 pre-commit==4.6.0 pytest==8.4.2 ruff==0.15.12 \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /usr/local/bin/hadolint \
        https://github.com/hadolint/hadolint/releases/download/v2.14.0/hadolint-linux-x86_64 \
    && printf '%s  %s\n' 6bf226944684f56c84dd014e8b979d27425c0148f61b3bd99bcc6f39e9dc5a47 /usr/local/bin/hadolint > /tmp/hadolint.sha256 \
    && sha256sum --check --strict /tmp/hadolint.sha256 \
    && chmod 0755 /usr/local/bin/hadolint \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/hadolint.sha256

# The runtime stage removes npm, npx and corepack from the image it ships;
# this stage keeps npm and npx for the JavaScript suites and drops corepack the
# same way, so the two images differ only in what the quality tools need.
RUN rm -rf /usr/local/lib/node_modules/corepack \
    && rm -f /usr/local/bin/corepack

# The venv's bin directory leads PATH, so `python3` resolves to it as well and
# `python3 -m coverage` / `python3 -m pytest` see the pinned tools.
ENV PATH="/opt/quality-python/bin:${PATH}"

RUN curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /tmp/trivy.tar.gz \
        https://github.com/aquasecurity/trivy/releases/download/v0.69.3/trivy_0.69.3_Linux-64bit.tar.gz \
    && printf '%s  %s\n' 1816b632dfe529869c740c0913e36bd1629cb7688bd5634f4a858c1d57c88b75 /tmp/trivy.tar.gz \
        > /tmp/trivy.sha256 \
    && sha256sum --check --strict /tmp/trivy.sha256 \
    && tar -xzf /tmp/trivy.tar.gz -C /usr/local/bin trivy \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /usr/local/bin/cosign \
        https://github.com/sigstore/cosign/releases/download/v3.0.6/cosign-linux-amd64 \
    && printf '%s  %s\n' c956e5dfcac53d52bcf058360d579472f0c1d2d9b69f55209e256fe7783f4c74 /usr/local/bin/cosign \
        > /tmp/cosign.sha256 \
    && sha256sum --check --strict /tmp/cosign.sha256 \
    && chmod 0755 /usr/local/bin/trivy /usr/local/bin/cosign \
    && rm -f /tmp/trivy.tar.gz /tmp/trivy.sha256 /tmp/cosign.sha256

# Debian ships the client as `docker-cli` (trixie split it out of `docker.io`,
# which now carries only the daemon) and without the Compose plugin, so
# `tools/quality` would skip or fail its `docker compose config` gate. Install
# the plugin explicitly.
RUN mkdir -p /usr/libexec/docker/cli-plugins \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /usr/libexec/docker/cli-plugins/docker-compose \
        https://github.com/docker/compose/releases/download/v5.3.1/docker-compose-linux-x86_64 \
    && printf '%s  %s\n' f9ebc6ebdb19d769b793c245a736caaeb198c62587f13b25c660c13b4987f959 \
        /usr/libexec/docker/cli-plugins/docker-compose > /tmp/compose.sha256 \
    && sha256sum --check --strict /tmp/compose.sha256 \
    && chmod 0755 /usr/libexec/docker/cli-plugins/docker-compose \
    && rm -f /tmp/compose.sha256

COPY --chown=knossos:knossos package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund \
    && chown -R knossos:knossos node_modules

# The runtime_deps stage installs the TypeScript worker with --omit=dev;
# reinstall with dev dependencies so the vitest suite can run in this stage.
RUN npm --prefix workers/typescript ci --ignore-scripts --no-audit --no-fund \
    && chown -R knossos:knossos workers/typescript/node_modules


# The Claude Code CLI runs `claude plugin validate` and `claude plugin test` for
# the mod in tools/quality's tests lane. It needs no authentication for either.
# The version is pinned, and the pin moves together with the mod's API types
# (types/index.d.ts and the engine declarations the type-check reads), on
# purpose: an unpinned install would let a CLI release change what the gate
# checks without any commit here saying so. The package ships a stub and puts
# the native binary in place from its postinstall script, which this stage's
# npm 12 does not run for a dependency on its own, so it is run explicitly.
# Without it `claude` exits with "native binary not installed".
RUN npm install --global @anthropic-ai/claude-code@2.1.287 --no-audit --no-fund \
    && node /usr/local/lib/node_modules/@anthropic-ai/claude-code/install.cjs \
    && claude --version

# The quality profile runs cargo fmt, clippy, the crate's tests, and llvm-cov, so
# this stage needs the toolchain the runtime stage deliberately does not ship.
COPY --from=rust_builder --chown=knossos:knossos /usr/local/rustup /usr/local/rustup
COPY --from=rust_builder --chown=knossos:knossos /usr/local/cargo /usr/local/cargo
ENV RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo PATH=/usr/local/cargo/bin:$PATH

# cargo-llvm-cov and cargo-audit are fetched as checksum-pinned GitHub release
# binaries, the same way Trivy and cosign are above. llvm-tools-preview is the
# rustup component cargo-llvm-cov instruments coverage with. gcc/libc6-dev give
# the toolchain a linker: `cargo test`/`clippy` need to actually build the
# crate (including proc-macro build scripts), unlike the runtime stage's
# rust_builder, which links its release binary inside its own base image.
# Unlike the pcov build's $PHPIZE_DEPS above, this is not purged afterwards --
# the quality profile needs a working linker for the lifetime of the container.
RUN apt-get update \
    && apt-get install --no-install-recommends -y gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /tmp/llvm-cov.tar.gz \
        https://github.com/taiki-e/cargo-llvm-cov/releases/download/v0.9.0/cargo-llvm-cov-x86_64-unknown-linux-gnu.tar.gz \
    && printf '%s  %s\n' b068f7c98841aacb9c4f382b4a0c184ae82f49b56a32d442b429b2961c73be15 /tmp/llvm-cov.tar.gz \
        > /tmp/llvm-cov.sha256 \
    && sha256sum --check --strict /tmp/llvm-cov.sha256 \
    && tar -xzf /tmp/llvm-cov.tar.gz -C /usr/local/cargo/bin cargo-llvm-cov \
    && chmod 0755 /usr/local/cargo/bin/cargo-llvm-cov \
    && rm -f /tmp/llvm-cov.tar.gz /tmp/llvm-cov.sha256 \
    && curl --fail --location --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors \
        --output /tmp/cargo-audit.tar.gz \
        https://github.com/rustsec/rustsec/releases/download/cargo-audit%2Fv0.22.2/cargo-audit-x86_64-unknown-linux-gnu-v0.22.2.tgz \
    && printf '%s  %s\n' ab28a1bdb54db4d5d8ad5981cf1f959410370b3d28250dbd35f6a44248620e39 /tmp/cargo-audit.tar.gz \
        > /tmp/cargo-audit.sha256 \
    && sha256sum --check --strict /tmp/cargo-audit.sha256 \
    && tar -xzf /tmp/cargo-audit.tar.gz -C /usr/local/cargo/bin --strip-components=1 \
        cargo-audit-x86_64-unknown-linux-gnu-v0.22.2/cargo-audit \
    && chmod 0755 /usr/local/cargo/bin/cargo-audit \
    && chown knossos:knossos /usr/local/cargo/bin/cargo-llvm-cov /usr/local/cargo/bin/cargo-audit \
    && rm -f /tmp/cargo-audit.tar.gz /tmp/cargo-audit.sha256

# The components land under RUSTUP_HOME, which the knossos user owns, so they
# are installed as that user and need no chown afterwards.
USER knossos
RUN rustup component add clippy rustfmt llvm-tools-preview
USER root

RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    && chown -R knossos:knossos vendor

# What runtime_deps created as root and the suite runs as knossos: the PHP
# worker's dependencies (2 MB), the Rust worker binary, the manifests, and the
# directories the source stage copies into. The two recursive chowns copy only
# those small trees into this layer.
RUN chown -R knossos:knossos workers/php/vendor workers/rust/bin \
    && chown knossos:knossos \
        /opt/knossos \
        /opt/knossos/workers \
        /opt/knossos/workers/php \
        /opt/knossos/workers/typescript \
        /opt/knossos/workers/rust \
        composer.json composer.lock \
        workers/php/composer.json workers/php/composer.lock \
        workers/typescript/package.json workers/typescript/package-lock.json

ENV KNOSSOS_QUALITY_CONTAINER=1
ENV DOCKER_API_VERSION=1.44

# The quality image: the tools above plus this commit's source, last so that a
# source change rebuilds only this stage. CI pulls quality_tools for the run's
# dependency key and builds this stage on top of it in each lane, which takes
# seconds; nothing here installs anything or rewrites a whole tree.
FROM quality_tools AS quality

# x-release-please-start-version
LABEL org.opencontainers.image.title="Knossos" \
      org.opencontainers.image.description="Local evidence-backed architecture intelligence over MCP" \
      org.opencontainers.image.version="0.21.2"
# x-release-please-end

COPY --chown=knossos:knossos workers/php/src ./workers/php/src
COPY --chown=knossos:knossos workers/php/bin ./workers/php/bin
COPY --chown=knossos:knossos workers/typescript/src ./workers/typescript/src
COPY --chown=knossos:knossos workers/typescript/bin ./workers/typescript/bin
COPY --chown=knossos:knossos workers/python/bin ./workers/python/bin
COPY --chown=knossos:knossos bin ./bin
COPY --chown=knossos:knossos src ./src
COPY --chown=knossos:knossos migrations ./migrations
COPY --chown=knossos:knossos schemas ./schemas
COPY --chown=knossos:knossos .claude-plugin ./.claude-plugin
COPY --chown=knossos:knossos hooks ./hooks
COPY --chown=knossos:knossos skills ./skills
COPY --chown=knossos:knossos types ./types

# The development classmap covers src/ and the dependencies, and is written
# before tests/ is copied so it matches what the suite has always loaded. The
# knossos user owns vendor, so it writes the autoloader and the files stay its.
# COMPOSER_HOME points away from /home/knossos, where composer would otherwise
# leave a cache directory the image never had.
USER knossos
RUN COMPOSER_HOME=/tmp/composer-home composer dump-autoload --optimize --no-interaction --no-scripts \
    && COMPOSER_HOME=/tmp/composer-home composer dump-autoload --working-dir=workers/php --no-dev --optimize --no-interaction --no-scripts \
    && rm -rf /tmp/composer-home
USER root

COPY --chown=knossos:knossos workers/typescript/vitest.config.js ./workers/typescript/
# The mod's vitest suite (`npm run test:mod`) reads this at the root.
COPY --chown=knossos:knossos vitest.config.mjs ./
COPY --chown=knossos:knossos workers/rust ./workers/rust

COPY --chown=knossos:knossos .editorconfig .hadolint.yaml .trivyignore .php-cs-fixer.dist.php .prettierignore .markdownlint-cli2.jsonc ./
# DockerIgnoreTest holds .dockerignore to every directory .gitignore anchors at
# the root, and CI runs it in this image, so both files have to be here for the
# check to run at all. Only this stage copies them: the runtime image ships
# exactly what it did before.
COPY --chown=knossos:knossos .gitignore .dockerignore ./
COPY --chown=knossos:knossos eslint.config.js phpstan.neon pyproject.toml .pre-commit-config.yaml .coveragerc ./
COPY --chown=knossos:knossos phpunit.xml infection.json5 ./
COPY --chown=knossos:knossos README.md CONTRIBUTING.md CHANGELOG.md LICENSE ./
COPY --chown=knossos:knossos version.txt release-please-config.json .release-please-manifest.json ./
COPY --chown=knossos:knossos coverage-budgets.json ./
COPY --chown=knossos:knossos maintainability-budgets.json ./
# The `gate` lane scans this repository and holds it to its own budgets, which
# live here along with the boundaries and policies the scan needs to reproduce
# the numbers those budgets were set from.
COPY --chown=knossos:knossos knossos.json ./
COPY --chown=knossos:knossos Dockerfile ./
COPY --chown=knossos:knossos docker-compose.yml .env.example ./
COPY --chown=knossos:knossos docs ./docs
COPY --chown=knossos:knossos plugins ./plugins
COPY --chown=knossos:knossos benchmarks ./benchmarks
COPY --chown=knossos:knossos tests ./tests
COPY --chown=knossos:knossos workers/python/tests ./workers/python/tests
COPY --chown=knossos:knossos tools ./tools
COPY --chown=knossos:knossos .github ./.github
RUN chmod 0755 \
    bin/knossos \
    workers/php/bin/worker \
    workers/typescript/bin/worker.js \
    workers/python/bin/worker.py \
    tools/quality tools/quality-container tools/quality-deps-key tools/install-hooks tools/coverage tools/benchmark tools/supply-chain tools/release-lifecycle tools/scanner-conformance tools/phpunit-shard

ENTRYPOINT ["/opt/knossos/tools/quality"]
CMD ["fast"]
