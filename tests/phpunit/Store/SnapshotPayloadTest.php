<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SnapshotPayload;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot is a full copy of the graph, and this repository's own is 30 MB of
 * JSON. Five retained snapshots per project made the database an order of
 * magnitude larger than the graph it serves, so the archived copy is stored
 * compressed.
 */
#[Group('storage')]
final class SnapshotPayloadTest extends TestCase
{
    public function testEncodedPayloadDecodesBackToTheOriginalJson(): void
    {
        $json = json_encode(['schema' => 1, 'facts' => ['nodes' => array_fill(0, 200, ['id' => 'symbol_x', 'kind' => 'class'])]], JSON_THROW_ON_ERROR);

        assertSame($json, SnapshotPayload::decode(SnapshotPayload::encode($json)));
    }

    public function testEncodingSubstantiallyShrinksARepetitivePayload(): void
    {
        $json = json_encode(['schema' => 1, 'facts' => ['nodes' => array_fill(0, 2000, ['id' => 'symbol_x', 'kind' => 'class'])]], JSON_THROW_ON_ERROR);

        $this->assertLessThan(strlen($json) / 4, strlen(SnapshotPayload::encode($json)));
    }

    public function testTheEncodedFormIsAsciiSoItSurvivesATextColumn(): void
    {
        $encoded = SnapshotPayload::encode(json_encode(['schema' => 1, 'facts' => ['nodes' => []]], JSON_THROW_ON_ERROR));

        assertSame(1, preg_match('/^[\x20-\x7e]*$/', $encoded));
    }

    public function testAnUncompressedPayloadWrittenByAnEarlierVersionStillDecodes(): void
    {
        // Snapshots archived before compression are plain JSON in the same
        // column; they have to keep answering, not be silently unreadable.
        $json = '{"schema":1,"facts":{"nodes":[]}}';

        assertSame($json, SnapshotPayload::decode($json));
    }

    public function testATruncatedCompressedPayloadIsRejectedRatherThanReturnedAsGarbage(): void
    {
        $encoded = SnapshotPayload::encode(json_encode(['schema' => 1, 'facts' => []], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        SnapshotPayload::decode(substr($encoded, 0, (int) (strlen($encoded) / 2)));
    }
    public function testAPayloadThatOutgrowsItsCeilingIsAbandonedRatherThanCompressed(): void
    {
        // The ceiling exists so one enormous project cannot write an unbounded
        // row. Past it the payload is discarded, so continuing to compress would
        // spend exactly the effort the ceiling is there to avoid.
        $writer = SnapshotPayload::writer(64);

        $writer->write(str_repeat('a', 32));
        assertSame(false, $writer->exceeded());
        assertSame(32, $writer->byteSize());

        $writer->write(str_repeat('b', 64));
        assertSame(true, $writer->exceeded());

        // Further writes are ignored, and the size stops at what was accepted.
        $writer->write(str_repeat('c', 1024));
        assertSame(true, $writer->exceeded());
        assertSame(96, $writer->byteSize());
    }

    public function testAWriterUnderItsCeilingProducesADecodablePayload(): void
    {
        $writer = SnapshotPayload::writer(1024);
        $writer->write('{"schema":1,');
        $writer->write('"facts":{}}');

        assertSame('{"schema":1,"facts":{}}', SnapshotPayload::decode($writer->finish()));
    }

}
