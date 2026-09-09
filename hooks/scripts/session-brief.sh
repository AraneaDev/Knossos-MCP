#!/bin/sh
# Injects a Knossos orientation brief at session start.
#
# Read-only: it never scans, writes, or executes project code. Every failure
# path is identical, exit 0 with nothing on stdout, because a hook that breaks
# a session start costs more than the brief was ever worth.
set -u

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$PWD}"

# Discovery order: an explicit override, then PATH, then the conventional
# locations. Deliberately short: a long search is a slow session start.
find_knossos() {
    if [ -n "${KNOSSOS_BIN:-}" ] && [ -x "${KNOSSOS_BIN}" ]; then
        printf '%s' "${KNOSSOS_BIN}"
        return 0
    fi
    if command -v knossos >/dev/null 2>&1; then
        command -v knossos
        return 0
    fi
    for candidate in "$PROJECT_DIR/bin/knossos" "$HOME/.local/bin/knossos" /usr/local/bin/knossos; do
        if [ -x "$candidate" ]; then
            printf '%s' "$candidate"
            return 0
        fi
    done
    return 1
}

BIN="$(find_knossos)" || exit 0

# Bound the worst case so a locked SQLite file cannot stall a session start.
# `timeout` is absent on some systems, so its own absence must not be fatal.
if command -v timeout >/dev/null 2>&1; then
    OUTPUT="$(timeout 3 "$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
else
    OUTPUT="$("$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0
