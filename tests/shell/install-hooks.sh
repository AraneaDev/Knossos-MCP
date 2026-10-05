#!/bin/sh
# tools/install-hooks must install every hook type the pre-commit config defines.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
failures=0
STUBS=$(mktemp -d)
trap 'rm -rf "$STUBS"' EXIT

# A pre-commit that only records what it was asked to install.
printf '#!/bin/sh\nprintf "%%s\\n" "$@" > "%s/args"\n' "$STUBS" > "$STUBS/pre-commit"
chmod +x "$STUBS/pre-commit"

PATH="$STUBS:$PATH" sh "$ROOT/tools/install-hooks" >/dev/null 2>&1
status=$?
if [ "$status" -ne 0 ]; then
    printf 'FAIL install-hooks exited %s\n' "$status"; failures=$((failures + 1))
fi

installed=$(awk 'prev == "--hook-type" { print } { prev = $0 }' "$STUBS/args" | sort | tr '\n' ' ')
declared=$(sed -n 's/^default_install_hook_types: \[\(.*\)\]$/\1/p' "$ROOT/.pre-commit-config.yaml" | tr ',' '\n' | tr -d ' ' | sort | tr '\n' ' ')

if [ -z "$declared" ]; then
    printf 'FAIL default_install_hook_types not found in .pre-commit-config.yaml\n'; failures=$((failures + 1))
elif [ "$installed" != "$declared" ]; then
    printf 'FAIL installs [%s], the config defines [%s]\n' "$installed" "$declared"; failures=$((failures + 1))
else
    printf 'ok   installs every defined hook type: %s\n' "$installed"
fi

# Without pre-commit it says so and fails.
if PATH="/usr/bin:/bin" sh -c 'command -v pre-commit' >/dev/null 2>&1; then
    printf 'skip no-pre-commit case: pre-commit is on the base PATH\n'
else
    PATH="/usr/bin:/bin" sh "$ROOT/tools/install-hooks" >/dev/null 2>&1 && { printf 'FAIL succeeded without pre-commit\n'; failures=$((failures + 1)); }
fi

[ "$failures" -eq 0 ]
