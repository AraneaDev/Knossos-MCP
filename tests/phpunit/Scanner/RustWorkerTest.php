<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;

/**
 * Drives the real Rust worker over the real protocol.
 *
 * Every test skips when the binary is absent. Rust is an optional language, so a
 * contributor without cargo must still get a green suite; the quality container
 * always has the binary, which is where the skip can never fire.
 */
final class RustWorkerTest extends KnossosTestCase
{
    use WorkerClients;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    public function testTheManifestIdentifiesTheRustWorker(): void
    {
        $client = $this->rustWorkerClient();
        try {
            $manifest = $client->initialize();
        } finally {
            $client->shutdown();
        }

        self::assertSame('knossos.rust', $manifest->id);
        self::assertSame('1.0', $manifest->protocolVersion);
        self::assertSame(['rust'], $manifest->languages);
        self::assertContains('content_hash', $manifest->capabilities);
    }

    public function testScanningARustFileProducesDecodableFacts(): void
    {
        $root = sys_get_temp_dir() . '/knossos-rust-e2e-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/src/lib.rs', "pub struct Engine;\n\nimpl Engine {\n    pub fn start(&self) {}\n}\n");
        $client = $this->rustWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/lib.rs']]));
        } finally {
            $client->shutdown();
            unlink($root . '/src/lib.rs');
            rmdir($root . '/src');
            rmdir($root);
        }

        self::assertCount(1, $contributions);
        $contribution = $contributions[0];
        self::assertSame('knossos.rust:file:src/lib.rs', $contribution->ownerKey);
        $kinds = array_map(static fn(NodeFact $node): string => $node->kind, $contribution->nodes);
        self::assertContains('module', $kinds);
        self::assertContains('class', $kinds);
        self::assertContains('method', $kinds);
    }

    public function testRustWorkerReportsTheHashOfTheRawBytesItParsed(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'src/bom.rs' => "\xEF\xBB\xBFpub fn bom() {}\n",
            'src/crlf.rs' => "pub fn crlf() {}\r\n",
            'src/broken.rs' => "pub fn {\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        try {
            $client = $this->rustWorkerClient();
            self::assertTrue(in_array('content_hash', $client->initialize()->capabilities, true));
            $byOwner = [];
            foreach ($client->scan(['root' => $root, 'files' => array_keys($files)]) as $contribution) {
                $byOwner[$contribution->ownerKey] = $contribution->contentHash;
            }
            $client->shutdown();

            foreach ($files as $relative => $bytes) {
                self::assertSame(hash('sha256', $bytes), $byOwner['knossos.rust:file:' . $relative] ?? null, $relative);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }
}
