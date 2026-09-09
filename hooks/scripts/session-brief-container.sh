#!/bin/sh
# Session brief for a containerised Knossos installation.
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# The project is mounted at the same path inside the container as outside. That
# is not cosmetic: projects are keyed by `root_realpath`, so a project scanned
# as /work would never match a session starting in /home/me/project.
set -u

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$PWD}"
IMAGE="__KNOSSOS_IMAGE__"
DATA="__KNOSSOS_DATA__"

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

# Longer than the local timeout because container startup is not free, but still
# bounded: a session start must never wait on a stuck daemon.
if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" 10 docker run --rm \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
else
    # Neither `timeout` nor `gtimeout` exists here, so this one call has no
    # bound of its own. The backstop is the harness: hooks.json sets this
    # hook's own "timeout" to 15, and Claude Code enforces that ceiling on
    # the whole process regardless of what runs inside it.
    OUTPUT="$(docker run --rm \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
