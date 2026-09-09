#!/bin/sh
# Failure-mode tests for the SessionStart hook.
#
# The property under test is the one every other guarantee rests on: the hook
# may fail to help, and may never cost anything. Each case must exit 0 with
# empty stdout.
set -u

HOOK="$(CDPATH='' cd -- "$(dirname -- "$0")/../../hooks/scripts" && pwd)/session-brief.sh"
failures=0

expect_silent_success() {
    label="$1"
    shift
    output="$("$@" 2>/dev/null)"
    status=$?
    if [ "$status" -ne 0 ] || [ -n "$output" ]; then
        printf 'FAIL %s: status=%s output=%s\n' "$label" "$status" "$output"
        failures=$((failures + 1))
    else
        printf 'ok   %s\n' "$label"
    fi
}

# No binary anywhere: KNOSSOS_BIN points at nothing and PATH is emptied.
# sh is resolved to an absolute path here, before PATH is emptied for the
# hook itself: `env` looks up its own target command through the PATH it is
# about to set, so a bare "sh" would make env fail to launch the hook at all
# rather than exercising the hook's own missing-binary handling.
SH_BIN="$(command -v sh)"
expect_silent_success "missing binary" \
    env KNOSSOS_BIN=/nonexistent/knossos PATH=/nonexistent CLAUDE_PROJECT_DIR=/tmp "$SH_BIN" "$HOOK"

# Binary exists but fails.
tmp="$(mktemp -d)"
printf '#!/bin/sh\nexit 3\n' > "$tmp/knossos"
chmod +x "$tmp/knossos"
expect_silent_success "failing binary" \
    env KNOSSOS_BIN="$tmp/knossos" CLAUDE_PROJECT_DIR=/tmp sh "$HOOK"

# Binary writes to stderr and hangs past the timeout.
printf '#!/bin/sh\necho boom >&2\nsleep 30\n' > "$tmp/knossos"
chmod +x "$tmp/knossos"
expect_silent_success "hanging binary" \
    env KNOSSOS_BIN="$tmp/knossos" CLAUDE_PROJECT_DIR=/tmp sh "$HOOK"

rm -rf "$tmp"
[ "$failures" -eq 0 ] || exit 1
printf 'all hook failure modes silent\n'
