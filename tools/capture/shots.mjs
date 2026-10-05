/**
 * Dev-only. The captures as data: one entry a shot, each a terminal size, a
 * theme and the steps shoot.mjs plays in an isolated Claude Code session.
 *
 * Steps:
 * - `type`: text typed into whatever has the keyboard (`delayMs` between keys,
 *   or between words with `words`).
 * - `send`: tmux key names, sent as they are.
 * - `submit`: runs its `steps` (typing into the prompt), then sends Enter only
 *   once the prompt line reads exactly `expect` and nothing matches `absent`;
 *   otherwise the prompt is cleared (ctrl+u) and typed again, twice at most,
 *   and the step fails without Enter.
 * - `press`: a key for the pane; the pane is given the keyboard first if it lost it.
 * - `focus`: gives the pane the keyboard (ctrl+x tab) unless it has it; with
 *   `field`, its open field; with `prompt`, hands it back to the prompt (Escape).
 * - `widen`: widens the pane (ctrl+x left) until it is `columns` wide.
 * - `resize`: resizes the terminal to `size` ([columns, rows]); the stills
 *   after it are taken at that size.
 * - `wait`: a visible beat: until `match` (a regex source, multiline) is on
 *   screen, or gone with `absent`, then a moment to read it. `fixture` names
 *   the FIXTURE entry the match depends on, for the error on a timeout.
 * - `hold`: keeps the screen as it is for `ms` (in a GIF, copies of the last frame).
 * - `edit`: writes `text` into a file of the worktree, above the first line
 *   holding `before`, as an edit would.
 * - `roll`: starts the GIF here, once the screen has stopped drawing.
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

/**
 * What the shots expect of this project's graph and source. Each comes from
 * somewhere a refactor can move it; change it here, once, when it does.
 */
export const FIXTURE = {
    // A top hub: the class in src/Query/ResultEnvelope.php, third on the Hubs tab
    // sorted by in-degree (so two `j` mark it). Its blast radius has three rings.
    hub: "ResultEnvelope",
    // Letters of the hub's name the finder is given; ResultEnvelope must be the first match.
    hubLetters: "ResultEnv",
    // A command that reaches the hub in a few hops: ServeCommand::run in src/Cli/Command/ServeCommand.php.
    routeFrom: "ServeCommand::run",
    // The boundary pair the Boundaries tab marks first: the tests and core boundaries of knossos.json,
    // the heaviest flow (tests depend on core the most).
    boundaryPair: "tests → core",
    // The label hooks/lib/diagram.ts draws under a cycle once the diagram closes the loop.
    cycleDrawn: "back to the start",
    // The small file the hero's turn edits, and the line the edit goes above.
    edited: "hooks/lib/paths.ts",
    editAnchor: "function normalise",
};

/** A FIXTURE value inside a regex, matched as written. */
const lit = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * What no capture may show: the token count Claude Code's spinner gives
 * mid-turn (`↓ 82 tokens`, `↑ 1,204 tokens`, `1.2k tokens`). A GIF frame
 * that shows it is left out; a still that shows it fails. A plain count
 * such as the pane's own "12 tokens" is not the spinner's and stays.
 */
export const NEVER_SHOWN = String.raw`(?:[↓↑]\s*\d[\d,]*(?:\.\d+)?k?|\b\d+(?:\.\d+)?k) tokens\b`;

/** The GIF's frame interval: 8 frames a second. */
export const FRAME_MS = 125;
/** How long a `wait` lets the screen stand once its text is there. */
export const WAIT_MS = 1000;
/** What a `wait` costs in the GIF, on average: the time-lapsed wait itself plus WAIT_MS. */
export const WAIT_BUDGET_MS = 1500;
/** The longest the hero may play. */
export const HERO_MAX_MS = 30000;

const SIZES = { hero: [160, 48], doc: [160, 48], wide: [224, 56] };
/** How wide the pane is drawn at each size: past 130 columns it puts the marked row's detail beside a tab. */
const PANE = { 160: 96, 224: 140 };

export const STEP_KINDS = [
    "type",
    "send",
    "submit",
    "press",
    "focus",
    "widen",
    "resize",
    "wait",
    "hold",
    "edit",
    "roll",
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

/** A row of the slash command list: the command, two spaces or more, its description. */
export const COMMAND_LIST = String.raw`^\s*/knossos(:graph)?\s{2,}\S`;

/**
 * `/knossos`, then a space once the command is listed: the space closes the
 * list, since Enter on the list runs the highlighted knossos:graph skill
 * instead, which starts a model turn. Enter goes only once the prompt reads
 * `/knossos ` and the list is gone.
 */
export const knossosCommand = {
    do: "submit",
    expect: "/knossos ",
    absent: COMMAND_LIST,
    steps: [
        {
            do: "type",
            text: "/knossos",
            until: String.raw`^\s*/knossos\s+Toggle`,
        },
        { do: "type", text: " " },
    ],
};

/** Opens the pane, gives it the keyboard and widens it to `columns`, so the tabs are named. */
const openPane = (columns, beat = true) => [
    { ...knossosCommand, until: "Overview" },
    { do: "focus" },
    { do: "widen", columns },
    beat
        ? wait("pane widened", String.raw`Overview\s+Hubs\s+Boundaries`)
        : { do: "focus", until: String.raw`Overview\s+Hubs\s+Boundaries` },
];

/** A row the marker stands on, naming `name`. */
const marked = (name) => String.raw`›\s+${lit(name)}\s`;

/** The finder's field: `f` opens it with the keys in it, checked before typing, so a word can never reach the prompt. */
const find = (text, fixture, delayMs = 0) => [
    press("f", String.raw`⌕ Find`),
    { do: "focus", field: true },
    { do: "type", text, delayMs },
    wait(`found ${text}`, marked(FIXTURE[fixture]), { fixture }),
];

/** Finds the hub and opens its detail. */
const openHub = (delayMs = 0) => [
    ...find(FIXTURE.hubLetters, "hub", delayMs),
    { do: "send", keys: ["Enter"], until: "Blast radius" },
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

/** The Changes heading's count of the session's own scans, one or more. */
export const THIS_SESSION = String.raw`scans\b.*\b[1-9]\d* this session`;

/** The hero's one prompt. */
export const PROMPT = `Add a one-line comment above the normalise function in ${FIXTURE.edited} saying what it does. Edit only that file and run nothing.`;

const hubDetail = (theme) =>
    pane(
        theme === "light" ? "hubs-detail-light" : "hubs-detail",
        [
            press("2"),
            press("j"),
            press("j"),
            wait(
                "hub detail",
                String.raw`${marked(FIXTURE.hub)}[\s\S]*Dependencies · used by`,
                { fixture: "hub" },
            ),
        ],
        { size: SIZES.wide, theme },
    );

export const SHOTS = {
    hero: {
        size: SIZES.hero,
        theme: "dark",
        gif: true,
        maxMs: HERO_MAX_MS,
        // One model turn, kept to the worktree: only Read and Edit exist, and edits are accepted.
        claude: ["--tools", "Read,Edit", "--permission-mode", "acceptEdits"],
        steps: [
            ...ready,
            { do: "roll" },
            hold(250),
            ...openPane(PANE[160]),
            hold(1000),
            press("2"),
            press("j"),
            press("j"),
            wait("hub marked", marked(FIXTURE.hub), { fixture: "hub" }),
            hold(1000),
            press("4"),
            wait("cycle drawn", lit(FIXTURE.cycleDrawn), {
                fixture: "cycleDrawn",
            }),
            hold(1000),
            press("3"),
            wait("boundaries", String.raw`Per boundary`),
            hold(1000),
            ...openHub(60),
            press("End"),
            wait("rings", String.raw`◉ ${lit(FIXTURE.hub)}`, {
                fixture: "hub",
            }),
            hold(1500),
            // The prompt gets the keyboard back for the model turn: typed into the pane, its letters would be keys.
            { do: "focus", prompt: true },
            {
                do: "submit",
                expect: PROMPT,
                steps: [{ do: "type", text: PROMPT, delayMs: 60, words: true }],
            },
            // The turn's note: the band over the prompt sums up what the edit reaches.
            wait("note on the turn", String.raw`knossos · \d+ files? →`, {
                timeoutMs: 180000,
                lapse: 8,
            }),
            press("b", String.raw`Per boundary`),
            press("Home"),
            press("6", String.raw`± Changes this session`),
            wait("change listed", marked(FIXTURE.edited), {
                timeoutMs: 60000,
                fixture: "edited",
            }),
            press("o"),
            wait("diff", String.raw`Changed since the session began`),
            hold(2000),
            { do: "cut" },
            wait("turn over", String.raw`esc to interrupt`, {
                absent: true,
                timeoutMs: 180000,
            }),
            // Changes with the turn's own edit in it, so the still shows a change from this session:
            // wide enough for the list with the marked file's detail and diff beside it.
            { do: "resize", size: SIZES.wide },
            { do: "focus" },
            { do: "widen", columns: PANE[224] },
            press("b", String.raw`± Changes this session`),
            wait(
                "change and its diff",
                String.raw`${marked(FIXTURE.edited)}[\s\S]*Changed since the session began`,
                { timeoutMs: 60000, fixture: "edited" },
            ),
            wait("from this session", THIS_SESSION, { timeoutMs: 60000 }),
            wait("fresh", String.raw`● live`, { timeoutMs: 60000 }),
            still("changes-diff"),
            // The band takes the turn's figures once the turn is over; it shows with the pane shut.
            { do: "resize", size: SIZES.hero },
            { do: "focus", prompt: true },
            knossosCommand,
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
        { theme: "light" },
    ),
    "hubs-detail": hubDetail("dark"),
    "hubs-detail-light": hubDetail("light"),
    cycles: pane("cycles", [
        press("4"),
        wait("cycle drawn", lit(FIXTURE.cycleDrawn), { fixture: "cycleDrawn" }),
    ]),
    boundaries: pane("boundaries", [
        press("3"),
        wait("boundaries", lit(FIXTURE.boundaryPair), {
            fixture: "boundaryPair",
        }),
    ]),
    branch: pane(
        "branch",
        [
            press("7"),
            wait("branch", String.raw`Against the merge base`, {
                timeoutMs: 60000,
            }),
        ],
        { size: SIZES.wide, base: true },
    ),
    churn: pane("churn", [
        press("8"),
        wait("churn", String.raw`Churn hotspots`),
    ]),
    finder: pane("finder", find(FIXTURE.hubLetters, "hub")),
    route: pane("route", [
        ...find(FIXTURE.routeFrom, "routeFrom"),
        { do: "send", keys: ["Enter"], until: "Blast radius" },
        press("p", String.raw`Route from`),
        { do: "focus", field: true },
        { do: "type", text: FIXTURE.hub },
        wait("target found", marked(FIXTURE.hub), { fixture: "hub" }),
        { do: "send", keys: ["Enter"] },
        wait("route drawn", String.raw`Route 1 · \d+ hops?`),
    ]),
    rings: pane("rings", [
        ...openHub(),
        press("End"),
        wait("rings", String.raw`◉ ${lit(FIXTURE.hub)}`, { fixture: "hub" }),
    ]),
    "note-on-detail": pane("note-on-detail", [
        ...openHub(),
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
