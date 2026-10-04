/**
 * Dev-only. The captures as data: one entry a shot, each a terminal size, a
 * theme and the steps shoot.mjs plays in an isolated Claude Code session.
 *
 * Steps:
 * - `type`: text typed into whatever has the keyboard (`delayMs` between keys,
 *   or between words with `words`).
 * - `send`: tmux key names, sent as they are.
 * - `press`: a key for the pane; the pane is given the keyboard first if it lost it.
 * - `focus`: gives the pane the keyboard (ctrl+x tab) unless it has it; with
 *   `field`, its open field; with `prompt`, hands it back to the prompt (Escape).
 * - `widen`: widens the pane (ctrl+x left) until it is `columns` wide.
 * - `wait`: a visible beat: until `match` (a regex source, multiline) is on
 *   screen, or gone with `absent`, then a moment to read it.
 * - `hold`: keeps the screen as it is for `ms` (in a GIF, copies of the last frame).
 * - `edit`: writes a line into a file of the worktree, as an edit would.
 * - `cut`: ends the GIF's recording; the steps after it only take stills.
 * - `still`: once nothing on screen moves, keeps the screen as `name`.png.
 *
 * `type`, `send` and `press` take `until`, a regex that must be on screen
 * before the next step: a quick sync, not a beat the viewer sees. A `press`
 * whose `until` does not come is sent again.
 *
 * Tab digits and keys come from hooks/lib/layout.ts (TABS, footerRows) and
 * the bindings Claude Code gives a plugin pane (ctrl+x tab, ctrl+x left).
 */

/** The GIF's frame interval: 8 frames a second. */
export const FRAME_MS = 125;
/** How long a `wait` lets the screen stand once its text is there. */
export const WAIT_MS = 1000;
/** What a `wait` costs in the GIF, on average: the time-lapsed wait itself plus WAIT_MS. */
export const WAIT_BUDGET_MS = 1500;

const SIZES = { hero: [160, 48], doc: [160, 48], wide: [224, 56] };
/** How wide the pane is drawn at each size: past 130 columns it puts the marked row's detail beside a tab. */
const PANE = { 160: 96, 224: 140 };

export const STEP_KINDS = [
    "type",
    "send",
    "press",
    "focus",
    "widen",
    "wait",
    "hold",
    "edit",
    "cut",
    "still",
];

const wait = (step, match, more = {}) => ({ do: "wait", step, match, ...more });
const press = (key, until) => ({
    do: "press",
    key,
    ...(until ? { until } : {}),
});
const hold = (ms) => ({ do: "hold", ms });
const still = (name) => ({ do: "still", name });

/** Claude Code is up: its prompt, not a dialog's numbered choice. */
const ready = [wait("prompt", String.raw`^❯(?! *\d\.)`, { timeoutMs: 90000 })];

/**
 * Opens the pane: `/knossos`, then a space once the command is listed (it
 * closes the list: Enter on the list runs the highlighted knossos:graph skill
 * instead, which starts a model turn), then Enter. The pane is given the
 * keyboard and widened to `columns`, so the tabs are named.
 */
const openPane = (columns, beat = true) => [
    { do: "type", text: "/knossos", until: String.raw`^\s*/knossos\s+Toggle` },
    {
        do: "type",
        text: " ",
        until: String.raw`^(?![\s\S]*^\s*/knossos:graph)`,
    },
    { do: "send", keys: ["Enter"], until: "Overview" },
    { do: "focus" },
    { do: "widen", columns },
    beat
        ? wait("pane widened", String.raw`Overview\s+Hubs\s+Boundaries`)
        : { do: "focus", until: String.raw`Overview\s+Hubs\s+Boundaries` },
];

/** The finder's field, given the keyboard: `f` opens it, and a first key may not reach it. */
const find = (text, match, delayMs = 0) => [
    press("f", String.raw`⌕ Find`),
    { do: "focus", field: true },
    { do: "type", text, delayMs },
    wait(`found ${text}`, match),
];

/** A still of one view: open the pane, do `steps`, keep the screen as `name`. */
const pane = (
    name,
    steps,
    { theme = "dark", size = SIZES.doc, base = false } = {},
) => ({
    size,
    theme,
    ...(base ? { base } : {}),
    steps: [...ready, ...openPane(PANE[size[0]], false), ...steps, still(name)],
});

/** The edit the hero's model turn makes, and the one the Changes stills make by hand. */
const EDITED = "hooks/lib/paths.ts";
const PROMPT =
    "Add a one-line comment above the normalise function in hooks/lib/paths.ts saying what it does. Edit only that file and run nothing.";
const handEdit = {
    do: "edit",
    file: EDITED,
    line: 8,
    text: "// Resolves . and .. segments, so two spellings of one path compare equal.",
};

export const SHOTS = {
    hero: {
        size: SIZES.hero,
        theme: "dark",
        gif: true,
        // One model turn, kept to the worktree: only Read and Edit exist, and edits are accepted.
        claude: ["--tools", "Read,Edit", "--permission-mode", "acceptEdits"],
        steps: [
            ...ready,
            hold(500),
            ...openPane(PANE[160]),
            hold(1500),
            press("2"),
            press("j"),
            press("j"),
            wait("hub marked", String.raw`›\s+ResultEnvelope\s`),
            hold(1500),
            press("4"),
            wait("cycle drawn", String.raw`back to the start`),
            hold(1500),
            press("3"),
            wait("boundaries", String.raw`Per boundary`),
            hold(1500),
            ...find("ResultEnv", String.raw`›\s+ResultEnvelope\s`, 60),
            { do: "send", keys: ["Enter"], until: "Blast radius" },
            press("End"),
            wait("rings", String.raw`◉ ResultEnvelope`),
            hold(2000),
            // The prompt gets the keyboard back for the model turn: typed into the pane, its letters would be keys.
            { do: "focus", prompt: true },
            // Checked before Enter: only text that reached the prompt is sent.
            {
                do: "type",
                text: PROMPT,
                delayMs: 60,
                words: true,
                until: "Edit only that file",
            },
            { do: "send", keys: ["Enter"] },
            // The turn's note: the band over the prompt sums up what the edit reaches.
            wait("note on the turn", String.raw`knossos · \d+ files? →`, {
                timeoutMs: 180000,
                lapse: 8,
            }),
            press("b", String.raw`Per boundary`),
            press("Home"),
            press("6", String.raw`± Changes this session`),
            wait("change listed", String.raw`›\s+hooks/lib/paths\.ts`, {
                timeoutMs: 60000,
            }),
            press("o"),
            wait("diff", String.raw`Changed since the session began`),
            hold(2500),
            { do: "cut" },
            // The band takes the turn's figures once the turn is over; it shows with the pane shut.
            wait("turn over", String.raw`esc to interrupt`, {
                absent: true,
                timeoutMs: 180000,
            }),
            { do: "focus", prompt: true },
            {
                do: "type",
                text: "/knossos",
                until: String.raw`^\s*/knossos\s+Toggle`,
            },
            {
                do: "type",
                text: " ",
                until: String.raw`^(?![\s\S]*^\s*/knossos:graph)`,
            },
            { do: "send", keys: ["Enter"] },
            wait("band", String.raw`knossos · `, { timeoutMs: 120000 }),
            still("band"),
        ],
    },
    overview: pane("overview", [
        wait("overview", String.raw`Cross-boundary flows`),
    ]),
    "overview-light": pane(
        "overview-light",
        [wait("overview", String.raw`Cross-boundary flows`)],
        {
            theme: "light",
        },
    ),
    "hubs-detail": pane(
        "hubs-detail",
        [
            press("2"),
            press("j"),
            press("j"),
            wait(
                "hub detail",
                String.raw`›\s+ResultEnvelope\s[\s\S]*Dependencies · used by`,
            ),
        ],
        { size: SIZES.wide },
    ),
    "hubs-detail-light": pane(
        "hubs-detail-light",
        [
            press("2"),
            press("j"),
            press("j"),
            wait(
                "hub detail",
                String.raw`›\s+ResultEnvelope\s[\s\S]*Dependencies · used by`,
            ),
        ],
        { size: SIZES.wide, theme: "light" },
    ),
    cycles: pane("cycles", [
        press("4"),
        wait("cycle drawn", String.raw`back to the start`),
    ]),
    boundaries: pane("boundaries", [
        press("3"),
        wait("boundaries", String.raw`tests → core`),
    ]),
    "changes-diff": pane(
        "changes-diff",
        [
            // Made once the watcher runs, so the graph takes it in and stays fresh.
            handEdit,
            press("6"),
            wait(
                "change and its diff",
                String.raw`›\s+hooks/lib/paths\.ts[\s\S]*Changed since the session began`,
                { timeoutMs: 60000 },
            ),
            wait("fresh", String.raw`● live`, { timeoutMs: 60000 }),
        ],
        { size: SIZES.wide },
    ),
    branch: pane(
        "branch",
        [
            press("7"),
            wait("branch", String.raw`Against the merge base`, {
                timeoutMs: 60000,
            }),
        ],
        {
            size: SIZES.wide,
            base: true,
        },
    ),
    churn: pane("churn", [
        press("8"),
        wait("churn", String.raw`Churn hotspots`),
    ]),
    finder: pane("finder", find("ResultEnv", String.raw`›\s+ResultEnvelope\s`)),
    route: pane("route", [
        ...find("ServeCommand::run", String.raw`›\s+ServeCommand::run\s`),
        { do: "send", keys: ["Enter"], until: "Blast radius" },
        press("p", String.raw`Route from`),
        { do: "focus", field: true },
        { do: "type", text: "ResultEnvelope" },
        wait("target found", String.raw`›\s+ResultEnvelope\s`),
        { do: "send", keys: ["Enter"] },
        wait("route drawn", String.raw`Route 1 · \d+ hops?`),
    ]),
    rings: pane("rings", [
        ...find("ResultEnv", String.raw`›\s+ResultEnvelope\s`),
        { do: "send", keys: ["Enter"], until: "Blast radius" },
        press("End"),
        wait("rings", String.raw`◉ ResultEnvelope`),
    ]),
    "note-on-detail": pane("note-on-detail", [
        ...find("ResultEnv", String.raw`›\s+ResultEnvelope\s`),
        { do: "send", keys: ["Enter"], until: "Blast radius" },
        press("End"),
        press("m", String.raw`note: `),
        { do: "focus", field: true },
        {
            do: "type",
            text: "Every MCP answer is built here; change its shape and every tool's output changes.",
        },
        { do: "send", keys: ["Enter"], until: String.raw`record` },
        press("y"),
        wait("note recorded", String.raw`Record this note`, { absent: true }),
        // Recording the note draws the detail again from its top.
        hold(1500),
        press("End"),
        wait("note kept", String.raw`m: change the note`),
    ]),
};
