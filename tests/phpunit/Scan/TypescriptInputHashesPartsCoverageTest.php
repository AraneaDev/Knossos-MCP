<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The real, coverage-instrumented TypeScript worker client splits a large
 * `input_hashes` map into `scan/input_hashes` parts.
 *
 * `InputHashesPartsScanTest` already proves the merge is correct end to end,
 * but it builds its `ProcessScannerClient` directly from a `LanguageDescriptor`
 * command, bypassing `WorkerClients::typescriptWorkerClient()`'s
 * `NODE_V8_COVERAGE` wrapping -- so that real split is invisible to coverage.
 * This drives the same behaviour through the instrumented client instead.
 */
#[Group('scan')]
final class TypescriptInputHashesPartsCoverageTest extends KnossosTestCase
{
    private const FILES = 1_000;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAProgramWhoseInputHashesMapOutgrowsOneFrameStillReportsEveryFile(): void
    {
        $directory = 'src/' . str_repeat('long-directory-name-', 9);
        mkdir($this->root . '/' . $directory, 0o777, true);
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['strict' => true],
            'include' => ['src'],
        ]));
        $expected = [];
        for ($i = 0; $i < self::FILES; ++$i) {
            $relative = sprintf('%s/module-%04d.ts', $directory, $i);
            $contents = sprintf("export const value%d = %d;\n", $i, $i);
            file_put_contents($this->root . '/' . $relative, $contents);
            $expected[$relative] = hash('sha256', $contents);
        }
        file_put_contents(
            $this->root . '/src/entry.ts',
            sprintf("import { value0 } from './%s/module-0000';\nexport const entry = value0;\n", substr($directory, 4)),
        );
        $expected['src/entry.ts'] = hash('sha256', (string) file_get_contents($this->root . '/src/entry.ts'));

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $this->root,
            'files' => ['src/entry.ts'],
        ]));
        $inputHashes = $client->lastScanResult()['input_hashes'] ?? [];
        $client->shutdown();

        assertSame([], $contributions[0]->diagnostics);
        // Larger than the JS worker's own 256,000-byte part size, so the map
        // cannot have travelled on the result's single line: it was split into
        // `scan/input_hashes` parts and merged back by the PHP session.
        assertSame(true, strlen((string) json_encode($inputHashes)) > 256_000);
        ksort($expected);
        ksort($inputHashes);
        assertSame($expected, $inputHashes);
    }
}
