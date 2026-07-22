<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two scanners referencing an external symbol with the same canonical name
 * (PHP's \Error and JavaScript's Error) must produce two distinct nodes.
 * StableId scopes node identity by language, so the schema must too —
 * regression test for the self-scan failure on UNIQUE(project_id, kind,
 * canonical_name).
 */
#[Group('reconciliation')]
final class CrossLanguageExternalNodeTest extends KnossosTestCase
{
    public function testSameCanonicalExternalReferenceFromTwoLanguagesReconciles(): void
    {
        $phpEvidence = new Evidence('src/Svc.php', 3, 5);
        $tsEvidence = new Evidence('frontend/app.ts', 1, 2);

        $php = new ScanContribution(
            'knossos.php:file:src/Svc.php',
            [new NodeFact('php:class:App\\Svc', 'class', 'App\\Svc', 'Svc', Origin::Ast, Confidence::Certain, $phpEvidence)],
            [new EdgeFact('references', 'php:class:App\\Svc', 'php:class:Error', Origin::Ast, Confidence::Certain, $phpEvidence)],
            [],
        );
        $typescript = new ScanContribution(
            'knossos.typescript:file:frontend/app.ts',
            [new NodeFact('ts:class:frontend/app.ts#App', 'class', 'frontend/app.ts#App', 'App', Origin::Ast, Confidence::Certain, $tsEvidence)],
            [new EdgeFact('references', 'ts:class:frontend/app.ts#App', 'ts:class:Error', Origin::Ast, Confidence::Certain, $tsEvidence)],
            [],
        );

        $discovery = new DiscoveryResult(
            '/workspace/cross-language',
            [
                new DiscoveredFile('src/Svc.php', '/workspace/cross-language/src/Svc.php', 'php', 40, 1_000, hash('sha256', 'php src')),
                new DiscoveredFile('frontend/app.ts', '/workspace/cross-language/frontend/app.ts', 'typescript', 30, 1_000, hash('sha256', 'ts src')),
            ],
            [],
            [],
            hash('sha256', 'inputs'),
            hash('sha256', 'config'),
        );
        $scanners = [
            new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], []),
            new ScannerManifest('knossos.typescript', '0.1.0', '1.0', '1.0', ['typescript'], ['ts'], []),
        ];

        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $reconciler = new GraphReconciler(new SqliteGraphRepository($pdo));
        $request = new FullScanRequest('cross-language', 'Cross Language', $discovery, $scanners, [$php, $typescript]);

        $result = $reconciler->reconcile($request);

        // 2 declared + 2 external nodes; both externals keep the bare name.
        assertSame(4, $result->nodes);
        $statement = $pdo->prepare(
            "SELECT language FROM nodes WHERE project_id = :project AND kind = 'external_class' AND canonical_name = 'Error' ORDER BY language",
        );
        $statement->execute(['project' => $result->projectId]);
        assertSame(['php', 'ts'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
