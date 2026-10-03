#!/bin/sh
# Runs one Knossos brief command for the Claude Code mod.
#
# Usage: knossos-run.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run.sh component-detail <project-dir> <name>
#
# Same contract as session-brief.sh: every failure exits 0 with nothing on
# stdout. The mod reads silence as "no data" and keeps its last figures
# with their age, so a broken install can never break a session.
set -u

[ "$#" -ge 2 ] || exit 0
SUBCOMMAND=$1
PROJECT_DIR=$2
shift 2

# Resolved to an absolute path before the cd below: $0 may be relative to the
# starting directory.
LIB="$(CDPATH='' cd -- "$(dirname -- "$0")" 2>/dev/null && pwd)/lib.sh"

case "$SUBCOMMAND" in
    turn-brief) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60} ;;
    dashboard | component-detail) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    *) exit 0 ;;
esac

# component-detail takes exactly one component name, which may not read as an
# option: `--db=...` would point the read at another graph.
if [ "$SUBCOMMAND" = component-detail ]; then
    [ "$#" -eq 1 ] || exit 0
    case "$1" in -*) exit 0 ;; esac
fi

CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0

# Shared discovery helpers and the install-time data location. A missing
# library is silent like every other failure: a plain `.` of a missing file
# would abort the shell with a diagnostic.
[ -r "$LIB" ] || exit 0
# shellcheck source=hooks/scripts/lib.sh
. "$LIB" 2>/dev/null || exit 0

BIN="$(find_knossos)" || exit 0

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" "$LIMIT" "$BIN" "$SUBCOMMAND" "$PROJECT_DIR" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$("$BIN" "$SUBCOMMAND" "$PROJECT_DIR" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
