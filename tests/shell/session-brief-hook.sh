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
# A PATH entry that does not resolve is dropped and an all-dropped PATH falls
# back to /usr/bin:/bin, so "nothing on PATH" is an existing empty directory.
EMPTY_DIR="$(mktemp -d)"
# The project is a sibling directory: /tmp itself would contain the empty one.
EMPTY_PROJ="$(mktemp -d)"
expect_silent_success "missing binary" \
    env KNOSSOS_BIN=/nonexistent/knossos PATH="$EMPTY_DIR" CLAUDE_PROJECT_DIR="$EMPTY_PROJ" "$SH_BIN" "$HOOK"

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

# Neither `timeout` nor `gtimeout` is resolvable (PATH emptied), and
# KNOSSOS_BIN is an absolute path so it is still found without PATH. The
# fallback path runs the binary unbounded, so use a binary that fails fast
# rather than one that hangs, since nothing here would bound a hang.
printf '#!/bin/sh\nexit 3\n' > "$tmp/knossos"
chmod +x "$tmp/knossos"
expect_silent_success "no timeout binary available" \
    env KNOSSOS_BIN="$tmp/knossos" PATH="$EMPTY_DIR" CLAUDE_PROJECT_DIR="$EMPTY_PROJ" "$SH_BIN" "$HOOK"

rm -rf "$tmp" "$EMPTY_DIR" "$EMPTY_PROJ"
# A hook installed without its library must still be silent.
NOLIB=$(mktemp -d)
cp "$HOOK" "$NOLIB/session-brief.sh"
printf '#!/bin/sh\necho brief\n' > "$NOLIB/knossos"
chmod +x "$NOLIB/knossos"
expect_silent_success "missing lib.sh" \
    env KNOSSOS_BIN="$NOLIB/knossos" CLAUDE_PROJECT_DIR=/tmp sh "$NOLIB/session-brief.sh"
rm -rf "$NOLIB"

# The container hook names the mounted project as git's safe directory: the container's user does not own it.
BOX=$(mktemp -d)
mkdir -p "$BOX/bin" "$BOX/proj"
sed -e "s|__KNOSSOS_IMAGE__|img:1|" -e "s|__KNOSSOS_DATA__|/srv/data|" "${HOOK%/*}/session-brief-container.sh" > "$BOX/hook.sh"
cat > "$BOX/bin/docker" <<'STUB'
#!/bin/sh
if [ "$1" = rm ]; then printf '%s\n' "$*" >> "${0%/*}/removed"; exit 0; fi
while [ "$#" -gt 0 ]; do
    [ "$1" = -e ] && printf '%s\n' "$2" >> "${0%/*}/env"
    [ "$1" = --init ] && printf 'init|' >> "${0%/*}/flags"
    [ "$1" = --name ] && printf 'name=%s|' "$2" >> "${0%/*}/flags"
    shift
done
STUB
chmod +x "$BOX/bin/docker"
env PATH="$BOX/bin:$PATH" CLAUDE_PROJECT_DIR="$BOX/proj" sh "$BOX/hook.sh" >/dev/null 2>&1
if [ "$(cat "$BOX/bin/env" 2>/dev/null)" = "KNOSSOS_GIT_SAFE_DIRECTORY=$(CDPATH='' cd -- "$BOX/proj" && pwd -P)" ]; then
    printf 'ok   %s\n' 'container hook names the project as git'"'"'s safe directory'
else
    printf 'FAIL %s: env=%s\n' 'container hook names the project as git'"'"'s safe directory' "$(cat "$BOX/bin/env" 2>/dev/null)"
    failures=$((failures + 1))
fi
case "$(cat "$BOX/bin/flags" 2>/dev/null)" in
    init\|name=knossos-hook-*) printf 'ok   %s\n' 'container hook run has --init and a name' ;;
    *) printf 'FAIL %s: flags=%s\n' 'container hook run has --init and a name' "$(cat "$BOX/bin/flags" 2>/dev/null)"; failures=$((failures + 1)) ;;
esac
# A timed-out container hook removes its container, not only the docker client.
mkdir -p "$BOX/killer"
# shellcheck disable=SC2016 # $1 belongs to the stub, not to this script
printf '#!/bin/sh\nif [ "$1" = -k ]; then shift 2; fi\nexit 124\n' > "$BOX/killer/timeout"
chmod +x "$BOX/killer/timeout"
: > "$BOX/bin/removed"
env PATH="$BOX/killer:$BOX/bin:$PATH" CLAUDE_PROJECT_DIR="$BOX/proj" sh "$BOX/hook.sh" >/dev/null 2>&1
if grep -q '^rm -f knossos-hook-' "$BOX/bin/removed"; then printf 'ok   %s\n' 'timed-out container hook is removed'; else printf 'FAIL %s\n' 'timed-out container hook is removed'; failures=$((failures + 1)); fi
rm -rf "$BOX"

# A project that ships an executable bin/knossos must never have it run:
# opening a repository is not consent to execute its code.
proj="$(mktemp -d)"
home="$(mktemp -d)"
mkdir -p "$proj/bin" "$home/bare"
# dirname is the one outside tool the hook needs before it can find its
# library; without it the hook exits early and the case would prove nothing.
ln -s "$(command -v dirname)" "$home/bare/dirname"
printf '#!/bin/sh\n: > "%s/RAN"\n' "$proj" > "$proj/bin/knossos"
chmod +x "$proj/bin/knossos"
env -u KNOSSOS_BIN PATH="$home/bare" HOME="$home" CLAUDE_PROJECT_DIR="$proj" "$SH_BIN" "$HOOK" >/dev/null 2>&1
if [ -e "$proj/RAN" ]; then
    printf 'FAIL project-local binary was executed\n'
    failures=$((failures + 1))
else
    printf 'ok   project-local binary is never executed\n'
fi
rm -rf "$proj" "$home"

# A PATH entry that is not absolute resolves against the directory the hook
# has entered, which is the project: `node_modules/.bin`, `.` or an empty
# entry would find a program the repository ships. Each planted program
# leaves a marker if it runs. KNOSSOS_BIN is a trusted stub in the timeout
# cases and unset in the knossos ones, which also start inside the project so
# the lookup of dirname, before the hook enters it, is covered too.
#
# An absolute entry that points into the project (a tool manager adds
# `<project>/node_modules/.bin` that way) finds the same programs, whether it
# names the project directly or reaches it through a symbolic link.
planted="$(mktemp -d)"
mkdir -p "$planted/bare" "$planted/trusted" "$planted/dockerbin"
ln -s "$planted/proj/node_modules/.bin" "$planted/linked-bin"
ln -s "$(command -v dirname)" "$planted/bare/dirname"
printf '#!/bin/sh\nexit 0\n' > "$planted/trusted/knossos"
printf '#!/bin/sh\nexit 0\n' > "$planted/dockerbin/docker"
chmod +x "$planted/trusted/knossos" "$planted/dockerbin/docker"
sed -e "s|__KNOSSOS_IMAGE__|img:1|" -e "s|__KNOSSOS_DATA__|/srv/data|" "${HOOK%/*}/session-brief-container.sh" > "$planted/container.sh"
expect_no_planted_run() {
    label=$1; shift
    proj="$planted/proj"
    rm -rf "$proj"
    mkdir -p "$proj/node_modules/.bin"
    for tool in knossos timeout gtimeout docker dirname tr; do
        for dir in "$proj" "$proj/node_modules/.bin"; do
            printf '#!/bin/sh\n: > "%s/RAN"\n' "$proj" > "$dir/$tool"
            chmod +x "$dir/$tool"
        done
    done
    output="$("$@" 2>/dev/null)"
    status=$?
    if [ -e "$proj/RAN" ] || [ "$status" -ne 0 ] || [ -n "$output" ]; then
        printf 'FAIL %s: ran=%s status=%s output=%s\n' "$label" "$([ -e "$proj/RAN" ] && echo yes || echo no)" "$status" "$output"
        failures=$((failures + 1))
    else
        printf 'ok   %s\n' "$label"
    fi
}
for entry in node_modules/.bin . '' "$planted/proj/node_modules/.bin" "$planted/linked-bin"; do
    expect_no_planted_run "PATH entry '$entry' never finds the project's knossos" \
        env -u KNOSSOS_BIN PATH="$entry:$planted/bare" HOME="$planted" CLAUDE_PROJECT_DIR="$planted/proj" "$SH_BIN" -c "cd '$planted/proj' && exec '$SH_BIN' '$HOOK'"
    expect_no_planted_run "PATH entry '$entry' never finds the project's timeout" \
        env KNOSSOS_BIN="$planted/trusted/knossos" PATH="$entry:$planted/bare" HOME="$planted" CLAUDE_PROJECT_DIR="$planted/proj" "$SH_BIN" "$HOOK"
    expect_no_planted_run "container hook: PATH entry '$entry' never finds the project's docker" \
        env PATH="$entry:$planted/bare" CLAUDE_PROJECT_DIR="$planted/proj" "$SH_BIN" "$planted/container.sh"
    expect_no_planted_run "container hook: PATH entry '$entry' never finds the project's timeout" \
        env PATH="$entry:$planted/dockerbin:$planted/bare" CLAUDE_PROJECT_DIR="$planted/proj" "$SH_BIN" "$planted/container.sh"
done

# A CLAUDE_PROJECT_DIR that does not resolve must not switch the project
# filter off: the hook runs inside the project, so the working directory
# stands in for it and the project's own bin directory is still dropped.
expect_no_planted_run "unresolvable CLAUDE_PROJECT_DIR still drops the project's bin" \
    env PATH="$planted/proj/node_modules/.bin:$planted/dockerbin:$planted/bare" KNOSSOS_BIN="$planted/trusted/knossos" HOME="$planted" CLAUDE_PROJECT_DIR="$planted/no-such-dir" "$SH_BIN" -c "cd '$planted/proj' && exec '$SH_BIN' '$HOOK'"

# An entry that does not resolve is dropped rather than kept as written: a
# symbolic link to a directory that does not exist yet would otherwise sit on
# PATH until something creates it. The trusted stub records the PATH it was
# started with; the dangling entry must be gone while a resolving entry is
# kept, which proves the filter ran and did not simply empty PATH.
mkdir -p "$planted/pathdump"
# shellcheck disable=SC2016
printf '#!/bin/sh\nprintf "%%s" "$PATH" > "%s/seen"\n' "$planted" > "$planted/pathdump/knossos"
chmod +x "$planted/pathdump/knossos"
ln -s "$planted/proj/future" "$planted/dangling"
CLAUDE_PROJECT_DIR="$planted/proj" KNOSSOS_BIN="$planted/pathdump/knossos" PATH="$planted/dangling:$planted/bare" "$SH_BIN" "$HOOK" >/dev/null 2>&1
seen="$(cat "$planted/seen" 2>/dev/null)"
if [ "$seen" = "$planted/bare" ]; then
    printf 'ok   a PATH entry that does not resolve is dropped\n'
else
    printf 'FAIL a PATH entry that does not resolve is dropped: PATH=%s\n' "$seen"
    failures=$((failures + 1))
fi
rm -rf "$planted"

[ "$failures" -eq 0 ] || exit 1
printf 'all hook failure modes silent\n'
