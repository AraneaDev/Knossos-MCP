<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class ContributionDecoderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function minimalNodeData(): array
    {
        return [
            'local_id' => 'php:class:App\\Foo',
            'kind' => 'class',
            'canonical_name' => 'App\\Foo',
            'display_name' => 'App\\Foo',
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => [
                'path' => 'src/Foo.php',
                'start_line' => 1,
                'end_line' => 5,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function minimalEdgeData(): array
    {
        return [
            'kind' => 'depends_on',
            'source' => 'srcRef',
            'target' => 'tgtRef',
            'origin' => 'derived',
            'confidence' => 'probable',
            'evidence' => [
                'path' => 'src/Foo.php',
                'start_line' => 1,
                'end_line' => 5,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function minimalDiagnosticData(): array
    {
        return [
            'severity' => 'warning',
            'code' => 'X',
            'message' => 'msg',
        ];
    }

    /** @return array<string, mixed> */
    private static function minimalContributionData(): array
    {
        return [
            'owner_key' => 'test.knossos:file:src/Foo.php',
            'nodes' => [self::minimalNodeData()],
            'edges' => [self::minimalEdgeData()],
            'diagnostics' => [self::minimalDiagnosticData()],
        ];
    }

    // ----- happy path -----

    public function testDecodeCreatesContributionWithAllFields(): void
    {
        $c = ContributionDecoder::decode(self::minimalContributionData());

        $this->assertInstanceOf(ScanContribution::class, $c);
        assertSame('test.knossos:file:src/Foo.php', $c->ownerKey);
        $this->assertCount(1, $c->nodes);
        $this->assertCount(1, $c->edges);
        $this->assertCount(1, $c->diagnostics);
    }

    public function testDecodePopulatesNodeFields(): void
    {
        $c = ContributionDecoder::decode(self::minimalContributionData());
        $node = $c->nodes[0];

        $this->assertInstanceOf(NodeFact::class, $node);

        assertSame('php:class:App\\Foo', $node->localId);
        assertSame('class', $node->kind);
        assertSame('App\\Foo', $node->canonicalName);
        assertSame('App\\Foo', $node->displayName);
        assertSame(Origin::Ast, $node->origin);
        assertSame(Confidence::Certain, $node->confidence);
        $this->assertInstanceOf(Evidence::class, $node->evidence);
        assertSame('src/Foo.php', $node->evidence->relativePath);
        assertSame(1, $node->evidence->startLine);
        assertSame(5, $node->evidence->endLine);
    }

    public function testDecodePopulatesEdgeFields(): void
    {
        $c = ContributionDecoder::decode(self::minimalContributionData());
        $edge = $c->edges[0];

        $this->assertInstanceOf(EdgeFact::class, $edge);

        assertSame('depends_on', $edge->kind);
        assertSame('srcRef', $edge->sourceReference);
        assertSame('tgtRef', $edge->targetReference);
        assertSame(Origin::Derived, $edge->origin);
        assertSame(Confidence::Probable, $edge->confidence);
        assertSame('src/Foo.php', $edge->evidence->relativePath);
    }

    public function testDecodePopulatesDiagnosticFields(): void
    {
        $c = ContributionDecoder::decode(self::minimalContributionData());
        $d = $c->diagnostics[0];

        $this->assertInstanceOf(Diagnostic::class, $d);

        assertSame('warning', $d->severity);
        assertSame('X', $d->code);
        assertSame('msg', $d->message);
        assertSame(null, $d->evidence);
    }

    public function testDecodeHandlesDiagnosticWithEvidence(): void
    {
        $data = self::minimalContributionData();
        $data['diagnostics'] = [[
            'severity' => 'error',
            'code' => 'ERR',
            'message' => 'error msg',
            'evidence' => ['path' => 'src/E.php', 'start_line' => 10, 'end_line' => 20],
        ]];
        $c = ContributionDecoder::decode($data);
        $d = $c->diagnostics[0];

        assertSame('error', $d->severity);
        assertSame('ERR', $d->code);
        assertSame('error msg', $d->message);
        $this->assertNotNull($d->evidence);
        assertSame('src/E.php', $d->evidence->relativePath);
        assertSame(10, $d->evidence->startLine);
        assertSame(20, $d->evidence->endLine);
    }

    public function testDecodeHandlesEmptyCollections(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'] = [];
        $data['edges'] = [];
        $data['diagnostics'] = [];
        $c = ContributionDecoder::decode($data);

        assertSame([], $c->nodes);
        assertSame([], $c->edges);
        assertSame([], $c->diagnostics);
    }

    // ----- owner_key validation -----

    public function testDecodeRejectsMissingOwnerKey(): void
    {
        $data = self::minimalContributionData();
        unset($data['owner_key']);

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- nodes validation -----

    public function testDecodeRejectsNodesNotAList(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'] = 'not-an-array';

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsNodeNotAnObject(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'] = ['just-a-string'];

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsNodeMissingLocalId(): void
    {
        $data = self::minimalContributionData();
        unset($data['nodes'][0]['local_id']);

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsNodeMissingKind(): void
    {
        $data = self::minimalContributionData();
        unset($data['nodes'][0]['kind']);

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsNodeEvidenceNotAnObject(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'][0]['evidence'] = 'not-an-object';

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- edges validation -----

    public function testDecodeRejectsEdgesNotAList(): void
    {
        $data = self::minimalContributionData();
        $data['edges'] = 'bad';

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsEdgeNotAnObject(): void
    {
        $data = self::minimalContributionData();
        $data['edges'] = [42];

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- diagnostics validation -----

    public function testDecodeRejectsDiagnosticsNotAList(): void
    {
        $data = self::minimalContributionData();
        $data['diagnostics'] = null;

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    public function testDecodeRejectsDiagnosticNotAnObject(): void
    {
        $data = self::minimalContributionData();
        $data['diagnostics'] = [false];

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- attributes validation -----

    public function testDecodeAcceptsAttributesObject(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'][0]['attributes'] = ['extra' => true];
        $data['edges'][0]['attributes'] = ['extra' => false];

        $c = ContributionDecoder::decode($data);

        assertSame(['extra' => true], $c->nodes[0]->attributes);
        assertSame(['extra' => false], $c->edges[0]->attributes);
    }

    public function testDecodeRejectsAttributesThatAreAList(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'][0]['attributes'] = [1, 2, 3];

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- evidence validation -----

    public function testDecodeRejectsEvidenceWithNonIntLines(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'][0]['evidence']['start_line'] = 'one';

        assertThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );
    }

    // ----- inner Throwable (e.g. invalid backed-enum case) wrapped as WORKER_CONTRIBUTION_INVALID -----

    public function testDecodeWrapsInvalidOriginEnumValueAsContributionInvalid(): void
    {
        $data = self::minimalContributionData();
        $data['nodes'][0]['origin'] = 'not-a-real-origin';

        $error = captureThrows(
            static fn() => ContributionDecoder::decode($data),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        $this->assertInstanceOf(\ValueError::class, $error->getPrevious());
    }
}
