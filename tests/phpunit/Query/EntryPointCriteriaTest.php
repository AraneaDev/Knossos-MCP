<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\EntryPointCriteria;
use Knossos\Query\ReportableComponent;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Impact analysis judged entry points by a list of its own, which drifted from
 * the one the briefs share: it counted queued jobs, missed declared endpoints
 * and admitted test code. The PHP predicate exists so that a caller holding a
 * node and its roles asks the same question the SQL predicate answers.
 */
final class EntryPointCriteriaTest extends KnossosTestCase
{
    #[Group('query')]
    public function testThePhpPredicateAgreesWithTheSqlPredicate(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $nodes = [
            'R' => ['route', []],
            'E' => ['endpoint', []],
            'C' => ['class', ['laravel.job']],
            'K' => ['class', ['laravel.controller']],
            'Q' => ['command', [ReportableComponent::TEST_ROLE]],
            'P' => ['class', []],
        ];
        foreach ($nodes as $name => [$kind, $roles]) {
            self::component($repository, $ids, $name, $kind, $roles);
        }
        $repository->completeScan($project, $ids['scan']);

        $sql = $pdo->prepare('SELECT n.canonical_name FROM nodes n WHERE n.project_id = :project AND ' . EntryPointCriteria::sqlCondition() . ' ORDER BY n.canonical_name');
        $sql->execute(['project' => $project]);
        $fromSql = $sql->fetchAll(PDO::FETCH_COLUMN);
        $fromPhp = [];
        foreach ($nodes as $name => [$kind, $roles]) {
            if (EntryPointCriteria::matches($kind, $roles)) {
                $fromPhp[] = 'App\\' . $name;
            }
        }
        sort($fromPhp);

        self::assertSame(['App\\E', 'App\\K', 'App\\R'], $fromSql);
        self::assertSame($fromSql, $fromPhp);
    }

    /** Test code is never a way in, whichever half would otherwise admit it. */
    #[Group('query')]
    public function testTestCodeIsExcludedWhetherAdmittedByKindOrByRole(): void
    {
        self::assertTrue(EntryPointCriteria::matches('route', []));
        self::assertTrue(EntryPointCriteria::matches('class', ['laravel.job', 'application.controller']));
        self::assertFalse(EntryPointCriteria::matches('route', [ReportableComponent::TEST_ROLE]));
        self::assertFalse(EntryPointCriteria::matches('class', ['application.controller', ReportableComponent::TEST_ROLE]));
        self::assertFalse(EntryPointCriteria::matches('class', ['laravel.job']));
    }

    /**
     * Save one PHP component named `App\<name>` with the given kind and roles.
     *
     * @param array<string, string> $ids @param list<string> $roles
     */
    private static function component(GraphRepository $repository, array $ids, string $name, string $kind, array $roles): void
    {
        $project = $ids['project'];
        $id = StableId::symbol($project, 'php', $kind, 'App\\' . $name);
        $repository->saveNode($id, $project, 'php', $kind, 'App\\' . $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Entry.php', $ids['scan']);
        foreach ($roles as $role) {
            $repository->saveClassification(StableId::classification($project, $id, $role, 'test.roles'), $project, $id, $role, 'user_rule', 'certain', 'test.roles', $ids['file'], 1, 1, [], $ids['scan']);
        }
    }
}
