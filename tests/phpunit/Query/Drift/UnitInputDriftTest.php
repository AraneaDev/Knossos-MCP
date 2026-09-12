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

            self::assertSame(1, $this->walkDrift($pdo, $projectId, $root)?->added, 'A manifest discovery would read is an input, whether or not any language claims it.');
            self::assertTrue(
                ScannedPaths::forProject($pdo, $projectId)->tracks('src/composer.json', $root . '/src/composer.json'),
                'Both oracles must give the same answer for the same path, or freshness depends on which one answered.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The distinction the whole record turns on: a scan that recorded nothing
     * about its manifests has not said they are unchanged, and reading its
     * silence as an empty set makes every manifest drop out of the comparison
     * while the probe reports `fresh`.
     *
     * Asserted through the probe rather than the oracle, because `unverified`
     * against `fresh` is the difference a caller actually sees, and it is the
     * whole point of declining.
     */
    #[Group('query')]
    public function testAScanThatRecordedNothingAboutItsManifestsIsNotReportedFresh(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $this->forgetUnitInputs($pdo, $projectId);

            $staleness = (new StalenessProbe($pdo))->probe($projectId);

            self::assertSame('unverified', $staleness['state'], 'Absence of evidence is not evidence of absence; the graph was never verified, so it must not be called fresh.');
            self::assertArrayNotHasKey('changed_files_since', $staleness, 'An oracle that declined has no counts to offer, and offering zero would be the same false claim in another shape.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The git oracle declines on the same silence, and for the same reason: a
     * manifest it holds no stored hash for cannot be decided, and its own
     * candidate listings never name one that has simply been deleted.
     */
    #[Group('query')]
    public function testTheWalkDeclinesRatherThanCountingUnrecordedManifestsAsUnchanged(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $this->forgetUnitInputs($pdo, $projectId);

            self::assertNull($this->walkDrift($pdo, $projectId, $root, expectAnswer: false), 'The walk cannot compare what the scan never recorded, and must say so.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A corrupt record is as unusable as an absent one, and must not be read as an empty set either. */
    #[Group('query')]
    public function testACorruptRecordDeclinesLikeAnAbsentOne(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $statement = $pdo->prepare('UPDATE scans SET unit_inputs_json = :units WHERE id = (SELECT active_scan_id FROM projects WHERE id = :id)');
            $statement->execute(['units' => '{"inputs":{"composer.json":"abc"}}', 'id' => $projectId]);

            self::assertSame('unverified', (new StalenessProbe($pdo))->probe($projectId)['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Drops the recorded manifest set, standing in for a graph built before the column existed. */
    private function forgetUnitInputs(PDO $pdo, string $projectId): void
    {
        $statement = $pdo->prepare('UPDATE scans SET unit_inputs_json = NULL WHERE id = (SELECT active_scan_id FROM projects WHERE id = :id)');
        $statement->execute(['id' => $projectId]);
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

    /**
     * Runs the walk oracle against the project's active scan, which is what
     * the probe does.
     *
     * `$expectAnswer` is false for the cases that are about the oracle
     * declining: asserting non-null inside the helper would turn a correct
     * decline into a helper failure rather than a reportable result.
     */
    private function walkDrift(PDO $pdo, string $projectId, string $root, bool $expectAnswer = true): ?\Knossos\Query\Drift\DriftCounts
    {
        $statement = $pdo->prepare('SELECT s.id, s.finished_at FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id');
        $statement->execute(['id' => $projectId]);
        $scan = $statement->fetch();
        $drift = (new WalkDriftOracle($pdo))->drift($projectId, (string) $scan['id'], $root, (string) $scan['finished_at']);
        if ($expectAnswer) {
            self::assertNotNull($drift);
        }

        return $drift;
    }
}
