#!/bin/sh
# Brief commands for the Claude Code mod, against a containerised Knossos installation.
#
# Usage: knossos-run-container.sh <turn-brief|dashboard> <project-dir> [options...]
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# Same contract as knossos-run.sh: every failure exits 0 with nothing on stdout.
#
# The project is mounted at the same path inside the container as outside. That
# is not cosmetic: projects are keyed by `root_realpath`, so a project scanned
# as /work would never match a session starting in /home/me/project.
set -u

[ "$#" -ge 2 ] || exit 0
SUBCOMMAND=$1
PROJECT_DIR=$2
shift 2

IMAGE="__KNOSSOS_IMAGE__"
DATA="__KNOSSOS_DATA__"

# The same bounds as the local wrapper, so a stuck daemon never stalls the mod.
case "$SUBCOMMAND" in
    turn-brief) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60} ;;
    dashboard) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    *) exit 0 ;;
esac

# The working directory and the argument must name the same place. `docker run`
# gives the container its image's own working directory rather than this one,
# so the bind mount below is what actually carries the project in; entering it
# here keeps a caller from mounting one directory and reading another.
CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0

command -v docker >/dev/null 2>&1 || exit 0

# `timeout` is GNU coreutils. A plain macOS ships none of it, and Homebrew's
# coreutils installs the same tool as `gtimeout` so it never shadows a BSD
# tool of the same name. Try both names before giving up the bound.
find_timeout() {
    if command -v timeout >/dev/null 2>&1; then
        command -v timeout
        return 0
    fi
    if command -v gtimeout >/dev/null 2>&1; then
        command -v gtimeout
        return 0
    fi
    return 1
}

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" "$LIMIT" docker run --rm \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$SUBCOMMAND" "$PROJECT_DIR" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$(docker run --rm \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$SUBCOMMAND" "$PROJECT_DIR" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
