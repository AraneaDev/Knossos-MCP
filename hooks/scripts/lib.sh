# shellcheck shell=sh
# Shared helpers for the Knossos hook scripts. Sourced, never executed.
#
# Callers run under `set -u`.

# Set at install time to the data directory the MCP server uses; empty when unknown.
# A checkout that was never installed still holds the placeholder, which counts as unknown.
KNOSSOS_INSTALLED_DATA_DIR='__KNOSSOS_DATA_DIR__'
case $KNOSSOS_INSTALLED_DATA_DIR in __*__) KNOSSOS_INSTALLED_DATA_DIR='' ;; esac
if [ -n "$KNOSSOS_INSTALLED_DATA_DIR" ]; then
    : "${KNOSSOS_DATA_DIR:=$KNOSSOS_INSTALLED_DATA_DIR}"
    : "${KNOSSOS_ROOTS_FILE:=$KNOSSOS_INSTALLED_DATA_DIR/roots.json}"
    export KNOSSOS_DATA_DIR KNOSSOS_ROOTS_FILE
fi

# Set at install time to the knossos binary of the checkout that installed the
# plugin; empty when unknown. Searched before PATH, and a project's own bin/ is
# never searched at all: opening a repository must not run code it ships.
KNOSSOS_INSTALLED_BIN='__KNOSSOS_BIN__'
case $KNOSSOS_INSTALLED_BIN in __*__) KNOSSOS_INSTALLED_BIN='' ;; esac

# Discovery order: an explicit override, the installed checkout's binary, PATH,
# then the conventional locations. Deliberately short: a long search is a slow
# session start.
find_knossos() {
    if [ -n "${KNOSSOS_BIN:-}" ] && [ -x "${KNOSSOS_BIN}" ]; then
        printf '%s' "${KNOSSOS_BIN}"
        return 0
    fi
    if [ -n "$KNOSSOS_INSTALLED_BIN" ] && [ -x "$KNOSSOS_INSTALLED_BIN" ]; then
        printf '%s' "$KNOSSOS_INSTALLED_BIN"
        return 0
    fi
    if command -v knossos >/dev/null 2>&1; then
        command -v knossos
        return 0
    fi
    # HOME is expanded only when it is set: the callers run under `set -u`,
    # and a bare $HOME with HOME unset makes the shell print a diagnostic on
    # stderr, which a hook promising silence must not do.
    for candidate in "${HOME:+$HOME/.local/bin/knossos}" /usr/local/bin/knossos; do
        if [ -x "$candidate" ]; then
            printf '%s' "$candidate"
            return 0
        fi
    done
    return 1
}

# `timeout` is GNU coreutils. A plain macOS ships none of it, and Homebrew's
# coreutils installs the same tool as `gtimeout` so it never shadows a BSD
# tool of the same name. Try both names before giving up the bound.
find_timeout() {
    if command -v timeout >/dev/null 2>&1; then
        command -v timeout
        return 0
    fi
    if command -v gtimeout >/dev/null 2>&1; then
        command -v gtimeout
        return 0
    fi
    return 1
}
