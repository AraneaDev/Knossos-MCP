<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Discovery\UnitInputSet;
use Knossos\Query\Drift\ScannedPaths;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A manifest is an input to the scan, so editing one is drift.
 *
 * Discovery hashes composer.json, package.json, tsconfig.json and their
 * siblings into project units, and the planner reads them for framework
 * detection, analyzer configuration hashes and entry-point classification.
 * None of them becomes a `files` row, though, and both oracles used to inspect
 * only `files` — so adding a framework to composer.json changed what a rescan
 * would produce while the graph went on reporting itself fresh.
 */
final class UnitInputDriftTest extends KnossosTestCase
{
    /**
     * End to end, through a real scan: editing a manifest the scan read must
     * move the verdict off `fresh`. This is the defect itself, stated in the
     * only terms a caller ever sees.
     */
    #[Group('query')]
    public function testEditingAManifestMakesTheGraphStale(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            self::assertSame('fresh', (new StalenessProbe($pdo))->probe($projectId)['state'], 'The scan just ran, so nothing has drifted yet.');

            // A dependency the scan never saw, which is exactly the edit that
            // changes framework enrichment and the analyzer configuration hash.
            file_put_contents($root . '/composer.json', (string) json_encode([
                'name' => 'fixture/mixed',
                'require' => ['laravel/framework' => '^11.0'],
            ], JSON_PRETTY_PRINT));

            $staleness = (new StalenessProbe($pdo))->probe($projectId);

            self::assertSame('stale', $staleness['state'], 'A manifest edit changes what a rescan would produce, so the graph is no longer current.');
            self::assertSame(1, $staleness['changed_files_since'], 'Exactly the manifest drifted, decided by the hash the scan stored for it.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The scan has to record the hashes in the first place, or there is nothing to compare a later edit against. */
    #[Group('query')]
    public function testAScanRecordsTheManifestsItRead(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $statement = $pdo->prepare('SELECT s.unit_inputs_json FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id');
            $statement->execute(['id' => $projectId]);
            $recorded = UnitInputSet::decode((string) $statement->fetchColumn());

            self::assertNotNull($recorded);
            self::assertArrayHasKey('composer.json', $recorded->inputs);
            self::assertArrayHasKey('frontend/tsconfig.json', $recorded->inputs);
            self::assertSame(
                hash('sha256', (string) file_get_contents($root . '/composer.json')),
                $recorded->inputs['composer.json'],
                'The recorded hash must be the content the scan read, or a later comparison decides nothing.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A manifest the graph holds a hash for that is gone from disk is a deletion like any other input. */
    #[Group('query')]
    public function testADeletedManifestIsADeletion(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            unlink($root . '/frontend/tsconfig.json');

            $staleness = (new StalenessProbe($pdo))->probe($projectId);

            self::assertSame('stale', $staleness['state']);
            self::assertSame(1, $staleness['deleted_files_since']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The other half of the same notion: a manifest appearing where there was
     * none is an addition. Without it the two halves disagree — an edited
     * manifest is drift while a brand new one is nothing — and the graph's
     * freshness depends on which kind of change happened to arrive.
     */
    #[Group('query')]
    public function testAManifestIsAPathTheScannerTracks(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            file_put_contents($root . '/src/composer.json', "{}\n");
            touch($root . '/src', time() + 60);

            self::assertSame(1, $this->walkDrift($pdo, $projectId, $root)->added, 'A manifest discovery would read is an input, whether or not any language claims it.');
            self::assertTrue(
                ScannedPaths::forProject($pdo, $projectId)->tracks('src/composer.json', $root . '/src/composer.json'),
                'Both oracles must give the same answer for the same path, or freshness depends on which one answered.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Every shape that is not a trustworthy record decodes to nothing, so no caller has to guess which of them it is holding. */
    #[Group('query')]
    public function testAnUntrustworthyRecordDecodesToNothing(): void
    {
        self::assertNull(UnitInputSet::decode(null), 'A scan predating the column recorded nothing.');
        self::assertNull(UnitInputSet::decode(''), 'An empty column is not an empty set.');
        self::assertNull(UnitInputSet::decode('not json'), 'A value that no longer parses says nothing about the manifests.');
        self::assertNull(UnitInputSet::decode('{"inputs":{"composer.json":"abc"}}'), 'Completeness is never assumed when it was not recorded.');
        self::assertNull(UnitInputSet::decode('{"inputs":{"composer.json":17},"complete":true}'), 'A hash that is not a string is a corrupt record, not a hash.');
        self::assertSame([], UnitInputSet::of([])->inputs, 'A project with no manifests records an empty set, which is an answer.');
    }

    /** A set past the bound is marked incomplete rather than trimmed into one a reader would act on. */
    #[Group('query')]
    public function testASetPastTheBoundIsMarkedIncomplete(): void
    {
        $units = [];
        for ($index = 0; $index <= UnitInputSet::MAX_INPUTS; ++$index) {
            $units[] = new \Knossos\Discovery\ProjectUnit('node', sprintf('packages/p%06d/package.json', $index), hash('sha256', (string) $index));
        }

        $set = UnitInputSet::of($units);

        self::assertFalse($set->complete);
        self::assertCount(UnitInputSet::MAX_INPUTS, $set->inputs);
        self::assertNull(UnitInputSet::decode($set->encode()));
    }

    /** Runs the walk oracle against the project's active scan, which is what the probe does. */
    private function walkDrift(PDO $pdo, string $projectId, string $root): \Knossos\Query\Drift\DriftCounts
    {
        $statement = $pdo->prepare('SELECT s.id, s.finished_at FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id');
        $statement->execute(['id' => $projectId]);
        $scan = $statement->fetch();
        $drift = (new WalkDriftOracle($pdo))->drift($projectId, (string) $scan['id'], $root, (string) $scan['finished_at']);
        self::assertNotNull($drift);

        return $drift;
    }
}
