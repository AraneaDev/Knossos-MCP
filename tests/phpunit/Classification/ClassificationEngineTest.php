<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use InvalidArgumentException;
use Knossos\Classification\ClassificationEngine;
use Knossos\Classification\ClassificationFact;
use Knossos\Classification\ClassificationRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('classification-engine')]
final class ClassificationEngineTest extends TestCase
{
    // ----- helpers -----

    private static function evidence(string $path = 'src/A.php', int $start = 1, int $end = 1): Evidence
    {
        return new Evidence(relativePath: $path, startLine: $start, endLine: $end);
    }

    private static function node(string $localId, string $canonical, int $start = 1, int $end = 1): NodeFact
    {
        return new NodeFact(
            localId: $localId,
            kind: 'class',
            canonicalName: $canonical,
            displayName: $canonical,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: self::evidence('src/' . $canonical . '.php', $start, $end),
            attributes: [],
        );
    }

    private static function contribution(string $ownerKey, NodeFact ...$nodes): ScanContribution
    {
        return new ScanContribution(ownerKey: $ownerKey, nodes: $nodes);
    }

    /**
     * Build a ClassificationRule stub that simply returns the supplied facts
     * regardless of the node passed in. Anonymous class because PHP rejects
     * multi-class files inside a single namespace block.
     */
    private static function stubRule(string $id, ClassificationFact ...$facts): ClassificationRule
    {
        return new class ($id, $facts) implements ClassificationRule {
            /** @param list<ClassificationFact> $facts */
            public function __construct(private string $id, private array $facts)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function classify(NodeFact $node): array
            {
                return $this->facts;
            }
        };
    }

    // ----- construction -----

    public function testConstructorAcceptsEmptyRuleList(): void
    {
        $engine = new ClassificationEngine([]);

        assertSame([], $engine->classify([]));
    }

    public function testConstructorRejectsNonClassificationRuleEntries(): void
    {
        $notARule = new class () {
            public function id(): string
            {
                return 'fake';
            }
        };

        $error = captureThrows(
            static fn () => new ClassificationEngine([$notARule]),
            InvalidArgumentException::class,
        );

        assertSame('Classification rules must implement ClassificationRule.', $error->getMessage());
    }

    // ----- classify(): empty inputs -----

    public function testClassifyReturnsEmptyListForNoContributions(): void
    {
        $engine = new ClassificationEngine([
            self::stubRule('rule.empty', new ClassificationFact(
                'unused', 'role.nope', 'rule.empty',
                Origin::FrameworkConvention, Confidence::Probable,
                self::evidence('src/X.php', 1, 1), [],
            )),
        ]);

        assertSame([], $engine->classify([]));
    }

    public function testClassifyReturnsEmptyListWhenNoRuleEmitsFacts(): void
    {
        $engine = new ClassificationEngine([
            self::stubRule('rule.silent'),
            self::stubRule('rule.also-silent'),
        ]);

        assertSame([], $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]));
    }

    // ----- classify(): fan-out -----

    public function testClassifyEmitsOneFactPerRulePerNode(): void
    {
        $factForN1FromR1 = new ClassificationFact(
            'n1', 'http.controller', 'rule.r1',
            Origin::FrameworkConvention, Confidence::Probable,
            self::evidence('src/Foo.php', 1, 1), [],
        );
        $factForN1FromR2 = new ClassificationFact(
            'n1', 'event.listener', 'rule.r2',
            Origin::FrameworkConvention, Confidence::Probable,
            self::evidence('src/Foo.php', 1, 1), [],
        );

        $engine = new ClassificationEngine([
            self::stubRule('rule.r1', $factForN1FromR1),
            self::stubRule('rule.r2', $factForN1FromR2),
        ]);

        $facts = $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]);

        assertSame(2, count($facts));

        $byRule = [];
        foreach ($facts as $fact) {
            $byRule[$fact->ruleId] = $fact;
        }
        assertSame($factForN1FromR1, $byRule['rule.r1']);
        assertSame($factForN1FromR2, $byRule['rule.r2']);
    }

    public function testClassifyWalksEveryNodeInOrder(): void
    {
        $capturedOrder = [];

        $captureRule = new class ($capturedOrder) implements ClassificationRule {
            /** @param list<string> $capturedOrder */
            public function __construct(private array &$capturedOrder)
            {
            }

            public function id(): string
            {
                return 'rule.capture';
            }

            public function classify(NodeFact $node): array
            {
                $this->capturedOrder[] = $node->localId;

                return [new ClassificationFact(
                    $node->localId, 'seen', 'rule.capture',
                    Origin::Ast, Confidence::Certain,
                    new Evidence(relativePath: 'src/' . $node->localId . '.php', startLine: 1, endLine: 1),
                    [],
                )];
            }
        };

        $engine = new ClassificationEngine([$captureRule]);

        $facts = $engine->classify([
            self::contribution('owner-1', self::node('alpha', 'Alpha'), self::node('bravo', 'Bravo'), self::node('charlie', 'Charlie')),
        ]);

        assertSame(['alpha', 'bravo', 'charlie'], $capturedOrder);
        assertSame(['alpha', 'bravo', 'charlie'], array_map(static fn (ClassificationFact $f): string => $f->nodeReference, $facts));
    }

    public function testClassifyEmitsMultipleFactsForSameNodeFromSameRule(): void
    {
        $f1 = new ClassificationFact('n1', 'http.controller', 'rule.multi', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);
        $f2 = new ClassificationFact('n1', 'event.listener', 'rule.multi', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);

        $engine = new ClassificationEngine([self::stubRule('rule.multi', $f1, $f2)]);

        $facts = $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]);

        assertSame(2, count($facts));
        // Engine sorts dedup keys via ksort(SORT_STRING). Comparing
        // "n1\0http.controller\0rule.multi" vs "n1\0event.listener\0rule.multi",
        // 'e' (101) < 'h' (104), so event.listener sorts first.
        assertSame(['event.listener', 'http.controller'], array_map(static fn (ClassificationFact $f): string => $f->role, $facts));
    }

    // ----- dedup -----

    public function testClassifyDedupesFactsWithIdenticalNodeRoleAndRuleKey(): void
    {
        $f1 = new ClassificationFact('n1', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);
        $f2 = new ClassificationFact('n1', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);

        $engine = new ClassificationEngine([self::stubRule('rule.A', $f1, $f2)]);

        $facts = $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]);

        assertSame(1, count($facts), 'engine must dedupe identical facts by (nodeReference, role, ruleId)');
        // Dedup stores the last value for each key; assert on the tuple rather than object identity.
        assertSame('n1', $facts[0]->nodeReference);
        assertSame('http.controller', $facts[0]->role);
        assertSame('rule.A', $facts[0]->ruleId);
    }

    public function testClassifyKeepsFactsWhenRuleIdDiffers(): void
    {
        $f1 = new ClassificationFact('n1', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);
        $f2 = new ClassificationFact('n1', 'http.controller', 'rule.B', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);

        $engine = new ClassificationEngine([
            self::stubRule('rule.A', $f1),
            self::stubRule('rule.B', $f2),
        ]);

        $facts = $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]);

        assertSame(2, count($facts), 'facts with distinct rule IDs are not deduplicated');
    }

    public function testClassifyKeepsFactsWhenRoleDiffers(): void
    {
        $f1 = new ClassificationFact('n1', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);
        $f2 = new ClassificationFact('n1', 'event.listener', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);

        $engine = new ClassificationEngine([self::stubRule('rule.A', $f1, $f2)]);

        $facts = $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]);

        assertSame(2, count($facts), 'facts with distinct roles are not deduplicated');
    }

    public function testClassifyKeepsFactsWhenNodeReferenceDiffers(): void
    {
        $f1 = new ClassificationFact('n1', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);
        $f2 = new ClassificationFact('n2', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Bar.php', 1, 1), []);

        // Node-aware anonymous rule that returns only the fact whose nodeReference
        // matches the node currently being classified; this models a real rule that
        // emits exactly one fact per node without conflating ownership.
        $nodeAwareRule = new class ($f1, $f2) implements ClassificationRule {
            public function __construct(private ClassificationFact $f1, private ClassificationFact $f2)
            {
            }

            public function id(): string
            {
                return 'rule.A';
            }

            public function classify(NodeFact $node): array
            {
                return match ($node->localId) {
                    'n1' => [$this->f1],
                    'n2' => [$this->f2],
                    default => [],
                };
            }
        };

        $engine = new ClassificationEngine([$nodeAwareRule]);

        $facts = $engine->classify([
            self::contribution('owner-1', self::node('n1', 'Foo'), self::node('n2', 'Bar')),
        ]);

        assertSame(2, count($facts), 'facts for distinct nodes are not deduplicated');
        assertSame('n1', $facts[0]->nodeReference);
        assertSame('n2', $facts[1]->nodeReference);
    }

    // ----- provenance validation -----

    public function testClassifyRejectsFactWithMismatchedNodeReference(): void
    {
        $bad = new ClassificationFact('different-node', 'http.controller', 'rule.A', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Other.php', 1, 1), []);

        $engine = new ClassificationEngine([self::stubRule('rule.A', $bad)]);

        $error = captureThrows(
            static fn () => $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Rule rule.A emitted inconsistent provenance', $error->getMessage());
    }

    public function testClassifyRejectsFactWithMismatchedRuleId(): void
    {
        // Fact claims ruleId "rule.X" while the originating rule is "rule.A".
        $bad = new ClassificationFact('n1', 'http.controller', 'rule.X', Origin::FrameworkConvention, Confidence::Probable, self::evidence('src/Foo.php', 1, 1), []);

        $engine = new ClassificationEngine([self::stubRule('rule.A', $bad)]);

        $error = captureThrows(
            static fn () => $engine->classify([self::contribution('owner-1', self::node('n1', 'Foo'))]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('inconsistent provenance', $error->getMessage());
    }

    // ----- multi-contribution -----

    public function testClassifyIteratesMultipleContributionsInOrder(): void
    {
        $engine = new ClassificationEngine([
            self::stubRule('rule.r', new ClassificationFact(
                'pn1', 'role', 'rule.r',
                Origin::Ast, Confidence::Certain,
                self::evidence('src/A.php', 1, 1), [],
            )),
        ]);

        // Each contribution declares its own ownerKey; the engine doesn't care
        // about the owner key — it only walks the node lists.
        $facts = $engine->classify([
            self::contribution('owner-1', self::node('pn1', 'A', 1, 1)),
            self::contribution('owner-2', self::node('pn1', 'B', 1, 1)),
        ]);

        assertSame(1, count($facts), 'each node is classified once even across multiple contributions');
    }

    // ----- class shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ClassificationEngine::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}