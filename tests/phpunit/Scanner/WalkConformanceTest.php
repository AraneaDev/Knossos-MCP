<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\UnitInputSet;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The TypeScript and Python workers walk one tree by the same rules: the same
 * exclusions, which the core sends both, and the same keys for what they read
 * through a linked directory, past a link out of the root and into a directory
 * they may not be able to read.
 *
 * The fixture holds a module of identical bytes in each language at each
 * place, imported the same way by `app/main.ts` and `app/main.py`, so what
 * the two report for one place is comparable once the extension is dropped.
 */
#[Group('typescript-scanner')]
final class WalkConformanceTest extends KnossosTestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-walk-' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/knossos-stale-walk-outside-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/walk-conformance', $this->root);
        mkdir($this->outside, 0o777, true);
        file_put_contents($this->outside . '/mod.ts', "value = 1\n");
        file_put_contents($this->outside . '/mod.py', "value = 1\n");
        symlink('real', $this->root . '/linked');
        symlink($this->outside, $this->root . '/outside');
        // Unreadable for an unprivileged user; root reads it all the same,
        // and the two workers must still agree on it.
        chmod($this->root . '/locked', 0o000);
    }

    protected function tearDown(): void
    {
        chmod($this->root . '/locked', 0o755);
        $this->removeTempTree($this->root);
        $this->removeTempTree($this->outside);
        parent::tearDown();
    }

    public function testBothWorkersReportTheSameReadsForTheSamePlaces(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        $exclusions = (new IgnoreMatcher(['legacy/**']))->workerRules();

        $typescript = self::byPlace($this->inputHashes($this->typescriptWorkerClient(), 'app/main.ts', $exclusions), '.ts');
        $python = self::byPlace($this->inputHashes($this->pythonWorkerClient(), 'app/main.py', $exclusions), '.py');

        // Each worker's own entry point differs; everything it read does not.
        unset($typescript['app/main'], $python['app/main']);
        $shared = array_intersect_key($typescript, $python);
        self::assertArrayHasKey('real/mod', $shared);
        self::assertArrayHasKey('locked/mod', $shared);
        self::assertArrayHasKey('outside/mod', $shared);
        foreach ($shared as $place => $value) {
            if (str_starts_with($place, 'linked/')) {
                continue;
            }
            assertSame($python[$place], $value, sprintf('%s is read differently', $place));
        }
        // Through the linked directory both key the path as written. The
        // protocol lets a worker put null under every key a link gives, which
        // the Python worker does, or the value of what the link leads to,
        // which the TypeScript worker does.
        assertSame(null, $python['linked/mod']);
        assertSame($typescript['real/mod'], $typescript['linked/mod']);
        // Discovery leaves venv/ out, so neither worker reads anything there.
        foreach ([$typescript, $python] as $reads) {
            assertSame([], array_values(array_filter(array_keys($reads), static fn(string $place): bool => str_starts_with($place, 'venv/'))));
        }
        // Nothing beyond the root is read, whatever a link there leads to.
        assertSame(null, $typescript['outside/mod'] ?? null);
        assertSame(null, $python['outside/mod'] ?? null);
    }

    public function testBothWorkersRefuseExclusionsThatAreNotTheRules(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        foreach ([[$this->typescriptWorkerClient(), 'app/main.ts'], [$this->pythonWorkerClient(), 'app/main.py']] as [$client, $file]) {
            try {
                $error = captureThrows(
                    fn() => iterator_to_array($client->scan(['root' => $this->root, 'files' => [$file], 'exclusions' => ['segments' => 'venv']]), false),
                    WorkerException::class,
                );
            } finally {
                $client->shutdown();
            }
            self::assertStringContainsString('exclusions must be an object', $error->getMessage());
        }
    }

    /** A scan sends the project's own ignores with the built-in rules, so neither is read. */
    public function testAScanLeavesOutWhatTheProjectIgnores(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        file_put_contents($this->root . '/knossos.json', (string) json_encode(['version' => 1, 'ignores' => ['locked/**']]));
        $pdo = $this->freshTestDatabase();

        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        assertSame([], $result->data['degraded_languages']);
        $json = (string) $pdo->query('SELECT s.unit_inputs_json FROM scans s JOIN projects p ON p.active_scan_id = s.id')->fetchColumn();
        $read = array_keys(UnitInputSet::decodeWorkerInputs($json) ?? []);
        assertSame([], array_values(array_filter($read, static fn(string $path): bool => str_starts_with($path, 'venv/') || str_starts_with($path, 'locked/'))));
    }

    /**
     * @param array<string, mixed> $exclusions
     * @return array<string, string|null>
     */
    private function inputHashes(ProcessScannerClient $client, string $file, array $exclusions): array
    {
        try {
            iterator_to_array($client->scan(['root' => $this->root, 'files' => [$file], 'exclusions' => $exclusions]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? [];
        } finally {
            $client->shutdown();
        }
        self::assertIsArray($inputHashes);

        return $inputHashes;
    }

    /**
     * The reads keyed by the place they name: the path without the worker's
     * own extension, so `real/mod.ts` and `real/mod.py` compare.
     *
     * @param array<string, string|null> $inputHashes
     * @return array<string, string|null>
     */
    private static function byPlace(array $inputHashes, string $extension): array
    {
        $places = [];
        foreach ($inputHashes as $path => $hash) {
            $path = (string) $path;
            if (str_ends_with($path, $extension)) {
                $places[substr($path, 0, -strlen($extension))] = $hash;
            }
        }

        return $places;
    }
}
