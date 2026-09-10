<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Discovery\RootNotFoundException;
use PDO;
use Throwable;

/**
 * Gathers and renders the brief injected at the start of a Claude Code session.
 *
 * Read-only by contract: it never scans, writes, or executes project code. The
 * hook that calls it runs before the session does anything, so a side effect
 * here would be a side effect nobody asked for. That contract extends to the
 * root-allowance check this service also performs: it only ever reads
 * `roots.json` and reuses {@see RootGuard::resolve()}'s containment logic, so
 * the check can never drift from what a real `scan_project` call would decide.
 *
 * Boundary rules are read from `knossos.json` on disk rather than from the copy
 * stored in `projects.config_json` at scan time, because a policy edited since
 * the last scan is still the policy CI will enforce.
 */
final readonly class SessionBriefService
{
    private const MAX_NOTES = 5;
    private const MAX_ENTRY_POINTS = 4;
    private const MAX_HUBS = 5;

    /**
     * @param string|null $databasePath where the database lives, used only to
     *   locate `roots.json` beside it (see {@see AllowedRoots::defaultConfigPath()}).
     *   Optional and nullable so callers that have no database path on hand
     *   (or none at all, or only the ':memory:' sentinel) still work:
     *   {@see self::rootStatus()} then treats every path as allowed rather
     *   than raising a warning it has no basis for. A missing database path
     *   is not evidence of a missing root.
     */
    public function __construct(private PDO $pdo, private ?string $databasePath = null) {}

    /**
     * The gathered brief for whatever project contains this path.
     *
     * Public, and separate from {@see self::brief()}, because the rendered
     * text cannot show whether graph sections were gathered and then dropped
     * downstream, or never gathered at all: {@see SessionBriefRenderer}
     * independently withholds entry points and hubs for any non-fresh state,
     * so a check against rendered text cannot tell the two apart. Exposing
     * this step is what makes the config-versus-graph split (rules and notes
     * survive every state; entry points and hubs are fresh-only) observable
     * on its own terms.
     */
    public function gather(string $path): SessionBrief
    {
        $project = (new ProjectPathResolver($this->pdo))->resolve($path);
        if ($project === null) {
            return self::unscanned($path, $this->databasePath);
        }
        $root = (string) $project['root_realpath'];
        $projectId = (string) $project['id'];
        // No `?? ['state' => 'missing']` fallback, because nothing reaches it.
        // StalenessProbe::probe() returns null for exactly three literal ids
        // ('', 'catalog' and 'server'), which name server scopes rather than
        // projects; every other id, including one with no project row, comes
        // back as a 'missing' array. $projectId here is the id column of a
        // projects row, and the only writer of that column fills it with
        // StableId::project()'s `project_<sha256>`, so none of the three can
        // arrive. A branch no input can reach reads as a case that has been
        // handled, which is a worse thing for the next reader to believe than
        // an absent one.
        /** @var array<string, mixed> $probe */
        $probe = (new StalenessProbe($this->pdo))->probe($projectId);
        $state = (string) $probe['state'];
        $drift = (int) ($probe['changed_files_since'] ?? 0)
            + (int) ($probe['added_files_since'] ?? 0)
            + (int) ($probe['deleted_files_since'] ?? 0);
        // Checked for every state, not just 'missing'. "Scanned implies the
        // root was accepted" does not hold: `knossos scan` passes the root it
        // was given as its own allow-list, so a CLI scan self-authorises any
        // path and leaves a project whose root no `roots.json` covers. A
        // 'stale' verdict there would otherwise tell an agent to run a
        // `scan_project` the server is bound to reject. One RootGuard call is
        // cheap enough to pay on every state rather than reason about which
        // ones can be trusted to have earned it.
        $rootStatus = self::rootStatus($this->databasePath, $root);

        return new SessionBrief(
            $state,
            $projectId,
            (string) $project['name'],
            $root,
            isset($probe['age_seconds']) ? (int) $probe['age_seconds'] : null,
            $drift,
            $this->trackedFiles($projectId),
            $this->rules($root),
            $this->notes($projectId),
            $state === 'fresh' ? $this->entryPoints($projectId) : [],
            $state === 'fresh' ? $this->hubs($projectId) : [],
            $rootStatus['allowed'],
            $rootStatus['exists'],
        );
    }

    /**
     * The brief for a path that belongs to no project, built without a graph.
     *
     * Static, and public, because the session-start path must be able to render
     * it with no database open at all: {@see \Knossos\Cli\Command\SessionCommand}
     * checks whether the database file exists before opening anything, and
     * opening one to say "not scanned" would create and migrate the very file
     * whose absence is the answer.
     *
     * @param string|null $databasePath only to locate `roots.json` beside it
     */
    public static function unscanned(string $path, ?string $databasePath = null): SessionBrief
    {
        // Resolve to an absolute path the same way ProjectPathResolver does
        // internally, so the verdict line can hand an agent a command it can
        // actually run. An MCP server has its own working directory and
        // allowed roots, so a relative argument here would be meaningless.
        $absolute = realpath($path) ?: $path;
        $rootStatus = self::rootStatus($databasePath, $absolute);

        return new SessionBrief(
            'unscanned',
            null,
            null,
            $absolute,
            null,
            0,
            0,
            pathAllowed: $rootStatus['allowed'],
            pathExists: $rootStatus['exists'],
        );
    }

    /**
     * Whether $absolutePath is there at all, and whether it lies inside a root
     * the CLI can see right now.
     *
     * Both answers come from one {@see RootGuard::resolve()} call rather than
     * from a reimplementation of containment, so this warning can never drift
     * from the rejection a real `scan_project` call would produce. The sources
     * mirror {@see \Knossos\Cli\Command\ServeCommand::resolveRoots()}: the
     * `KNOSSOS_ALLOWED_ROOTS` environment variable, unioned with the roots
     * file beside the database.
     *
     * The two failures are read off the exception type, which is why RootGuard
     * raises two. Reading a missing directory as "not an allowed root" is how
     * the brief came to answer `/gone` with `knossos allow-root /gone`, a
     * command that then refuses the same path for the same reason: a two-step
     * dead end, in the feature that exists to stop the brief recommending
     * things that cannot work.
     *
     * Only {@see DiscoveryException} and its subclasses are caught: that is
     * RootGuard's own refusal signal, and nothing else may be silently read
     * as one.
     *
     * @return array{exists: bool, allowed: bool}
     */
    private static function rootStatus(?string $databasePath, string $absolutePath): array
    {
        // ':memory:' is PDO's in-memory sentinel, not a filesystem path (the
        // same reading DatabaseMaintenanceService and DoctorService give it
        // elsewhere); there is no directory to find a roots file beside, so
        // it is treated the same as no database path at all.
        //
        // Existence is still reported here. It is a fact about the filesystem
        // and owes nothing to the allow-list, so having no roots file to
        // consult is no reason to claim a directory is there.
        if ($databasePath === null || $databasePath === ':memory:') {
            return ['exists' => RootGuard::exists($absolutePath), 'allowed' => true];
        }
        $staticRoots = [];
        $configured = getenv('KNOSSOS_ALLOWED_ROOTS');
        if (is_string($configured) && $configured !== '') {
            $staticRoots = array_values(array_filter(explode(PATH_SEPARATOR, $configured)));
        }
        $allowedRoots = new AllowedRoots($staticRoots, AllowedRoots::defaultConfigPath($databasePath));
        try {
            (new RootGuard($allowedRoots))->resolve($absolutePath);

            return ['exists' => true, 'allowed' => true];
        } catch (RootNotFoundException) {
            // Ordered before the parent type, which would otherwise swallow it.
            return ['exists' => false, 'allowed' => false];
        } catch (DiscoveryException) {
            return ['exists' => true, 'allowed' => false];
        }
    }

    /** The rendered brief for whatever project contains this path. */
    public function brief(string $path): string
    {
        return (new SessionBriefRenderer())->render($this->gather($path));
    }

    /** How many files the scan tracks, which is what makes the unverified verdict concrete. */
    private function trackedFiles(string $projectId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Boundary policies as rules an agent can act on, not as a count.
     *
     * A missing or malformed `knossos.json` is not an error here: the brief is
     * best-effort orientation, and a config problem is the scan's business to
     * report, not the session hook's.
     *
     * @return list<string>
     */
    private function rules(string $root): array
    {
        try {
            $policies = ProjectConfigurationLoader::load($root, [$root])->policies;
        } catch (Throwable) {
            return [];
        }
        $rules = [];
        foreach ($policies as $policy) {
            $from = (string) ($policy['from_boundary'] ?? '');
            $deny = $policy['deny_targets'] ?? null;
            $allow = $policy['allow_targets'] ?? null;
            if ($from === '') {
                continue;
            }
            if (is_array($deny) && $deny !== []) {
                $rules[] = sprintf('%s -x-> %s', $from, implode(', ', $deny));
            } elseif (is_array($allow) && $allow !== []) {
                $rules[] = sprintf('%s --> only %s', $from, implode(', ', $allow));
            }
        }
        return $rules;
    }

    /**
     * What earlier sessions wrote down.
     *
     * Only `note`: the other three kinds (`intended_boundary`, `false_positive`,
     * `confirmed_dead`) exist to change how other read surfaces behave, and are
     * not orientation material.
     *
     * @return list<string>
     */
    private function notes(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT canonical_name, value FROM annotations ' .
            "WHERE project_id = :project AND kind = 'note' AND value <> '' " .
            'ORDER BY updated_at DESC, canonical_name LIMIT ' . self::MAX_NOTES,
        );
        $statement->execute(['project' => $projectId]);
        return array_map(
            static fn(array $row): string => sprintf('%s: %s', $row['canonical_name'], $row['value']),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Where execution enters the system. Graph-derived, so fresh graphs only.
     *
     * @return list<string>
     */
    private function entryPoints(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.display_name, n.kind FROM nodes n ' .
            "WHERE n.project_id = :project AND n.kind IN ('route', 'command', 'endpoint') " .
            'ORDER BY n.kind, n.canonical_name LIMIT ' . self::MAX_ENTRY_POINTS,
        );
        $statement->execute(['project' => $projectId]);
        return array_map(
            static fn(array $row): string => sprintf('%s (%s)', $row['display_name'], $row['kind']),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * The components a change is most likely to reach. Graph-derived.
     *
     * @return list<string>
     */
    private function hubs(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.display_name, COUNT(e.id) AS degree FROM nodes n ' .
            'JOIN edges e ON e.target_id = n.id ' .
            'WHERE n.project_id = :project GROUP BY n.id ' .
            'ORDER BY degree DESC, n.canonical_name LIMIT ' . self::MAX_HUBS,
        );
        $statement->execute(['project' => $projectId]);
        return array_map(
            static fn(array $row): string => (string) $row['display_name'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
