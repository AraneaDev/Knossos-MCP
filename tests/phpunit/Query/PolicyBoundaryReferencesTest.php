<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitecturePolicyQueryService;
use Knossos\Query\FileContextQueryService;
use Knossos\Query\GraphSummaryQuery;
use Knossos\Query\LocationSuggestionService;
use Knossos\Query\SessionBriefService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Policies keep naming the boundary they were written for after a manifest
 * renames it, and every reader of a policy resolves its references the same way.
 */
final class PolicyBoundaryReferencesTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-policy-refs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/a.ts', "export const a = 1;\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * Adding a composer.json beside package.json renamed the merged boundary to
     * "composer:acme/lib (+node:web)", and a policy naming "node:web" failed with
     * "Unknown policy boundary", failing check_architecture and quality_gate.
     */
    #[Group('query')]
    public function testAPolicyNamingAMergedBoundaryStillResolves(): void
    {
        file_put_contents($this->root . '/package.json', '{"name":"web"}');
        $pdo = $this->freshTestDatabase();
        $scans = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $projectId = $scans->scan($this->root)->projectId;
        $policy = static fn(string $from): array => [['id' => 'web-stays-assigned', 'from_boundary' => $from, 'deny_targets' => ['@unassigned']]];
        $check = static fn(string $from): string => (new ArchitecturePolicyQueryService($pdo))->checkArchitecture($projectId, $policy($from))->summary;
        $oldId = StableId::boundary($projectId, 'node:web', 'inferred');
        $check('node:web');
        $check($oldId);

        file_put_contents($this->root . '/composer.json', '{"name":"acme/lib"}');
        $scans->scan($this->root);

        $names = $pdo->query('SELECT name FROM boundaries ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['composer:acme/lib (+node:web)', 'module:src'], $names);
        self::assertIsString($check('node:web'));
        self::assertIsString($check($oldId));
        self::assertIsString($check('composer:acme/lib'));
        // Targets resolve the same way: an allow list naming a former name is
        // compiled, one naming nothing is refused.
        $allow = static fn(string $target): string => (new ArchitecturePolicyQueryService($pdo))->checkArchitecture(
            $projectId,
            [['id' => 'web-allows', 'from_boundary' => 'node:web', 'allow_targets' => [$target]]],
        )->summary;
        self::assertIsString($allow('node:web'));
        $error = captureThrows(static fn() => $allow('Nowhere'), InvalidArgumentException::class);
        assertSame('Unknown policy boundary: Nowhere', $error->getMessage());
    }

    #[Group('query')]
    public function testAnAliasTwoBoundariesShareIsAmbiguousAndNamesTheCandidates(): void
    {
        foreach (['a', 'b'] as $directory) {
            mkdir($this->root . '/' . $directory);
            file_put_contents($this->root . '/' . $directory . '/package.json', '{"name":"loc"}');
        }
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;
        $ids = $pdo->query("SELECT id FROM boundaries WHERE name LIKE 'node:loc (%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $ids);

        $error = captureThrows(
            static fn() => (new ArchitecturePolicyQueryService($pdo))->checkArchitecture($projectId, [['id' => 'loc', 'from_boundary' => 'node:loc', 'deny_targets' => ['@unassigned']]]),
            InvalidArgumentException::class,
        );

        assertSame(sprintf('Ambiguous policy boundary name; use its stable ID: node:loc (candidates: %s, %s)', ...$ids), $error->getMessage());
    }

    /** file_context compared the policy's reference with the file's boundary name, so a policy written with a stable id was never listed. */
    #[Group('query')]
    public function testFileContextFindsAPolicyWrittenWithAStableId(): void
    {
        $config = static fn(string $from): string => (string) json_encode([
            'version' => 1,
            'boundaries' => [['name' => 'core', 'path_prefix' => 'src']],
            'policies' => [
                ['id' => 'elsewhere', 'from_boundary' => 'Nowhere', 'deny_targets' => ['@unassigned']],
                ['id' => 'core-alone', 'from_boundary' => $from, 'deny_targets' => ['@unassigned']],
            ],
        ]);
        file_put_contents($this->root . '/knossos.json', $config('core'));
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;
        file_put_contents($this->root . '/knossos.json', $config(StableId::boundary($projectId, 'core', 'explicit')));

        $context = (new FileContextQueryService($pdo))->fileContext($projectId, 'src/a.ts');

        assertSame([0], array_keys($context->data['policies']));
        assertSame('core-alone', $context->data['policies'][0]['id']);
    }

    /** list_boundaries shows the former names beside the matcher, never inside it. */
    #[Group('query')]
    public function testListBoundariesShowsTheAliasesBesideTheMatcher(): void
    {
        file_put_contents($this->root . '/package.json', '{"name":"web"}');
        file_put_contents($this->root . '/composer.json', '{"name":"acme/lib"}');
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;

        $boundaries = (new GraphSummaryQuery($pdo))->listBoundaries($projectId)->data['boundaries'];
        $boundary = array_values(array_filter($boundaries, static fn(array $row): bool => $row['name'] === 'composer:acme/lib (+node:web)'))[0];
        assertSame([], array_values(array_filter($boundaries, static fn(array $row): bool => $row['name'] === 'module:src'))[0]['aliases']);

        assertSame(['type' => 'path_prefix', 'value' => ''], $boundary['matcher']);
        assertSame(['composer:acme/lib', 'node:web'], $boundary['aliases']);

        // suggest_location shows the same matcher, the former names left out.
        $matchers = array_column(array_column((new LocationSuggestionService($pdo, null))->suggestLocation($projectId, 'src a')->data['candidates'], 'boundary'), 'matcher', 'name');
        assertSame(['type' => 'path_prefix', 'value' => ''], $matchers['composer:acme/lib (+node:web)'] ?? null);
    }

    /** The session brief showed a reference as written, so a policy written with a stable id read as a hash. */
    #[Group('query')]
    public function testTheSessionBriefNamesTheBoundaryAReferenceResolvesTo(): void
    {
        $config = static fn(string $from): string => (string) json_encode([
            'version' => 1,
            'boundaries' => [['name' => 'core', 'path_prefix' => 'src']],
            'policies' => [
                ['id' => 'core-alone', 'from_boundary' => $from, 'deny_targets' => ['@unassigned', 'gone', $from]],
                ['id' => 'core-only', 'from_boundary' => $from, 'allow_targets' => [$from]],
            ],
        ]);
        file_put_contents($this->root . '/knossos.json', $config('core'));
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;
        file_put_contents($this->root . '/knossos.json', $config(StableId::boundary($projectId, 'core', 'explicit')));

        assertSame(['core -x-> @unassigned, gone, core', 'core --> only core'], (new SessionBriefService($pdo))->gather($this->root)->rules);
    }
}
