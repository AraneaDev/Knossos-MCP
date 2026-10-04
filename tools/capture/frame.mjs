/**
 * Dev-only. Turns one captured pane (ANSI text from tmux) into an HTML page
 * that draws it with xterm.js, for render.mjs to screenshot.
 */
import { createRequire } from "node:module";
import path from "node:path";
import { pathToFileURL } from "node:url";

const require = createRequire(import.meta.url);
const XTERM = path.dirname(path.dirname(require.resolve("@xterm/xterm")));

// Claude Code draws in truecolor and its themes name no terminal palette, so
// the 16 ANSI colours are xterm.js's own defaults. Foreground and background
// are the terminal colours tools/pane-preview.mjs draws each theme on.
const ANSI = {
    black: "#2e3436",
    red: "#cc0000",
    green: "#4e9a06",
    yellow: "#c4a000",
    blue: "#3465a4",
    magenta: "#75507b",
    cyan: "#06989a",
    white: "#d3d7cf",
    brightBlack: "#555753",
    brightRed: "#ef2929",
    brightGreen: "#8ae234",
    brightYellow: "#fce94f",
    brightBlue: "#729fcf",
    brightMagenta: "#ad7fa8",
    brightCyan: "#34e2e2",
    brightWhite: "#eeeeec",
};

export const THEMES = {
    dark: {
        ...ANSI,
        foreground: "#d4d4d4",
        background: "#1e1e1e",
        cursor: "#d4d4d4",
    },
    light: {
        ...ANSI,
        foreground: "#1f1f1f",
        background: "#ffffff",
        cursor: "#1f1f1f",
    },
};

/** JSON that is safe inside a <script> element: no `<` can close it. */
const embed = (value) => JSON.stringify(value).replace(/</g, "\\u003c");

export function framePage(ansi, { cols, rows, theme }) {
    const colours = THEMES[theme];
    if (colours === undefined) throw new Error(`unknown theme: ${theme}`);
    const options = {
        cols,
        rows,
        theme: colours,
        fontFamily: "DejaVu Sans Mono",
        fontSize: 14,
        allowProposedApi: true,
    };
    const lib = (file) => pathToFileURL(path.join(XTERM, file)).href;
    return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="${lib("css/xterm.css")}">
<style>
html, body { margin: 0; background: ${colours.background}; }
#terminal { display: inline-block; padding: 16px; }
</style>
<script src="${lib("lib/xterm.js")}"></script>
</head>
<body>
<div id="terminal"></div>
<script>
(async () => {
    const frame = ${embed(ansi)};
    const options = ${embed(options)};
    await document.fonts.load('14px "DejaVu Sans Mono"');
    const term = new Terminal(options);
    term.open(document.getElementById("terminal"));
    // tmux ends the last row with a newline too; writing it would scroll the first row away.
    const text = frame.replace(/\\n$/, "").replace(/\\n/g, "\\r\\n");
    term.write(text + "\\u001b[?25l", () => { window.ready = true; });
})();
</script>
</body>
</html>
`;
}
