<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A module-level `const f = () => {}` nothing calls is dead code like an
 * unused `function f() {}`, and one something calls is not.
 */
#[Group('query')]
final class FunctionBindingCandidatesTest extends KnossosTestCase
{
    public function testAnUnusedFunctionBindingIsACandidateAndAUsedOneIsNot(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/function-bindings';
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full')->projectId;

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        self::assertContains('src/bindings.ts#unusedArrow', $names);
        self::assertNotContains('src/bindings.ts#helper', $names);
        self::assertNotContains('src/bindings.ts#exportedArrow', $names);
    }
}
