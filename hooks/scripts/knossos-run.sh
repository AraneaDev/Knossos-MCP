#!/bin/sh
# Runs one Knossos brief command for the Claude Code mod.
#
# Usage: knossos-run.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run.sh component-detail <project-dir> <name>
#        knossos-run.sh file-detail <project-dir> <file>
#        knossos-run.sh scan <project-dir>
#        knossos-run.sh allow-root <root>
#        knossos-run.sh watch <project-dir> [--poll-ms=N]
#        knossos-run.sh session-changes <project-dir> --since=<snapshot>
#        knossos-run.sh session-head <project-dir>
#        knossos-run.sh session-diff <project-dir> --rev=<commit> --file=<file>
#        knossos-run.sh boundary-couplings <project-dir> --from=<boundary> --to=<boundary>
#        knossos-run.sh graph-search <project-dir> --query=<text>
#        knossos-run.sh file-context <project-dir> <file>
#        knossos-run.sh branch-diff <project-dir>
#        knossos-run.sh churn <project-dir>
#        knossos-run.sh blast-radius <project-dir> --component=<name>
#        knossos-run.sh path-between <project-dir> --from=<name> --to=<name>
#        knossos-run.sh annotate <project-dir> --component=<name> --value=<note> [--execute]
#
# Every failure exits 0. All but one print nothing: the mod reads silence as
# "no data", keeps its last figures with their age and asks again later, so a
# broken install can never break a session. The exception is a missing
# binary, which prints {"status":"no-binary"} so the mod can turn itself off.
#
# `watch` is the one that does not end on its own: it becomes the live
# watcher (`knossos watch --shared`), which prints one JSON event per line
# for as long as the session keeps it, and stops when the session stops it
# or goes away. It is never bounded by a timeout.
set -u

[ "$#" -ge 2 ] || exit 0
SUBCOMMAND=$1
PROJECT_DIR=$2
shift 2

# Resolved to an absolute path before the cd below: $0 may be relative to the
# starting directory.
LIB="$(CDPATH='' cd -- "$(dirname -- "$0")" 2>/dev/null && pwd)/lib.sh"

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
    watch) LIMIT=0 ;;
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
# watch takes at most its poll interval, in milliseconds: an option such as
# `--db=...` would point it at another graph.
if [ "$SUBCOMMAND" = watch ]; then
    [ "$#" -le 1 ] || exit 0
    if [ "$#" -eq 1 ]; then
        case "$1" in --poll-ms=*[!0-9]* | --poll-ms=) exit 0 ;; --poll-ms=*) ;; *) exit 0 ;; esac
    fi
    set -- --shared "$@"
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

CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0
# What the binary reads: the project directory, or for file-detail and file-context the file in it.
TARGET=$PROJECT_DIR
if [ "$SUBCOMMAND" = file-detail ] || [ "$SUBCOMMAND" = file-context ]; then
    TARGET="$PROJECT_DIR/$1"
    shift
fi

# Shared discovery helpers and the install-time data location. A missing
# library is silent like every other failure: a plain `.` of a missing file
# would abort the shell with a diagnostic.
[ -r "$LIB" ] || exit 0
# shellcheck source=hooks/scripts/lib.sh
. "$LIB" 2>/dev/null || exit 0
# A grant goes only into the roots file the installation baked in (or one the
# environment names): without it the binary would fall back to a file beside
# the working directory, which no server reads.
if [ "$SUBCOMMAND" = allow-root ] && [ -z "${KNOSSOS_ROOTS_FILE:-}" ]; then
    exit 0
fi

# The one failure that is not silent: with no binary there is nothing to
# retry, so the mod is told and turns itself off. Every other failure
# (a timeout, a crash, empty output) is silence and is asked again later.
BIN="$(find_knossos)" || {
    printf '%s\n' '{"status":"no-binary"}'
    exit 0
}

# The watcher replaces this shell, so stopping the child the session holds stops the watcher itself.
if [ "$SUBCOMMAND" = watch ]; then
    exec "$BIN" watch "$TARGET" "$@" 2>/dev/null
fi

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" "$LIMIT" "$BIN" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$("$BIN" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
