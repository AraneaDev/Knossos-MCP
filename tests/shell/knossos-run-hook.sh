#!/bin/sh
# The mod's wrapper must be silent on every failure and pass JSON through.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
SCRIPTS="$HERE/../../hooks/scripts"
RUN="$SCRIPTS/knossos-run.sh"
failures=0
STUBS=$(mktemp -d)
trap 'rm -rf "$STUBS"' EXIT
# The wrapper hands the binary the project's physical path. Under a TMPDIR that
# is itself a symbolic link that differs from the spelling given, so every
# expectation below compares against the resolved path, never a literal.
mkdir -p "$STUBS/proj"
ABS_PROJ=$(CDPATH='' cd -- "$STUBS/proj" && pwd -P)

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

NO_BINARY='{"status":"no-binary"}'
# A PATH with the one outside tool the wrappers need and nothing else: no
# knossos, no docker. An empty PATH would fail on dirname first and prove nothing.
mkdir -p "$STUBS/bare"
ln -s "$(command -v dirname)" "$STUBS/bare/dirname"
# The one failure the wrapper reports: the mod turns itself off on it.
expect_output 'missing binary says so' "$NO_BINARY" env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" dashboard /tmp
expect_output 'missing binary says so for a turn brief' "$NO_BINARY" env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" turn-brief /tmp
expect_silent_success 'failing binary' env KNOSSOS_BIN="$STUBS/failing" /bin/sh "$RUN" dashboard /tmp
expect_silent_success 'hanging binary' env KNOSSOS_BIN="$STUBS/hanging" KNOSSOS_RUN_TIMEOUT=1 /bin/sh "$RUN" dashboard /tmp
expect_silent_success 'unknown subcommand' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" bogus /tmp
# scan is the pane's rescan: it runs `knossos rescan`, never `knossos scan`, and takes nothing but the project.
expect_output 'scan runs the rescan command on the project' "rescan|$ABS_PROJ|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" scan "$STUBS/proj"
expect_silent_success 'scan refuses an option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" scan "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'scan refuses a second argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" scan "$STUBS/proj" extra
expect_output 'missing binary says so for a scan' "$NO_BINARY" env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" scan /tmp
expect_silent_success 'failing binary on a scan' env KNOSSOS_BIN="$STUBS/failing" /bin/sh "$RUN" scan /tmp
# allow-root: the root alone, always with --execute, and only into a roots file the installation (or the environment) names.
expect_output 'allow-root grants the root with --execute' "allow-root|$ABS_PROJ|--execute|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" KNOSSOS_ROOTS_FILE=/tmp/roots.json /bin/sh "$RUN" allow-root "$STUBS/proj"
expect_silent_success 'allow-root without a named roots file does nothing' \
    env -u KNOSSOS_ROOTS_FILE KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" allow-root "$STUBS/proj"
expect_silent_success 'allow-root refuses an option' \
    env KNOSSOS_BIN="$STUBS/echoing" KNOSSOS_ROOTS_FILE=/tmp/roots.json /bin/sh "$RUN" allow-root "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'allow-root refuses a second argument' \
    env KNOSSOS_BIN="$STUBS/echoing" KNOSSOS_ROOTS_FILE=/tmp/roots.json /bin/sh "$RUN" allow-root "$STUBS/proj" extra
expect_silent_success 'failing binary on allow-root' \
    env KNOSSOS_BIN="$STUBS/failing" KNOSSOS_ROOTS_FILE=/tmp/roots.json /bin/sh "$RUN" allow-root /tmp
expect_silent_success 'missing project dir' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" dashboard
expect_silent_success 'unenterable project dir' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" dashboard /nonexistent/dir
expect_output 'passes arguments with spaces intact' "turn-brief|$ABS_PROJ|--files=a, b.php|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" turn-brief "$STUBS/proj" '--files=a, b.php'

# A relative project directory is resolved after the cd, so the binary and the
# bind mount receive the absolute path rather than one that means nothing from
# inside the directory.
expect_output 'relative project directory reaches the binary as an absolute path' "dashboard|$ABS_PROJ|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh -c "cd '$STUBS' && /bin/sh '$RUN' dashboard proj"

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

# component-detail: one component name after the project directory, passed
# through intact; anything that could read as an option is refused.
printf '#!/bin/sh\nprintf "%%s\\n" "{\\"status\\":\\"not-found\\"}"\n' > "$STUBS/unmatched"; chmod +x "$STUBS/unmatched"

expect_output 'component-detail passes the name intact' "component-detail|$ABS_PROJ|App\\My Router|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" component-detail "$STUBS/proj" 'App\My Router'
expect_output 'component-detail passes an unmatched component through' '{"status":"not-found"}' \
    env KNOSSOS_BIN="$STUBS/unmatched" /bin/sh "$RUN" component-detail /tmp Nope
expect_output 'component-detail with a missing binary says so' "$NO_BINARY" \
    env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" component-detail /tmp Router
expect_silent_success 'component-detail without a name' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" component-detail /tmp
expect_silent_success 'component-detail with two names' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" component-detail /tmp A B
expect_silent_success 'component-detail with a name that reads as an option' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" component-detail /tmp --db=/elsewhere
expect_silent_success 'the old inspect subcommand is gone' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" inspect /tmp p1 Router

# file-detail: one file relative to the project directory, handed to the
# binary as the file's own path; an option, an absolute path or nothing at all
# is refused.
expect_output 'file-detail passes the file under the project, spaces intact' "file-detail|$ABS_PROJ/src/My File.php|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-detail "$STUBS/proj" 'src/My File.php'
expect_output 'file-detail with a missing binary says so' "$NO_BINARY" \
    env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" file-detail /tmp a.php
expect_silent_success 'file-detail without a file' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-detail /tmp
expect_silent_success 'file-detail with two files' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-detail /tmp a.php b.php
expect_silent_success 'file-detail with a file that reads as an option' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-detail /tmp --db=/elsewhere
expect_silent_success 'file-detail with an absolute path' \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-detail /tmp /etc/passwd

# file-context: one file relative to the project, as file-detail takes it.
expect_output 'file-context passes the file under the project' "file-context|$ABS_PROJ/src/My File.php|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-context "$STUBS/proj" 'src/My File.php'
expect_silent_success 'file-context with an absolute path' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-context /tmp /etc/passwd
expect_silent_success 'file-context with a file that reads as an option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-context /tmp --db=/elsewhere
expect_silent_success 'file-context without a file' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" file-context /tmp
# graph-search: what was typed into the finder, one printable line and nothing else.
expect_output 'graph-search passes what was typed' "graph-search|$ABS_PROJ|--query=dash svc|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" '--query=dash svc'
expect_silent_success 'graph-search refuses an empty query' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" --query=
expect_silent_success 'graph-search refuses another option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'graph-search refuses a second argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" --query=a --db=/tmp/x
expect_silent_success 'graph-search refuses a query with a line break' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" "--query=a
b"
expect_silent_success 'graph-search refuses a query past 200 characters' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" "--query=$(printf '%0201d' 0)"
# branch-diff: the branch against its merge base, with nothing but the project.
expect_output 'branch-diff asks for the project alone' "branch-diff|$ABS_PROJ|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" branch-diff "$STUBS/proj"
expect_silent_success 'branch-diff refuses an option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" branch-diff "$STUBS/proj" --db=/tmp/other.sqlite
# Non-ASCII text is text in any locale, and counted by its characters, not its bytes.
E200=$(i=0; while [ "$i" -lt 200 ]; do printf '\303\251'; i=$((i + 1)); done)
expect_output 'graph-search passes a non-ASCII query under the C locale' "graph-search|$ABS_PROJ|--query=écran|--json|" \
    env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" '--query=écran'
expect_output 'graph-search passes 200 non-ASCII characters' "graph-search|$ABS_PROJ|--query=$E200|--json|" \
    env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" "--query=$E200"
expect_silent_success 'graph-search refuses 201 non-ASCII characters' env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" "--query=${E200}é"
expect_silent_success 'graph-search refuses a tab' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" graph-search "$STUBS/proj" "--query=a$(printf '\t')b"
expect_output 'blast-radius passes a non-ASCII component' "blast-radius|$ABS_PROJ|--component=App\\Écran|--json|" \
    env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" blast-radius "$STUBS/proj" '--component=App\Écran'
expect_output 'path-between passes non-ASCII ends' "path-between|$ABS_PROJ|--from=Ä|--to=Ö|--json|" \
    env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" path-between "$STUBS/proj" --from=Ä --to=Ö
expect_output 'annotate previews a non-ASCII note' "annotate|$ABS_PROJ|--component=A|--value=geprüft|--json|" \
    env LC_ALL=C KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A --value=geprüft
# churn, blast-radius, path-between: the project and the components asked for, and nothing else.
expect_output 'churn asks for the project alone' "churn|$ABS_PROJ|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" churn "$STUBS/proj"
expect_silent_success 'churn refuses an option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" churn "$STUBS/proj" --db=/tmp/other.sqlite
expect_output 'blast-radius passes the component' "blast-radius|$ABS_PROJ|--component=App\\Greeter::greet|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" blast-radius "$STUBS/proj" '--component=App\Greeter::greet'
expect_silent_success 'blast-radius refuses an empty component' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" blast-radius "$STUBS/proj" --component=
expect_silent_success 'blast-radius refuses another option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" blast-radius "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'blast-radius refuses a second argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" blast-radius "$STUBS/proj" --component=A --db=/tmp/x
expect_output 'path-between passes both ends in order' "path-between|$ABS_PROJ|--from=A|--to=B|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" path-between "$STUBS/proj" --from=A --to=B
expect_silent_success 'path-between refuses the ends swapped' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" path-between "$STUBS/proj" --to=B --from=A
expect_silent_success 'path-between refuses one end' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" path-between "$STUBS/proj" --from=A
expect_silent_success 'path-between refuses an end with a line break' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" path-between "$STUBS/proj" --from=A "--to=B
C"
# annotate: a preview unless --execute comes last; the note one printable line of at most 2,000 characters.
expect_output 'annotate previews a note' "annotate|$ABS_PROJ|--component=A|--value=a note|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A '--value=a note'
expect_output 'annotate records a confirmed note' "annotate|$ABS_PROJ|--component=A|--value=a note|--execute|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A '--value=a note' --execute
expect_silent_success 'annotate refuses another third argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A --value=x --db=/tmp/x
expect_silent_success 'annotate refuses the value first' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --value=x --component=A
expect_silent_success 'annotate refuses a note with a line break' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A "--value=a
b"
expect_silent_success 'annotate refuses a note past 2,000 characters' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" annotate "$STUBS/proj" --component=A "--value=$(printf '%02001d' 0)"

# watch: the live watcher, `knossos watch --shared`, with at most its poll interval.
expect_output 'watch runs the shared watcher on the project' "watch|$ABS_PROJ|--shared|--poll-ms=1500|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj" --poll-ms=1500
expect_output 'watch without a poll interval leaves it to the binary' "watch|$ABS_PROJ|--shared|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj"
expect_silent_success 'watch refuses another option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'watch refuses a poll interval that is not a number' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj" --poll-ms=1e3
expect_silent_success 'watch refuses an empty poll interval' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj" --poll-ms=
expect_silent_success 'watch refuses a second argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" watch "$STUBS/proj" --poll-ms=1 extra
expect_output 'missing binary says so for a watch' "$NO_BINARY" env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" watch /tmp
# session-changes: what changed since the session began, read from the scan ledger, with nothing but that snapshot.
expect_output 'session-changes reads since the snapshot it names' "session-changes|$ABS_PROJ|--since=scan_ab12|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-changes "$STUBS/proj" --since=scan_ab12
expect_silent_success 'session-changes refuses another option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-changes "$STUBS/proj" --db=/tmp/other.sqlite
expect_silent_success 'session-changes refuses a snapshot that is not a plain id' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-changes "$STUBS/proj" '--since=a b'
expect_silent_success 'session-changes refuses no snapshot' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-changes "$STUBS/proj"
expect_silent_success 'session-changes refuses a second argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-changes "$STUBS/proj" --since=s1 --db=/tmp/x
# boundary-couplings: one heat map cell spelled out, by its two boundaries, in that order and nothing else.
expect_output 'boundary-couplings passes the two boundaries' "boundary-couplings|$ABS_PROJ|--from=namespace:App\\Core|--to=module:hooks (+ts)|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" '--from=namespace:App\Core' '--to=module:hooks (+ts)'
expect_silent_success 'boundary-couplings refuses another option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" --from=a --db=/tmp/other.sqlite
expect_silent_success 'boundary-couplings refuses the boundaries swapped' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" --to=a --from=b
expect_silent_success 'boundary-couplings refuses an empty boundary' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" --from= --to=b
expect_silent_success 'boundary-couplings refuses a boundary with a line break' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" --from=a "--to=b
c"
expect_silent_success 'boundary-couplings refuses a third argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" boundary-couplings "$STUBS/proj" --from=a --to=b --json
# session-head: the commit the session starts at, with nothing but the project.
expect_output 'session-head asks for the project alone' "session-head|$ABS_PROJ|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-head "$STUBS/proj"
expect_silent_success 'session-head refuses an option' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-head "$STUBS/proj" --db=/tmp/other.sqlite
# session-diff: one file's change since that commit, a hex id and a file inside the project, in that order.
REV=0123456789abcdef0123456789abcdef01234567
expect_output 'session-diff passes the commit and the file' "session-diff|$ABS_PROJ|--rev=$REV|--file=src/a b.php|--json|" \
    env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-diff "$STUBS/proj" "--rev=$REV" '--file=src/a b.php'
for bad in '--rev=HEAD' '--rev=' '--rev=abc' "--rev=$REV$REV$REV" '--since=s1'; do
    expect_silent_success "session-diff refuses $bad" env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-diff "$STUBS/proj" "$bad" --file=src/a.php
done
for bad in '--file=/etc/passwd' '--file=../x' '--file=src/../../x' '--file=..' '--file=src/..' '--file=--output=x' '--file=' '--db=/tmp/x'; do
    expect_silent_success "session-diff refuses $bad" env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-diff "$STUBS/proj" "--rev=$REV" "$bad"
done
expect_silent_success 'session-diff refuses a third argument' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-diff "$STUBS/proj" "--rev=$REV" --file=a.php extra
expect_silent_success 'session-diff refuses the file before the commit' env KNOSSOS_BIN="$STUBS/echoing" /bin/sh "$RUN" session-diff "$STUBS/proj" --file=a.php "--rev=$REV"
expect_output 'missing binary says so for a session diff' "$NO_BINARY" env KNOSSOS_BIN=/nonexistent/knossos PATH="$STUBS/bare" HOME=/nonexistent /bin/sh "$RUN" session-diff /tmp "--rev=$REV" --file=a.php
# The real binary against a real repository: the head it names is the one git has, and a file's diff comes back.
if command -v git >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
    REPO="$STUBS/repo"
    git init --quiet "$REPO" && printf 'one\n' > "$REPO/a.txt" \
        && git -C "$REPO" add a.txt && git -C "$REPO" -c user.name=t -c user.email=t@example.test -c commit.gpgsign=false commit --quiet -m one
    HEAD_REV=$(git -C "$REPO" rev-parse HEAD)
    HEAD_BRANCH=$(git -C "$REPO" symbolic-ref --short HEAD)
    REAL_REPO=$(CDPATH='' cd -- "$REPO" && pwd -P)
    expect_output 'session-head names the commit and the branch the project is at' "{\"status\":\"ok\",\"path\":\"$REAL_REPO\",\"rev\":\"$HEAD_REV\",\"branch\":\"$HEAD_BRANCH\"}" \
        env KNOSSOS_BIN="$HERE/../../bin/knossos" /bin/sh "$RUN" session-head "$REPO"
    printf 'two\n' >> "$REPO/a.txt"
    out=$(env KNOSSOS_BIN="$HERE/../../bin/knossos" /bin/sh "$RUN" session-diff "$REPO" "--rev=$HEAD_REV" --file=a.txt)
    case "$out" in
        *'"kind":"changed"'*'@@ -1 +1,2 @@\n one\n+two\n'*) printf 'ok   %s\n' 'session-diff answers with the hunks since the commit' ;;
        *) printf 'FAIL %s (output %s)\n' 'session-diff answers with the hunks since the commit' "$out"; failures=$((failures + 1)) ;;
    esac
fi
# The watcher replaces the wrapper's shell: stopping the process the session started stops the watcher.
printf '#!/bin/sh\nprintf "%%s" "$$"\n' > "$STUBS/pid"; chmod +x "$STUBS/pid"
env KNOSSOS_BIN="$STUBS/pid" /bin/sh "$RUN" watch "$STUBS/proj" > "$STUBS/pid.out" &
started=$!
wait "$started"
if [ "$(cat "$STUBS/pid.out")" = "$started" ]; then
    printf 'ok   %s\n' 'watch execs the binary in place of the wrapper'
else
    printf 'FAIL %s (wrapper %s, binary %s)\n' 'watch execs the binary in place of the wrapper' "$started" "$(cat "$STUBS/pid.out")"; failures=$((failures + 1))
fi

# The container variant, emitted with its placeholders filled, against a docker stand-in.
mkdir -p "$STUBS/container" "$STUBS/dockerbin"
sed -e "s|__KNOSSOS_IMAGE__|img:1|" -e "s|__KNOSSOS_DATA__|/srv/data|" "$SCRIPTS/knossos-run-container.sh" > "$STUBS/container/knossos-run.sh"
# Drops `run --rm` and the `-v` mounts, writes each `-e` to `env` beside it, says `user=<uid:gid>` when the run names a user, then prints the image and its argv.
cat > "$STUBS/dockerbin/docker" <<'STUB'
#!/bin/sh
shift 2
while [ "$#" -gt 0 ]; do
    case "$1" in
        -v) shift 2 ;;
        -e) printf '%s\n' "$2" >> "${0%/*}/env"; shift 2 ;;
        --user=*) printf 'user=%s|' "${1#--user=}"; shift ;;
        *) break ;;
    esac
done
printf "%s|" "$@"
STUB
chmod +x "$STUBS/dockerbin/docker"
expect_output 'container component-detail passes the name intact' "img:1|component-detail|$ABS_PROJ|My Router|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" component-detail "$STUBS/proj" 'My Router'
expect_output 'container graph-search passes a non-ASCII query under the C locale' "img:1|graph-search|$ABS_PROJ|--query=écran|--json|" \
    env LC_ALL=C PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" graph-search "$STUBS/proj" '--query=écran'
expect_silent_success 'container graph-search refuses 201 non-ASCII characters' \
    env LC_ALL=C PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" graph-search "$STUBS/proj" "--query=${E200}é"
rm -f "$STUBS/dockerbin/env"
env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" churn "$STUBS/proj" >/dev/null
if [ "$(cat "$STUBS/dockerbin/env" 2>/dev/null)" = "KNOSSOS_GIT_SAFE_DIRECTORY=$ABS_PROJ" ]; then
    printf 'ok   %s\n' 'container names the mounted project as git'"'"'s safe directory'
else
    printf 'FAIL %s (env %s)\n' 'container names the mounted project as git'"'"'s safe directory' "$(cat "$STUBS/dockerbin/env" 2>/dev/null)"; failures=$((failures + 1))
fi
expect_output 'container dashboard keeps its arguments' "img:1|dashboard|$ABS_PROJ|--fan-in-threshold=20|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" dashboard "$STUBS/proj" --fan-in-threshold=20
expect_output 'container with a relative project directory mounts and passes the absolute path' "img:1|dashboard|$ABS_PROJ|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh -c "cd '$STUBS' && /bin/sh '$STUBS/container/knossos-run.sh' dashboard proj"
expect_silent_success 'container component-detail with a name that reads as an option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" component-detail /tmp -x
expect_output 'container scan runs the rescan command' "img:1|rescan|$ABS_PROJ|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" scan "$STUBS/proj"
expect_silent_success 'container scan refuses an option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" scan /tmp --db=/x
expect_output 'container allow-root grants the root with --execute' "img:1|allow-root|$ABS_PROJ|--execute|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" allow-root "$STUBS/proj"
expect_silent_success 'container allow-root refuses an option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" allow-root /tmp --db=/x
expect_output 'container file-detail passes the file under the project' "img:1|file-detail|$ABS_PROJ/src/A.php|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" file-detail "$STUBS/proj" src/A.php
expect_silent_success 'container offers no watcher' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" watch "$STUBS/proj"
expect_silent_success 'container file-detail with an absolute path' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" file-detail /tmp /etc/passwd
expect_silent_success 'container component-detail without a name' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" component-detail /tmp
expect_output 'container file-context passes the file under the project' "img:1|file-context|$ABS_PROJ/src/A.php|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" file-context "$STUBS/proj" src/A.php
expect_output 'container graph-search passes what was typed' "img:1|graph-search|$ABS_PROJ|--query=dash|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" graph-search "$STUBS/proj" --query=dash
expect_silent_success 'container graph-search refuses another option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" graph-search "$STUBS/proj" --db=/tmp/other.sqlite
expect_output 'container branch-diff asks for the project alone' "img:1|branch-diff|$ABS_PROJ|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" branch-diff "$STUBS/proj"
expect_output 'container churn asks for the project alone' "img:1|churn|$ABS_PROJ|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" churn "$STUBS/proj"
expect_output 'container annotate records a confirmed note' "img:1|annotate|$ABS_PROJ|--component=A|--value=n|--execute|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" annotate "$STUBS/proj" --component=A --value=n --execute
expect_silent_success 'container annotate refuses another option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" annotate "$STUBS/proj" --component=A --value=n --db=/tmp/x
expect_output 'container path-between passes both ends' "img:1|path-between|$ABS_PROJ|--from=A|--to=B|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" path-between "$STUBS/proj" --from=A --to=B
expect_output 'container blast-radius passes the component' "img:1|blast-radius|$ABS_PROJ|--component=A|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" blast-radius "$STUBS/proj" --component=A
# The session commands, as the local wrapper takes them; the two that read git run as the caller, whom git trusts with the mounted project.
ME="$(id -u):$(id -g)"
expect_output 'container session-changes reads since the snapshot it names' "img:1|session-changes|$ABS_PROJ|--since=scan_ab12|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-changes "$STUBS/proj" --since=scan_ab12
expect_silent_success 'container session-changes refuses another option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-changes "$STUBS/proj" --db=/tmp/other.sqlite
expect_output 'container boundary-couplings passes the two boundaries' "img:1|boundary-couplings|$ABS_PROJ|--from=Edge|--to=Core|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" boundary-couplings "$STUBS/proj" --from=Edge --to=Core
expect_silent_success 'container boundary-couplings refuses another option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" boundary-couplings "$STUBS/proj" --from=Edge --db=/tmp/other.sqlite
expect_output 'container session-head runs as the caller' "user=$ME|img:1|session-head|$ABS_PROJ|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-head "$STUBS/proj"
expect_silent_success 'container session-head refuses an option' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-head "$STUBS/proj" --db=/tmp/other.sqlite
expect_output 'container session-diff runs as the caller with the commit and the file' "user=$ME|img:1|session-diff|$ABS_PROJ|--rev=$REV|--file=src/a b.php|--json|" \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-diff "$STUBS/proj" "--rev=$REV" '--file=src/a b.php'
for bad in --file=../a.php --file=/etc/passwd --file=-x --file=a/../../b; do
    expect_silent_success "container session-diff refuses $bad" env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-diff "$STUBS/proj" "--rev=$REV" "$bad"
done
expect_silent_success 'container session-diff refuses a commit that is not hex' \
    env PATH="$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-diff "$STUBS/proj" --rev=HEAD~1xxxxxxxxx --file=a.php
expect_output 'container without docker says so' "$NO_BINARY" \
    env PATH="$STUBS/bare" /bin/sh "$STUBS/container/knossos-run.sh" dashboard /tmp
printf '#!/bin/sh\nexit 1\n' > "$STUBS/dockerbin-failing"; mkdir -p "$STUBS/dockerfail"; mv "$STUBS/dockerbin-failing" "$STUBS/dockerfail/docker"; chmod +x "$STUBS/dockerfail/docker"
expect_silent_success 'container with a failing docker stays silent' \
    env PATH="$STUBS/dockerfail:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" dashboard /tmp

# The dashboard's own bound is 30 s and the others' as before: a stand-in
# timeout tool prints the limit it was given instead of running anything.
mkdir -p "$STUBS/timeoutbin"
# shellcheck disable=SC2016 # $1 belongs to the stub, not to this script
printf '#!/bin/sh\nprintf "%%s" "$1"\n' > "$STUBS/timeoutbin/timeout"; chmod +x "$STUBS/timeoutbin/timeout"
expect_output 'dashboard is bounded at 30 s' '30' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" dashboard /tmp
expect_output 'turn-brief is bounded at 60 s' '60' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" turn-brief /tmp
expect_output 'scan is bounded at 60 s' '60' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" scan /tmp
expect_output 'allow-root is bounded at 15 s' '15' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" KNOSSOS_ROOTS_FILE=/tmp/roots.json PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" allow-root /tmp
expect_output 'component-detail is bounded at 15 s' '15' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" component-detail /tmp X
expect_output 'file-detail is bounded at 15 s' '15' env -u KNOSSOS_RUN_TIMEOUT KNOSSOS_BIN="$STUBS/echoing" PATH="$STUBS/timeoutbin:$PATH" /bin/sh "$RUN" file-detail /tmp a.php
expect_output 'container session-diff is bounded at 15 s' '15' env -u KNOSSOS_RUN_TIMEOUT PATH="$STUBS/timeoutbin:$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" session-diff /tmp "--rev=$REV" --file=a.php
expect_output 'container dashboard is bounded at 30 s' '30' env -u KNOSSOS_RUN_TIMEOUT PATH="$STUBS/timeoutbin:$STUBS/dockerbin:$PATH" /bin/sh "$STUBS/container/knossos-run.sh" dashboard /tmp

# The wrapper, like the SessionStart hook, never runs a project's own bin/knossos.
proj="$(mktemp -d)"
home="$(mktemp -d)"
mkdir -p "$proj/bin"
printf '#!/bin/sh\n: > "%s/RAN"\n' "$proj" > "$proj/bin/knossos"
chmod +x "$proj/bin/knossos"
env -u KNOSSOS_BIN PATH="$STUBS/bare" HOME="$home" /bin/sh "$RUN" dashboard "$proj" >/dev/null 2>&1
if [ -e "$proj/RAN" ]; then
    printf 'FAIL project-local binary was executed by the wrapper\n'
    failures=$((failures + 1))
else
    printf 'ok   wrapper never executes a project-local binary\n'
fi
rm -rf "$proj" "$home"

[ "$failures" -eq 0 ] || exit 1
