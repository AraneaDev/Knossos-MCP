FROM node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d AS node_runtime

RUN npm install --global npm@12.0.1 --ignore-scripts --no-audit --no-fund

FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer_runtime

FROM php:8.4-cli-bookworm@sha256:138a210978c7767ef2a26f499c413fe6de1c13233c9a5068139565c81191b1ac AS runtime

# x-release-please-start-version
LABEL org.opencontainers.image.title="Knossos MCP" \
      org.opencontainers.image.description="Local evidence-backed architecture intelligence over MCP" \
      org.opencontainers.image.version="0.5.0"
# x-release-please-end

# The compiler toolchain the base image carries for `docker-php-ext-install` is
# build-only, and it drags in `libc6-dev` -> `linux-libc-dev`. Kernel headers are
# never executed in a container, but Trivy still reports every kernel CVE against
# them, so the runtime image fails the HIGH/CRITICAL gate for something it cannot
# be exploited through. Purge the build deps once the extension is compiled, the
# way the quality stage already does.
RUN apt-get update \
    && apt-get install --no-install-recommends -y git libsqlite3-dev python3 unzip \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev libc6-dev $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node_runtime /usr/local/ /usr/local/
COPY --from=composer_runtime /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/knossos

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY workers/php/composer.json workers/php/composer.lock ./workers/php/
COPY workers/php/src ./workers/php/src
COPY workers/php/bin ./workers/php/bin
RUN composer install \
    --working-dir=workers/php \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY workers/typescript/package.json workers/typescript/package-lock.json ./workers/typescript/
RUN npm ci \
    --prefix workers/typescript \
    --omit=dev \
    --ignore-scripts \
    --no-audit \
    --no-fund
COPY workers/typescript/src ./workers/typescript/src
COPY workers/typescript/bin ./workers/typescript/bin

COPY workers/python/bin ./workers/python/bin

COPY bin ./bin
COPY src ./src
COPY migrations ./migrations
COPY schemas ./schemas
RUN chmod 0755 \
    /opt/knossos/bin/knossos \
    /opt/knossos/workers/php/bin/worker \
    /opt/knossos/workers/typescript/bin/worker.js \
    /opt/knossos/workers/python/bin/worker.py \
    && mkdir -p /data \
    && chown -R www-data:www-data /data /opt/knossos \
    && rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
    && rm -f /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack

USER www-data

ENV KNOSSOS_DATA_DIR=/data
ENV NODE_OPTIONS=--max-old-space-size=512

STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD ["php", "/opt/knossos/bin/knossos", "version", "--json"]

ENTRYPOINT ["/opt/knossos/bin/knossos"]
CMD ["help"]

FROM runtime AS quality

USER root

COPY --from=node_runtime /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# pcov is built from a checksum-pinned GitHub tarball rather than installed with
# `pecl install`. pecl.php.net is repeatedly unreachable from GitHub-hosted
# runners ("No releases available for package"), which broke the image build for
# reasons unrelated to the change under test. Every other third-party binary in
# this stage is already fetched by URL and verified by SHA-256; pcov now matches.
RUN apt-get update \
    && apt-get install --no-install-recommends -y ca-certificates curl docker.io python3-pip shellcheck $PHPIZE_DEPS \
    && curl --fail --location --silent --show-error \
        --output /tmp/pcov.tar.gz \
        https://github.com/krakjoe/pcov/archive/refs/tags/v1.0.12.tar.gz \
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
    && python3 -m pip install --break-system-packages --no-cache-dir \
        coverage==7.14.3 mypy==2.3.0 pre-commit==4.6.0 pytest==8.4.2 ruff==0.15.12 \
    && curl --fail --location --silent --show-error \
        --output /usr/local/bin/hadolint \
        https://github.com/hadolint/hadolint/releases/download/v2.14.0/hadolint-linux-x86_64 \
    && printf '%s  %s\n' 6bf226944684f56c84dd014e8b979d27425c0148f61b3bd99bcc6f39e9dc5a47 /usr/local/bin/hadolint > /tmp/hadolint.sha256 \
    && sha256sum --check --strict /tmp/hadolint.sha256 \
    && chmod 0755 /usr/local/bin/hadolint \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/hadolint.sha256

RUN curl --fail --location --silent --show-error \
        --output /tmp/trivy.tar.gz \
        https://github.com/aquasecurity/trivy/releases/download/v0.69.3/trivy_0.69.3_Linux-64bit.tar.gz \
    && printf '%s  %s\n' 1816b632dfe529869c740c0913e36bd1629cb7688bd5634f4a858c1d57c88b75 /tmp/trivy.tar.gz \
        > /tmp/trivy.sha256 \
    && sha256sum --check --strict /tmp/trivy.sha256 \
    && tar -xzf /tmp/trivy.tar.gz -C /usr/local/bin trivy \
    && curl --fail --location --silent --show-error \
        --output /usr/local/bin/cosign \
        https://github.com/sigstore/cosign/releases/download/v3.0.6/cosign-linux-amd64 \
    && printf '%s  %s\n' c956e5dfcac53d52bcf058360d579472f0c1d2d9b69f55209e256fe7783f4c74 /usr/local/bin/cosign \
        > /tmp/cosign.sha256 \
    && sha256sum --check --strict /tmp/cosign.sha256 \
    && chmod 0755 /usr/local/bin/trivy /usr/local/bin/cosign \
    && rm -f /tmp/trivy.tar.gz /tmp/trivy.sha256 /tmp/cosign.sha256

# Debian's `docker.io` ships the CLI without the Compose plugin, so `tools/quality`
# would skip (or fail) its `docker compose config` gate. Install the plugin explicitly.
RUN mkdir -p /usr/libexec/docker/cli-plugins \
    && curl --fail --location --silent --show-error \
        --output /usr/libexec/docker/cli-plugins/docker-compose \
        https://github.com/docker/compose/releases/download/v5.3.1/docker-compose-linux-x86_64 \
    && printf '%s  %s\n' f9ebc6ebdb19d769b793c245a736caaeb198c62587f13b25c660c13b4987f959 \
        /usr/libexec/docker/cli-plugins/docker-compose > /tmp/compose.sha256 \
    && sha256sum --check --strict /tmp/compose.sha256 \
    && chmod 0755 /usr/libexec/docker/cli-plugins/docker-compose \
    && rm -f /tmp/compose.sha256

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund

# The runtime stage installs the TypeScript worker with --omit=dev; reinstall
# with dev dependencies so the vitest suite can run in this stage.
RUN npm --prefix workers/typescript ci --ignore-scripts --no-audit --no-fund
COPY workers/typescript/vitest.config.js ./workers/typescript/

RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY .editorconfig .hadolint.yaml .trivyignore .php-cs-fixer.dist.php .prettierignore .markdownlint-cli2.jsonc ./
COPY eslint.config.js phpstan.neon pyproject.toml .pre-commit-config.yaml .coveragerc ./
COPY phpunit.xml infection.json5 ./
COPY README.md CONTRIBUTING.md LICENSE ./
COPY version.txt release-please-config.json .release-please-manifest.json ./
COPY coverage-budgets.json ./
COPY maintainability-budgets.json ./
COPY Dockerfile ./
COPY docker-compose.yml .env.example .mcp.json ./
COPY docs ./docs
COPY benchmarks ./benchmarks
COPY tests ./tests
COPY workers/python/tests ./workers/python/tests
COPY tools ./tools
COPY .github ./.github
RUN chmod 0755 tools/quality tools/quality-container tools/install-hooks tools/coverage tools/benchmark tools/supply-chain tools/release-lifecycle tools/scanner-conformance

# The quality stage installs its tooling as root (Trivy, the docker socket, and
# pcov all need it), but the permission-error tests -- DoctorService's
# unwritable-data-dir path, MigrationRunner's unreadable-migration path, and
# ProjectDiscoverer's permission-denied path -- skip themselves entirely when the
# suite runs as root, so those branches would never execute. tools/quality drops
# to this dedicated non-root user for `composer test` (see run_test_suite there),
# which owns the tree so PHPUnit's cache and coverage output stay writable.
RUN useradd --system --create-home --home-dir /home/knossos --shell /usr/sbin/nologin knossos \
    && chown -R knossos:knossos /opt/knossos /home/knossos

ENV KNOSSOS_QUALITY_CONTAINER=1
ENV DOCKER_API_VERSION=1.44

ENTRYPOINT ["/opt/knossos/tools/quality"]
CMD ["fast"]
