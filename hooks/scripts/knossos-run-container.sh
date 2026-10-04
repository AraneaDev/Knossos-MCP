#!/bin/sh
# Brief commands for the Claude Code mod, against a containerised Knossos installation.
#
# Usage: knossos-run-container.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run-container.sh component-detail <project-dir> <name>
#        knossos-run-container.sh file-detail <project-dir> <file>
#        knossos-run-container.sh scan <project-dir>
#        knossos-run-container.sh allow-root <root>
#        knossos-run-container.sh session-changes <project-dir> --since=<snapshot>
#        knossos-run-container.sh session-head <project-dir>
#        knossos-run-container.sh session-diff <project-dir> --rev=<commit> --file=<file>
#        knossos-run-container.sh boundary-couplings <project-dir> --from=<boundary> --to=<boundary>
#        knossos-run-container.sh graph-search <project-dir> --query=<text>
#        knossos-run-container.sh file-context <project-dir> <file>
#        knossos-run-container.sh branch-diff <project-dir>
#        knossos-run-container.sh churn <project-dir>
#        knossos-run-container.sh blast-radius <project-dir> --component=<name>
#        knossos-run-container.sh path-between <project-dir> --from=<name> --to=<name>
#        knossos-run-container.sh annotate <project-dir> --component=<name> --value=<note> [--execute]
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# Same contract as knossos-run.sh: every failure exits 0 with nothing on stdout,
# except a missing docker, which prints {"status":"no-binary"}.
#
# The session commands take exactly what the local wrapper lets through. The
# two that read git (`session-head`, `session-diff`) run as the caller's own
# user and group: git refuses a repository another user owns, and the image's
# user owns none of the caller's. They read the project only, never the data.
# `file-context`, `branch-diff` and `churn` read the graph as well, so they run
# as the image's user like the rest: git may then refuse the mounted
# repository, and they answer without commits, or with `no-git`, rather than
# not at all.
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
    component-detail|file-detail|file-context|graph-search) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    # Two whole graphs read and compared.
    branch-diff) LIMIT=${KNOSSOS_RUN_TIMEOUT:-30} ;;
    # A git log of the last 30 days, a search out from one component, two route searches, a note.
    churn|blast-radius|path-between|annotate) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    allow-root) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    session-changes|session-head|session-diff|boundary-couplings) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
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
if [ "$SUBCOMMAND" = file-detail ] || [ "$SUBCOMMAND" = file-context ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in -* | /* | '') exit 0 ;; esac
fi
# Whether "$1" is one line of text of at most $2 characters: no control
# character, counted by its characters. Read byte by byte (LC_ALL=C), so a
# non-ASCII name is text whatever locale the hook runs in, and a UTF-8
# continuation byte is not counted as a character of its own.
is_text_line() (
    LC_ALL=C
    export LC_ALL
    case "$1" in *[[:cntrl:]]*) exit 1 ;; esac
    chars=$(printf '%s' "$1" | tr -d '\200-\277')
    [ "${#chars}" -le "$2" ]
)
# graph-search takes exactly what was typed into the finder, one line of
# text of at most 200 characters: any other option (`--db=...`) would read
# another graph.
if [ "$SUBCOMMAND" = graph-search ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in --query=) exit 0 ;; --query=*) ;; *) exit 0 ;; esac
    is_text_line "$1" 208 || exit 0
fi
# branch-diff takes nothing but the project.
if [ "$SUBCOMMAND" = branch-diff ]; then
    [ "$#" -eq 0 ] || exit 0
fi
# churn takes nothing but the project.
if [ "$SUBCOMMAND" = churn ]; then
    [ "$#" -eq 0 ] || exit 0
fi
# blast-radius takes exactly one component, by its name, one line of text: any other
# option (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = blast-radius ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in --component=) exit 0 ;; --component=*) ;; *) exit 0 ;; esac
    is_text_line "$1" 520 || exit 0
fi
# path-between takes exactly the two components, the one the route starts at
# and the one it ends at, in that order, each one line of text.
if [ "$SUBCOMMAND" = path-between ]; then
    [ "$#" -eq 2 ] || exit 0
    case "$1" in --from=) exit 0 ;; --from=*) ;; *) exit 0 ;; esac
    case "$2" in --to=) exit 0 ;; --to=*) ;; *) exit 0 ;; esac
    is_text_line "$1" 520 && is_text_line "$2" 520 || exit 0
fi
# annotate takes the component, a note of one line of text (at most 2,000
# characters) and, last, `--execute` when the person confirmed the preview in
# the pane: without it nothing is written. Nothing else: an option such as
# `--db=...` would write into another graph.
if [ "$SUBCOMMAND" = annotate ]; then
    [ "$#" -eq 2 ] || [ "$#" -eq 3 ] || exit 0
    case "$1" in --component=) exit 0 ;; --component=*) ;; *) exit 0 ;; esac
    case "$2" in --value=*) ;; *) exit 0 ;; esac
    is_text_line "$1" 520 && is_text_line "$2" 2008 || exit 0
    if [ "$#" -eq 3 ]; then
        [ "$3" = --execute ] || exit 0
    fi
fi
# scan takes nothing but the project: an option such as `--db=...` would point the write at another graph.
if [ "$SUBCOMMAND" = scan ]; then
    [ "$#" -eq 0 ] || exit 0
fi
# session-changes takes exactly the snapshot the session began at, a plain id:
# any other option (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = session-changes ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in --since=*[!A-Za-z0-9_.:-]* | --since=) exit 0 ;; --since=*) ;; *) exit 0 ;; esac
fi
# session-head takes nothing but the project.
if [ "$SUBCOMMAND" = session-head ]; then
    [ "$#" -eq 0 ] || exit 0
fi
# session-diff takes exactly the commit the session began at (a hex id) and one
# file relative to the project directory, in that order: no other option, no
# absolute path and no step out of the directory.
if [ "$SUBCOMMAND" = session-diff ]; then
    [ "$#" -eq 2 ] || exit 0
    case "$1" in --rev=*[!0-9a-f]* | --rev=) exit 0 ;; --rev=*) ;; *) exit 0 ;; esac
    [ "${#1}" -ge 13 ] && [ "${#1}" -le 70 ] || exit 0
    case "$2" in --file=-* | --file=/* | --file= | --file=.. | --file=../* | --file=*/../* | --file=*/..) exit 0 ;; --file=*) ;; *) exit 0 ;; esac
fi
# boundary-couplings takes exactly the two boundaries of one heat map cell, the
# one it runs from and the one it runs to, in that order, each one line of text:
# any other option (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = boundary-couplings ]; then
    [ "$#" -eq 2 ] || exit 0
    case "$1" in --from=) exit 0 ;; --from=*) ;; *) exit 0 ;; esac
    case "$2" in --to=) exit 0 ;; --to=*) ;; *) exit 0 ;; esac
    is_text_line "$1" 520 && is_text_line "$2" 520 || exit 0
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
# What the binary reads: the project directory, or for file-detail and file-context the file in it.
TARGET=$PROJECT_DIR
if [ "$SUBCOMMAND" = file-detail ] || [ "$SUBCOMMAND" = file-context ]; then
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

# Git reads the project as the user who owns it (see above); everything else runs as the image's user.
USER_ARG=
case "$SUBCOMMAND" in
    session-head|session-diff) USER_ARG="--user=$(id -u):$(id -g)" ;;
esac

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" "$LIMIT" docker run --rm ${USER_ARG:+"$USER_ARG"} \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$(docker run --rm ${USER_ARG:+"$USER_ARG"} \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
