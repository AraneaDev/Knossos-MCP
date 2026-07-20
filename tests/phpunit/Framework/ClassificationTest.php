<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Classification\ClassificationEngine;
use Knossos\Classification\ClassificationFact;
use Knossos\Classification\ExplicitRoleRule;
use Knossos\Classification\NameSuffixRule;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ClassificationTest extends KnossosTestCase
{
    #[Group('classification')]
    public function testClassificationRulesAreDeterministicMultiRoleAndKindSafe(): void
    {
        $evidence = new Evidence('src/CheckoutService.php', 7, 9);
        $class = new NodeFact(
            'php:class:Fixture\\CheckoutService',
            'class',
            'Fixture\\CheckoutService',
            'CheckoutService',
            Origin::Ast,
            Confidence::Certain,
            $evidence,
        );
        $method = new NodeFact(
            'php:method:Fixture\\CheckoutService::run',
            'method',
            'Fixture\\CheckoutService::run',
            'CheckoutService',
            Origin::Ast,
            Confidence::Certain,
            $evidence,
        );
        $contribution = new ScanContribution('knossos.php:file:src/CheckoutService.php', [$class, $method]);
        $engine = new ClassificationEngine([
            new NameSuffixRule('test.naming.v1', ['Service' => 'application.service']),
            new ExplicitRoleRule('test.explicit.v1', ['Fixture\\CheckoutService' => ['domain.checkout', 'application.entry_point']]),
        ]);
        $first = $engine->classify([$contribution]);
        $second = $engine->classify([$contribution]);
        assertSame(3, count($first));
        assertSame(serialize($first), serialize($second));
        assertSame([], array_values(array_filter(
            $first,
            fn($fact): bool => $fact->nodeReference === $method->localId,
        )));
        $confidenceByRole = [];
        foreach ($first as $fact) {
            $confidenceByRole[$fact->role] = $fact->confidence->value;
        }
        assertSame('probable', $confidenceByRole['application.service']);
        assertSame('certain', $confidenceByRole['domain.checkout']);
    }

    #[Group('classification')]
    public function testClassificationsReconcileAtomicallyWithEvidenceAndProvenance(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $engine = new ClassificationEngine([
            new NameSuffixRule('test.naming.v1', ['Service' => 'application.service']),
            new ExplicitRoleRule('test.explicit.v1', ['Fixture\\CheckoutService' => ['domain.checkout', 'application.entry_point']]),
        ]);
        $facts = $engine->classify($request->contributions);
        $classified = new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            $request->scanners,
            $request->contributions,
            [],
            $facts,
        );
        $active = $reconciler->reconcile($classified);
        assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM classifications')->fetchColumn());
        $row = $pdo->query("SELECT role, origin, confidence, rule_id, start_line FROM classifications WHERE role = 'domain.checkout'")->fetch();
        assertSame('user_rule', $row['origin']);
        assertSame('certain', $row['confidence']);
        assertSame('test.explicit.v1', $row['rule_id']);
        assertSame(7, $row['start_line']);

        $bad = new ClassificationFact(
            'php:class:Fixture\\Missing',
            'application.service',
            'test.invalid.v1',
            Origin::Derived,
            Confidence::Possible,
            new Evidence('src/CheckoutService.php', 1, 1),
        );
        $badRequest = new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            $request->scanners,
            $request->contributions,
            [],
            [...$facts, $bad],
        );
        assertThrows(fn() => $reconciler->reconcile($badRequest), ReconciliationException::class);
        assertSame($active->scanId, (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn());
        assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM classifications')->fetchColumn());
    }
}
