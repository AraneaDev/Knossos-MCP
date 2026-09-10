<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * What counts as a way into the system, written once as a SQL predicate.
 *
 * Two briefs answer this question about the same graph: the agent brief
 * ({@see AgentBriefService::entryPointsSection()}), read once by a person, and
 * the session brief ({@see SessionBriefService::entryPoints()}), injected at
 * every session start. They drifted, and the drift was invisible: the session
 * brief matched node kinds alone and so rendered no entry points at all for a
 * repository whose ways in are classified rather than kind-tagged, which is
 * every repository this scanner classifies. Nothing failed; a section simply
 * went missing. One predicate is the only way that stays fixed.
 *
 * Two halves, both load-bearing:
 *
 * - A component qualifies by its own kind, which a scanner sets when the code
 *   itself declares a route or a console command, OR by a classification role,
 *   which is how a controller or an entry-point class is recognised when the
 *   kind is merely `class`.
 * - A component classified as test code is excluded, whichever half admitted
 *   it. A command stub declared inside a test file carries the command role
 *   without being a way into the system, and a brief that offers one has spent
 *   its budget on scaffolding. This is the same exclusion the hub ranking
 *   makes, for the same reason; see {@see ReportableComponent}.
 *
 * Only the predicate is shared. Ordering and limits stay with each caller,
 * because they answer a different question — how many entry points fit here,
 * and which ones matter most when they do not all fit.
 */
final readonly class EntryPointCriteria
{
    /**
     * Node kinds a scanner emits when the code declares a way in outright.
     *
     * A stronger signal than a role, because it was read off a route
     * definition or a command declaration rather than inferred from shape.
     */
    public const KINDS = ['route', 'command', 'endpoint'];

    /**
     * Roles that mark a component as a way in when its kind does not.
     *
     * Deliberately narrower than {@see ReportableComponent::CONVENTION_DISCOVERED_ROLES},
     * which answers a different question. A queued job and an event listener
     * are also reached from outside the graph, which is why that list excuses
     * them from dead-code reporting, but neither is where a reader starts
     * following execution through the system.
     */
    public const ROLES = [
        'application.controller', 'application.command', 'application.entry_point',
        'laravel.controller', 'laravel.command',
    ];

    /**
     * The WHERE fragment selecting entry points, minus the project filter.
     *
     * Binds `:project`, which the caller supplies once however many times the
     * fragment names it. Every literal it interpolates is a constant declared
     * above, so there is no value here a caller could steer.
     *
     * @param string $alias the alias the caller gave the `nodes` table
     */
    public static function sqlCondition(string $alias = 'n'): string
    {
        return sprintf(
            '(%1$s.kind IN (%2$s) OR %1$s.id IN (' .
            'SELECT node_id FROM classifications WHERE project_id = :project AND role IN (%3$s)' .
            ')) AND %1$s.id NOT IN (' .
            'SELECT node_id FROM classifications WHERE project_id = :project AND role = %4$s' .
            ')',
            $alias,
            self::quoted(self::KINDS),
            self::quoted(self::ROLES),
            self::quoted([ReportableComponent::TEST_ROLE]),
        );
    }

    /**
     * An ORDER BY term putting kind-declared entry points ahead of role-matched ones.
     *
     * For a caller that shows every match this changes nothing worth having.
     * For one that shows four of forty it decides which four, and a declared
     * route beats a classifier's reading of a class every time.
     *
     * @param string $alias the alias the caller gave the `nodes` table
     */
    public static function sqlKindPriority(string $alias = 'n'): string
    {
        return sprintf('CASE WHEN %s.kind IN (%s) THEN 0 ELSE 1 END', $alias, self::quoted(self::KINDS));
    }

    /**
     * A comma-separated SQL literal list, for an IN clause or an equality.
     *
     * @param list<string> $values constants declared above, never caller input
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn(string $value): string => "'" . $value . "'", $values));
    }
}
