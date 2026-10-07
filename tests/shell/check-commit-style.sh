#!/bin/sh
# The commit-style check accepts exactly the types release-please knows, whatever tools are installed.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="$HERE/../../tools/check-commit-style.sh"
failures=0
nojq="$(mktemp -d)"
trap 'rm -rf "$nojq"' EXIT
mkdir "$nojq/bin"
# Every external tool the check needs, and deliberately not jq.
for tool in bash dirname sed grep sort paste tr cat head; do
    real=$(command -v "$tool") && ln -s "$real" "$nojq/bin/$tool"
done
check() { # label expected-status path-mode subject
    if [ "$3" = nojq ]; then p="$nojq/bin"; else p="$PATH"; fi
    printf '%s\n' "$4" > "$nojq/msg"
    PATH="$p" bash "$CHECK" --file "$nojq/msg" >/dev/null 2>&1
    s=$?
    if { [ "$2" = ok ] && [ "$s" -eq 0 ]; } || { [ "$2" = fail ] && [ "$s" -eq 1 ]; }; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (status %s)\n' "$1" "$s"; failures=$((failures + 1))
    fi
}
for mode in jq nojq; do
    check "$mode: feat accepted" ok "$mode" 'feat: add a thing'
    check "$mode: chore accepted" ok "$mode" 'chore: tidy a thing'
    check "$mode: build rejected" fail "$mode" 'build: bump a thing'
    check "$mode: revert rejected" fail "$mode" 'revert: undo a thing'
    check "$mode: generic rejected" fail "$mode" 'generic: not a type'
done
[ "$failures" -eq 0 ]
