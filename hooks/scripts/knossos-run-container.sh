#!/bin/sh
# Brief commands for the Claude Code mod, against a containerised Knossos installation.
#
# Usage: knossos-run-container.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run-container.sh component-detail <project-dir> <name>
#        knossos-run-container.sh file-detail <project-dir> <file>
#        knossos-run-container.sh scan <project-dir>
#        knossos-run-container.sh allow-root <root>
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# Same contract as knossos-run.sh: every failure exits 0 with nothing on stdout,
# except a missing docker, which prints {"status":"no-binary"}.
#
# `watch` is not offered here and answers with silence, so the mod falls back
# to scanning at the end of a turn: a container outlives the docker client
# that started it unless that client forwards every signal, and a watcher left
# running after its session would poll the project for nobody.
#
# The project is mounted at the same path inside the container as outside. That
# is not cosmetic: projects are keyed by `root_realpath`, so a project scanned
# as /work would never match a session starting in /home/me/project.
set -u

[ "$#" -ge 2 ] || exit 0
SUBCOMMAND=$1
PROJECT_DIR=$2
shift 2

IMAGE='__KNOSSOS_IMAGE__'
DATA='__KNOSSOS_DATA__'

# The same bounds as the local wrapper, so a stuck daemon never stalls the mod.
# The binary's command is the subcommand's name, except `scan`: the pane's
# rescan runs `knossos rescan`, never `knossos scan`, so it can only rescan an
# existing project in an allowed root and never create one.
COMMAND=$SUBCOMMAND
case "$SUBCOMMAND" in
    turn-brief) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60} ;;
    scan) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60}; COMMAND=rescan ;;
    # A cold first dashboard of a large project walks the whole graph.
    dashboard) LIMIT=${KNOSSOS_RUN_TIMEOUT:-30} ;;
    component-detail|file-detail) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    allow-root) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    *) exit 0 ;;
esac

# component-detail takes exactly one component name, which may not read as an
# option: `--db=...` would point the read at another graph.
if [ "$SUBCOMMAND" = component-detail ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in -*) exit 0 ;; esac
fi
# file-detail takes exactly one file, relative to the project directory: an
# option such as `--db=...` would point the read at another graph, and an
# absolute path would read outside the directory the call names.
if [ "$SUBCOMMAND" = file-detail ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in -* | /* | '') exit 0 ;; esac
fi
# scan takes nothing but the project: an option such as `--db=...` would point the write at another graph.
if [ "$SUBCOMMAND" = scan ]; then
    [ "$#" -eq 0 ] || exit 0
fi
# allow-root takes nothing but the root, and always writes: the pane runs it
# only after the person confirmed, so a preview would answer a question nobody
# asked. An option such as `--db=...` would grant the root in another file.
if [ "$SUBCOMMAND" = allow-root ]; then
    [ "$#" -eq 0 ] || exit 0
    set -- --execute
fi

# The working directory and the argument must name the same place. `docker run`
# gives the container its image's own working directory rather than this one,
# so the bind mount below is what actually carries the project in; entering it
# here keeps a caller from mounting one directory and reading another.
CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0
# What the binary reads: the project directory, or for file-detail the file in it.
TARGET=$PROJECT_DIR
if [ "$SUBCOMMAND" = file-detail ]; then
    TARGET="$PROJECT_DIR/$1"
    shift
fi

# The one failure that is not silent: with no docker there is nothing to
# retry, so the mod is told and turns itself off. Every other failure
# (a timeout, a crash, empty output) is silence and is asked again later.
command -v docker >/dev/null 2>&1 || {
    printf '%s\n' '{"status":"no-binary"}'
    exit 0
}

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
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$(docker run --rm \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
