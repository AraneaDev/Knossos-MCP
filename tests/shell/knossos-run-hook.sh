#!/bin/sh
# The mod's wrapper must be silent on every failure and pass JSON through.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
SCRIPTS="$HERE/../../hooks/scripts"
RUN="$SCRIPTS/knossos-run.sh"
failures=0
STUBS=$(mktemp -d)
trap 'rm -rf "$STUBS"' EXIT

expect_silent_success() {
    label=$1; shift
    out=$("$@" 2>/dev/null); status=$?
    if [ "$status" -ne 0 ] || [ -n "$out" ]; then
        printf 'FAIL %s (status %s, output %s)\n' "$label" "$status" "$out"; failures=$((failures + 1))
    else
        printf 'ok   %s\n' "$label"
    fi
}

expect_output() {
    label=$1; expected=$2; shift 2
    out=$("$@" 2>/dev/null); status=$?
    if [ "$status" -ne 0 ] || [ "$out" != "$expected" ]; then
        printf 'FAIL %s (status %s, output %s)\n' "$label" "$status" "$out"; failures=$((failures + 1))
    else
        printf 'ok   %s\n' "$label"
    fi
}

printf '#!/bin/sh\nexit 3\n' > "$STUBS/failing"; chmod +x "$STUBS/failing"
printf '#!/bin/sh\nsleep 30\n' > "$STUBS/hanging"; chmod +x "$STUBS/hanging"
printf '#!/bin/sh\nprintf "%%s|" "$@"\n' > "$STUBS/echoing"; chmod +x "$STUBS/echoing"
# shellcheck disable=SC2016
printf '#!/bin/sh\nprintf "%%s|%%s" "${KNOSSOS_DATA_DIR:-}" "${KNOSSOS_ROOTS_FILE:-}"\n' > "$STUBS/envdump"; chmod +x "$STUBS/envdump"

expect_silent_success 'missing binary' env KNOSSOS_BIN=/nonexistent/knossos PATH=/nonexistent HOME=/nonexistent /bin/sh "$RUN" dashboard /tmp
expect_silent_success 'failing binary' env KNOSSOS_BIN="$STUBS/failing" /bin/sh "$RUN" dashboard /tmp
expect_silent_success 'hanging binary' env KNOSSOS_BIN="$STUBS/hanging" KNOSSOS_RUN_TIMEOUT=1 /bin/sh "$RUN" dashboard /tmp
expect_silent_success 'unknown subcommand' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" scan /tmp
expect_silent_success 'missing project dir' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" dashboard
expect_silent_success 'unenterable project dir' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" dashboard /nonexistent/dir
expect_output 'passes arguments with spaces intact' 'turn-brief|/tmp|--files=a, b.php|--json|' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" turn-brief /tmp '--files=a, b.php'

# Install-time data location: lib.sh carries a placeholder the installer fills.
# The wrapper sources lib.sh from its own directory, so each case runs a copy.
install_copy() {
    dir=$1; data=$2
    mkdir -p "$dir"
    cp "$RUN" "$dir/knossos-run.sh"
    if [ -n "$data" ]; then
        sed "s|__KNOSSOS_DATA_DIR__|$data|" "$SCRIPTS/lib.sh" > "$dir/lib.sh"
    else
        cp "$SCRIPTS/lib.sh" "$dir/lib.sh"
    fi
}

install_copy "$STUBS/plain" ''
expect_output 'uninstalled placeholder leaves the data location unset' '|' \
    env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE KNOSSOS_BIN="$STUBS/envdump" /bin/sh "$STUBS/plain/knossos-run.sh" dashboard /tmp

install_copy "$STUBS/baked" '/x y/data'
expect_output 'installed data location reaches the binary' '/x y/data|/x y/data/roots.json' \
    env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE KNOSSOS_BIN="$STUBS/envdump" /bin/sh "$STUBS/baked/knossos-run.sh" dashboard /tmp

expect_output 'environment data location wins over the installed one' '/env|/x y/data/roots.json' \
    env -u KNOSSOS_ROOTS_FILE KNOSSOS_DATA_DIR=/env KNOSSOS_BIN="$STUBS/envdump" /bin/sh "$STUBS/baked/knossos-run.sh" dashboard /tmp

# A wrapper installed without its library must still be silent.
mkdir -p "$STUBS/nolib"
cp "$RUN" "$STUBS/nolib/knossos-run.sh"
expect_silent_success 'missing lib.sh' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$STUBS/nolib/knossos-run.sh" dashboard /tmp

[ "$failures" -eq 0 ] || exit 1
