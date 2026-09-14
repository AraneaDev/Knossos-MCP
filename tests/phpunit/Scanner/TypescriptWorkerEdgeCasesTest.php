<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-worker edge cases in TypeScript module resolution that only surface
 * through Node's own module resolution algorithm: a bare-specifier import
 * into `node_modules` (which the host realpaths as part of resolving it), a
 * legacy `import x = require(...)` declaration, a requested file the
 * compiler never includes in any program, and a symlink whose target walks
 * back up a directory.
 */
#[Group('typescript-scanner')]
final class TypescriptWorkerEdgeCasesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // The raw-protocol test below needs no fixture tree at all.
        if (is_dir($this->root)) {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    /**
     * A bare-specifier import into a real `node_modules` package makes the
     * compiler realpath the resolved file as part of ordinary Node module
     * resolution, and `import x = require(...)` is TypeScript's legacy
     * import-equals form for the same relative target a plain `import`
     * would reach.
     */
    public function testABareSpecifierImportAndAnImportEqualsDeclarationBothResolve(): void
    {
        mkdir($this->root . '/src', 0o755, true);
        mkdir($this->root . '/other', 0o755, true);
        mkdir($this->root . '/node_modules/leftpad', 0o755, true);

        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['module' => 'CommonJS', 'moduleResolution' => 'node', 'allowJs' => true],
            'files' => ['src/entry.ts', 'other/legacy.ts'],
        ]));
        file_put_contents(
            $this->root . '/node_modules/leftpad/package.json',
            json_encode(['name' => 'leftpad', 'version' => '1.0.0', 'main' => 'index.js']),
        );
        file_put_contents($this->root . '/node_modules/leftpad/index.js', "module.exports.pad = function (s) {\n    return s;\n};\n");
        file_put_contents($this->root . '/other/legacy.ts', "export const value = 1;\n");
        file_put_contents(
            $this->root . '/src/entry.ts',
            "import { pad } from \"leftpad\";\nimport other = require(\"../other/legacy\");\nexport const padded = pad(\"x\");\nexport const legacyValue = other.value;\n",
        );

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $this->root,
            'files' => ['src/entry.ts', 'other/legacy.ts'],
        ]));
        $client->shutdown();

        $entry = $this->contributionFor($contributions, 'src/entry.ts');
        $packageTargets = array_map(
            fn(EdgeFact $edge): string => $edge->targetReference,
            array_filter($entry->edges, fn(EdgeFact $edge): bool => str_contains($edge->targetReference, 'leftpad')),
        );
        assertSame(true, count($packageTargets) > 0, 'A bare-specifier import into node_modules must resolve to a package edge.');

        $importEqualsTargets = array_map(
            fn(EdgeFact $edge): string => $edge->targetReference,
            array_filter($entry->edges, fn(EdgeFact $edge): bool => str_contains($edge->targetReference, 'other/legacy.ts')),
        );
        assertSame(true, count($importEqualsTargets) > 0, 'An import-equals declaration must resolve like a plain relative import.');
    }

    /**
     * A file requested from inside a nested `node_modules` directory is a
     * real, readable file the compiler still never emits (the emitter skips
     * any source file whose path runs through node_modules): the scanner's
     * backstop reports it as unscannable rather than silently dropping it.
     */
    public function testARequestedFileInsideNodeModulesIsReportedUnscannableNotDropped(): void
    {
        mkdir($this->root . '/lib/node_modules/nestedpkg', 0o755, true);
        file_put_contents($this->root . '/lib/node_modules/nestedpkg/index.ts', "export const nestedThing = 1;\n");
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['module' => 'CommonJS', 'moduleResolution' => 'node'],
            'files' => [],
        ]));

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $this->root,
            'files' => ['lib/node_modules/nestedpkg/index.ts'],
        ]));
        $client->shutdown();

        $contribution = $this->contributionFor($contributions, 'lib/node_modules/nestedpkg/index.ts');
        assertSame([], $contribution->nodes);
        assertSame(
            ['TS_UNSCANNABLE_FILE'],
            array_map(fn($d): string => $d->code, $contribution->diagnostics),
        );
    }

    /**
     * A symlink whose target text itself walks back up a directory (`../`)
     * forces the kernel-lookup emulation to resolve a `..` component that
     * only the link introduced, once the link has already been followed.
     */
    public function testASymlinkTargetContainingDotDotStillResolvesInsideTheRoot(): void
    {
        mkdir($this->root . '/src/real', 0o755, true);
        mkdir($this->root . '/src/nested', 0o755, true);
        file_put_contents($this->root . '/src/real/target.ts', "export const deepValue = 1;\n");
        symlink('../real/target.ts', $this->root . '/src/nested/linkback.ts');
        file_put_contents(
            $this->root . '/src/nested/uselink.ts',
            "import { deepValue } from \"./linkback\";\nexport const usesLink = deepValue;\n",
        );
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['module' => 'CommonJS', 'moduleResolution' => 'node'],
            'include' => ['src'],
        ]));

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $this->root,
            'files' => ['src/nested/uselink.ts', 'src/real/target.ts'],
        ]));
        $client->shutdown();

        $useLink = $this->contributionFor($contributions, 'src/nested/uselink.ts');
        assertSame([], $useLink->diagnostics);
        assertSame(true, count($useLink->edges) > 0, 'The import through the symlink must still resolve to an edge.');
    }

    /**
     * TypeScript loads one copy of a package name@version and redirects
     * every further copy resolved to it. Two physically distinct copies of
     * the same package, imported from two different files, produce exactly
     * that: the second import's source file carries `redirectInfo`. With
     * identical bytes in both copies the redirect is verified, so facts and
     * a content hash still flow for the copy the compiler treated as a
     * duplicate.
     */
    public function testADuplicatePackageCopyWithAgreeingBytesStillGetsVerifiedFacts(): void
    {
        mkdir($this->root . '/vendor/node_modules/duppkg', 0o755, true);
        mkdir($this->root . '/node_modules/duppkg', 0o755, true);
        $packageJson = json_encode(['name' => 'duppkg', 'version' => '1.0.0', 'main' => 'index.ts']);
        $indexSource = "export const dupValue = 1;\n";
        file_put_contents($this->root . '/vendor/node_modules/duppkg/package.json', $packageJson);
        file_put_contents($this->root . '/vendor/node_modules/duppkg/index.ts', $indexSource);
        file_put_contents($this->root . '/node_modules/duppkg/package.json', $packageJson);
        file_put_contents($this->root . '/node_modules/duppkg/index.ts', $indexSource);
        file_put_contents(
            $this->root . '/vendor/importFirst.ts',
            "import { dupValue } from \"duppkg\";\nexport const first = dupValue;\n",
        );
        file_put_contents(
            $this->root . '/importSecond.ts',
            "import { dupValue } from \"duppkg\";\nexport const second = dupValue;\n",
        );
        // A `files` array (rather than `include`) fixes the processing order:
        // the vendor copy must be resolved and registered under its package
        // id before the top-level import resolves the second, redirected
        // copy.
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['module' => 'CommonJS', 'moduleResolution' => 'node', 'allowJs' => true],
            'files' => ['vendor/importFirst.ts', 'importSecond.ts'],
        ]));

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $this->root,
            'files' => ['vendor/importFirst.ts', 'importSecond.ts', 'node_modules/duppkg/index.ts'],
        ]));
        $client->shutdown();

        $duplicate = $this->contributionFor($contributions, 'node_modules/duppkg/index.ts');
        assertSame([], $duplicate->diagnostics, 'Identical bytes must not raise TS_REDIRECTED_SOURCE_UNVERIFIED.');
        assertSame(true, $duplicate->contentHash !== null, 'A verified redirect must still carry a content hash.');
        assertSame(true, count($duplicate->nodes) > 0);
    }

    /**
     * A line that parses as JSON but is not a request object (a bare array
     * here) is a request-level protocol error, distinct from an unscannable
     * file: there is no id to attribute it to, and no per-file contribution
     * could stand in for it. An unrecognised method name is the same kind of
     * error once the line does parse as an object.
     *
     * `ProcessScannerClient` only ever sends well-formed requests, so this
     * drives the worker's raw stdio protocol directly, the same way
     * `WorkerClients::runPythonWorkerProtocol()` does for the Python worker.
     */
    public function testAMalformedLineAndAnUnknownMethodAreBothRequestLevelErrors(): void
    {
        $responses = $this->runRawTypescriptWorkerProtocol([
            '[1,2,3]',
            json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'not-a-real-method', 'params' => new \stdClass()]),
            json_encode(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'shutdown', 'params' => new \stdClass()]),
        ]);

        assertSame(3, count($responses));
        assertSame(true, array_key_exists('id', $responses[0]) && $responses[0]['id'] === null);
        assertSame(-32602, $responses[0]['error']['code']);
        assertSame(9, $responses[1]['id']);
        assertContains('Unknown method', $responses[1]['error']['message']);
        assertSame('bye', $responses[2]['result']['status']);
    }

    /** @param list<ScanContribution> $contributions */
    private function contributionFor(array $contributions, string $relativePath): ScanContribution
    {
        foreach ($contributions as $contribution) {
            if (str_ends_with($contribution->ownerKey, ':file:' . $relativePath)) {
                return $contribution;
            }
        }

        self::fail(sprintf('No contribution found for %s.', $relativePath));
    }

    /**
     * Speak the worker's newline-delimited JSON-RPC protocol directly over a
     * fresh subprocess, the way `WorkerClients::runPythonWorkerProtocol()`
     * does, so a malformed line can be sent at all.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function runRawTypescriptWorkerProtocol(array $lines): array
    {
        $coverageDirectory = getenv('KNOSSOS_JS_COVERAGE_DIR');
        $command = is_string($coverageDirectory) && $coverageDirectory !== ''
            ? ['env', 'NODE_V8_COVERAGE=' . $coverageDirectory, 'node', self::repositoryRoot() . '/workers/typescript/bin/worker.js']
            : ['node', self::repositoryRoot() . '/workers/typescript/bin/worker.js'];

        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('Unable to start TypeScript worker protocol fixture.');
        }
        foreach ($lines as $line) {
            fwrite($pipes[0], $line . "\n");
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        assertSame(0, $exit);
        assertSame('', $stderr);

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($stdout)))),
        );
    }
}
