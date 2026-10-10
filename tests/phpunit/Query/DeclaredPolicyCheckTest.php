<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Without policies of its own, a check reads the ones the project declares. */
#[Group('query')]
final class DeclaredPolicyCheckTest extends KnossosTestCase
{
    public function testTheDeclaredPoliciesAreCheckedAndNoneDeclaredIsRefused(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $this->assertRefused($queries, $projectId, 'declares no policies');
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'boundaries' => [
                ['name' => 'Core', 'path_prefix' => 'src/Core/'],
                ['name' => 'Edge', 'path_prefix' => 'src/Edge/'],
            ], 'policies' => [['id' => 'core-alone', 'from_boundary' => 'Core', 'deny_targets' => ['Edge']]]]));
            self::assertSame(['core-alone'], array_column($queries->checkArchitecture($projectId, null)->data['policies_evaluated'], 'id'));
            // The file is named as it is spelled.
            unlink($root . '/knossos.json');
            file_put_contents($root . '/knossos.jsonc', "// rules\n{\"version\": 1}");
            $this->assertRefused($queries, $projectId, 'knossos.jsonc declares no policies');
            unlink($root . '/knossos.jsonc');
            // A broken file is said to be broken, never read as declaring nothing.
            file_put_contents($root . '/knossos.json', '{ not json');
            $this->assertRefused($queries, $projectId, 'could not be read');
        } finally {
            $this->removeTempTree($root);
        }
    }

    private function assertRefused(ArchitectureQueryService $queries, string $projectId, string $reason): void
    {
        try {
            $queries->checkArchitecture($projectId, null);
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($reason, $error->getMessage());
            self::assertStringContainsString('knossos.json', $error->getMessage());
            return;
        }
        self::fail('The check ran without policies to check.');
    }
}
