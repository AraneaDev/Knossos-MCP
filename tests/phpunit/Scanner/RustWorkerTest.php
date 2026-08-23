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
}
