#!/bin/sh
# Injects a Knossos orientation brief at session start.
#
# Read-only: it never scans, writes, or executes project code. Every failure
# path is identical, exit 0 with nothing on stdout, because a hook that breaks
# a session start costs more than the brief was ever worth.
set -u

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$PWD}"

# Resolved to an absolute path before the cd below: $0 may be relative to the
# starting directory.
LIB="$(CDPATH='' cd -- "$(dirname -- "$0")" 2>/dev/null && pwd)/lib.sh"

# Run from the project directory so the working directory and the argument
# agree. The command derives its database from the path it is given, but a
# caller that passes no path at all falls back to the working directory, and a
# session started in a subdirectory would then answer out of a different graph.
# Guarded like every other failure path here: a directory that cannot be
# entered is silent, not fatal.
CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0

# Shared discovery helpers. A missing library is silent like every other
# failure: a plain `.` of a missing file would abort the shell with a diagnostic.
[ -r "$LIB" ] || exit 0
# shellcheck source=hooks/scripts/lib.sh
. "$LIB" 2>/dev/null || exit 0

BIN="$(find_knossos)" || exit 0

# Bound the worst case so a locked SQLite file cannot stall a session start.
if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" 3 "$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
else
    # Neither `timeout` nor `gtimeout` exists here, so this one call has no
    # bound of its own. The backstop is the harness: hooks.json sets this
    # hook's own "timeout" to 15, and Claude Code enforces that ceiling on
    # the whole process regardless of what runs inside it.
    OUTPUT="$("$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
