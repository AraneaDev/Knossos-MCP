<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class TypescriptScannerTest extends KnossosTestCase
{
    /**
     * Discovery classifies an extensionless script by its shebang and routes it
     * here, so the worker has to accept one. TypeScript itself silently drops a
     * root file whose name carries no recognised extension, so the script is
     * offered to the program under a synthetic name — which must never surface:
     * every node, edge, and owner key has to name the file that exists on disk.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerScansAnExtensionlessShebangScriptUnderItsRealPath(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-shebang-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0o755, true);
        mkdir($root . '/src', 0o755, true);
        file_put_contents($root . '/package.json', '{"name":"shebang-fixture"}');
        file_put_contents($root . '/src/helper.js', "export function helper() {\n    return 1;\n}\n");
        file_put_contents(
            $root . '/bin/cli',
            "#!/usr/bin/env node\nimport { helper } from '../src/helper.js';\nexport function run() {\n    return helper();\n}\n",
        );
        // Not JavaScript at all: a shebang naming another interpreter, and a
        // path that merely contains one, must both still be refused.
        file_put_contents($root . '/bin/other', "#!/bin/sh\necho hi\n");

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['bin/cli', 'bin/other'],
            ]));
            $client->shutdown();
        } finally {
            foreach (['bin/cli', 'bin/other', 'src/helper.js', 'package.json'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/bin');
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[str_replace('knossos.typescript:file:', '', $contribution->ownerKey)] = $contribution;
        }
        // Rejections are emitted before the program is built, so sort rather
        // than pin an order the protocol does not promise.
        $owners = array_keys($byOwner);
        sort($owners, SORT_STRING);
        assertSame(['bin/cli', 'bin/other'], $owners);

        $names = array_map(fn(NodeFact $node): string => $node->canonicalName, $byOwner['bin/cli']->nodes);
        assertSame(true, count($names) > 0);
        // The synthetic name must not leak into the graph.
        assertSame([], array_values(array_filter($names, fn(string $n): bool => str_contains($n, 'knossos-shebang'))));
        assertSame(true, count(array_filter($names, fn(string $n): bool => str_contains($n, 'run'))) > 0);
        assertSame([], $byOwner['bin/cli']->diagnostics);

        assertSame([], $byOwner['bin/other']->nodes);
        assertSame(
            ['TS_UNSCANNABLE_FILE'],
            array_map(fn(Diagnostic $d): string => $d->code, $byOwner['bin/other']->diagnostics),
        );
    }

    /**
     * A script's body is run by a shell, never referenced from the codebase, so
     * its module has no inbound edge however heavily the script is used. Only
     * the PHP scanner marked its modules executable, so `architecture_health`
     * had nothing to exclude on and reported this repository's own
     * `tools/chaos-loop.mjs` — driven by a shell wrapper — as probably dead
     * code, while the TypeScript worker escaped the same fate only because a
     * manifest happened to name it an entry point.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerMarksShebangScriptModulesExecutable(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-executable-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0o755, true);
        mkdir($root . '/src', 0o755, true);
        file_put_contents($root . '/package.json', '{"name":"executable-fixture"}');
        file_put_contents($root . '/bin/cli', "#!/usr/bin/env node\nexport function run() {\n    return 1;\n}\n");
        file_put_contents($root . '/src/loop.mjs', "#!/usr/bin/env node\nexport function loop() {\n    return 2;\n}\n");
        file_put_contents($root . '/src/helper.js', "export function helper() {\n    return 3;\n}\n");

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['bin/cli', 'src/loop.mjs', 'src/helper.js'],
            ]));
            $client->shutdown();
        } finally {
            foreach (['bin/cli', 'src/loop.mjs', 'src/helper.js', 'package.json'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/bin');
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $executable = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $executable[$node->canonicalName] = $node->attributes['executable'] ?? false;
                }
            }
        }

        // An extensionless script and a suffixed one are both entered by a shell.
        assertSame(true, $executable['bin/cli']);
        assertSame(true, $executable['src/loop.mjs']);
        // A library module nothing imports is exactly what dead-code analysis
        // exists to surface, so it must stay reportable.
        assertSame(false, $executable['src/helper.js']);
    }

    /**
     * The JavaScript `__main__` guard. A module whose body runs under
     * `if (import.meta.main)` is compiled or launched as an entry point and is
     * imported, if at all, only by its tests, so without the flag it was
     * reported as reached only by tests. `require.main === module` is the
     * CommonJS form.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerMarksMainGuardedModulesExecutable(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-main-guard-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"main-guard-fixture"}',
            'src/hook.ts' => "export function handle() {}\nif (import.meta.main) {\n    handle();\n}\n",
            'src/cli.cjs' => "function run() {}\nif (require.main === module) run();\n",
            'src/reversed.js' => "function run() {}\nif (module === require.main) {\n    run();\n}\n",
            'src/nested.ts' => "export function check() {\n    if (import.meta.main) return 1;\n    return 0;\n}\n",
            'src/negated.ts' => "export function lib() {}\nif (!import.meta.main) lib();\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => array_values(array_filter(
                    array_keys($files),
                    fn(string $relative): bool => $relative !== 'package.json',
                )),
            ]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $executable = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $executable[$node->canonicalName] = $node->attributes['executable'] ?? false;
                }
            }
        }

        assertSame(true, $executable['src/hook.ts']);
        assertSame(true, $executable['src/cli.cjs']);
        assertSame(true, $executable['src/reversed.js']);
        // Only a guard at file scope says how the file is entered.
        assertSame(false, $executable['src/nested.ts']);
        // A negated guard runs its body when the module is imported, not run.
        assertSame(false, $executable['src/negated.ts']);
    }

    /**
     * A generic type parameter, an inline object return type and a
     * `const read = () => ...` helper are declared inside the project but are
     * not nodes the scanner emits. References to them were given canonical
     * names like `src/ledger.ts#readAll.T`, `src/ledger.ts#totals.` and
     * `src/ledger.ts#`, which matched nothing, and the core turned every such
     * dangling edge into an external component.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerDoesNotReferenceDeclarationsItNeverEmits(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-unemitted-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"unemitted-fixture"}',
            'src/ledger.ts' => implode("\n", [
                'export function readAll<T>(items: T[]): T[] {',
                '    return items;',
                '}',
                'export function totals(): { added: number } {',
                '    return { added: 1 };',
                '}',
                'const read = (p: string): string => p;',
                'export function run(): string {',
                '    readAll<string>([]);',
                '    return read("x");',
                '}',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['src/ledger.ts'],
            ]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $declared = [];
        $targets = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $declared[$node->localId] = true;
            }
            foreach ($contribution->edges as $edge) {
                $targets[] = $edge->targetReference;
            }
        }

        $dangling = array_values(array_filter(
            $targets,
            fn(string $target): bool => !isset($declared[$target])
                && !str_starts_with($target, 'ts:external_')
                && !str_starts_with($target, 'ts:package:'),
        ));
        assertSame([], $dangling);
        // The call to the declared generic function is still an edge.
        assertArrayContains('ts:function:src/ledger.ts#readAll', $targets);
    }

    /**
     * `new Worker(new URL('./gen.worker.ts', import.meta.url))` is how Vite,
     * webpack and the browser load a module worker. No import names the file,
     * so it carried no inbound edge and was reported as unreferenced while it
     * ran on every page. Asset URLs and paths leaving the project stay out.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerLinksAModuleLoadedThroughAnImportMetaUrl(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-url-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/workers', 0o755, true);
        $files = [
            'package.json' => '{"name":"url-fixture"}',
            'src/pool.ts' => implode("\n", [
                'export function start(): Worker {',
                "    new URL('../../outside.ts', import.meta.url);",
                "    new URL('./logo.svg', import.meta.url);",
                "    new URL('./workers/sim.worker.ts', 'https://example.com/');",
                "    return new Worker(new URL('./workers/gen.worker.ts', import.meta.url), { type: 'module' });",
                '}',
                '',
            ]),
            'src/workers/gen.worker.ts' => "self.onmessage = () => {};\nexport {};\n",
            'src/workers/sim.worker.ts' => "export {};\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/pool.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src/workers');
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $imports = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'imports') {
                    $imports[] = [$edge->sourceReference, $edge->targetReference];
                }
            }
        }

        assertSame([['ts:function:src/pool.ts#start', 'ts:module:src/workers/gen.worker.ts']], $imports);
    }

    /**
     * `const { App } = await import('./tui/App')` loads a module lazily and
     * names the exports it takes. Only a dynamic import's `default` was
     * resolved, so a component loaded this way was reported as reached by
     * nothing but its tests. Destructuring names each export exactly, so no
     * guess is involved; a rest element names none.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerReferencesExportsDestructuredFromADynamicImport(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-dynamic-named-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/tui', 0o755, true);
        $files = [
            'package.json' => '{"name":"dynamic-named-fixture"}',
            'src/cli.ts' => implode("\n", [
                'export async function run(): Promise<unknown[]> {',
                "    const { App, Panel: Renamed, ...rest } = await import('./tui/App');",
                '    return [App, Renamed, rest];',
                '}',
                '',
            ]),
            'src/tui/App.ts' => "export function App() {}\nexport class Panel {}\nexport function Unused() {}\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/cli.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src/tui');
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $references = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references' && str_contains($edge->targetReference, 'src/tui/')) {
                    $references[] = $edge->targetReference;
                }
            }
        }
        sort($references);

        assertSame(['ts:class:src/tui/App.ts#Panel', 'ts:function:src/tui/App.ts#App'], $references);
    }

    /**
     * `{ discover, hydrate }` names two functions in shorthand, and
     * `row = DefaultRow` names one as a parameter default. The shorthand was a
     * listed position, but the name resolves to the object's property rather
     * than the function it copies, and a default was not a position at all, so
     * both functions read as unreferenced.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerReferencesShorthandPropertiesAndDefaults(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-shorthand-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"shorthand-fixture"}',
            'src/reader.ts' => implode("\n", [
                'function discover(): number { return 1; }',
                'function DefaultRow(): string { return "row"; }',
                'function fallbackRun(): number { return 2; }',
                'function eitherRun(): number { return 3; }',
                'export function choose(run?: () => number, flag = false): number {',
                '    const picked = run ?? fallbackRun;',
                '    const other = flag ? eitherRun : picked;',
                '    return picked() + other();',
                '}',
                'export const reader = { discover };',
                'export function list(row: () => string = DefaultRow): string { return row(); }',
                'export function pick({ render = DefaultRow }: { render?: () => string } = {}): string { return render(); }',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/reader.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $references = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references') {
                    $references[] = [$edge->sourceReference, $edge->targetReference];
                }
            }
        }

        assertArrayContains(['ts:module:src/reader.ts', 'ts:function:src/reader.ts#discover'], $references);
        assertArrayContains(['ts:function:src/reader.ts#list', 'ts:function:src/reader.ts#DefaultRow'], $references);
        assertArrayContains(['ts:function:src/reader.ts#pick', 'ts:function:src/reader.ts#DefaultRow'], $references);
        // `run ?? fallbackRun` and `flag ? eitherRun : picked` hand a function over too.
        assertArrayContains(['ts:function:src/reader.ts#choose', 'ts:function:src/reader.ts#fallbackRun'], $references);
        assertArrayContains(['ts:function:src/reader.ts#choose', 'ts:function:src/reader.ts#eitherRun'], $references);
    }

    /**
     * `function make(): Clipboard | null` returns an object literal that
     * implements `Clipboard`. The union has no symbol of its own, so no
     * `returns` edge was drawn and the literal's methods were not tied to the
     * interface its callers use.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerReturnsEachNamedMemberOfAUnion(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-union-return-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"union-return-fixture"}',
            'src/clip.ts' => implode("\n", [
                'export interface Clipboard { writeText(text: string): void; }',
                'export interface Fallback { note(): void; }',
                'export function make(): Clipboard | Fallback | null {',
                '    return { writeText() {} };',
                '}',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/clip.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $returns = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'returns') {
                    $returns[] = $edge->targetReference;
                }
            }
        }
        sort($returns);

        assertSame(['ts:interface:src/clip.ts#Clipboard', 'ts:interface:src/clip.ts#Fallback'], $returns);
    }

    /**
     * `fs?: { readFile(p: string): string }` inside an interface, or as a
     * parameter's type, is a structural type, not code. Its members were
     * emitted as methods named after the enclosing interface or function
     * (`Input::readFile`), claiming a member the interface does not have, and
     * reported as dead code that there is nothing to delete for.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerDoesNotDeclareMembersOfTypeLiterals(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-type-literal-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"type-literal-fixture"}',
            'src/input.ts' => implode("\n", [
                'export interface Input {',
                '    relFile: string;',
                '    fs?: { readFile(p: string): string; size: number };',
                '}',
                'export function run(input: Input, io: { write(c: string): void }): string {',
                '    io.write(input.relFile);',
                '    return input.fs?.readFile(input.relFile) ?? "";',
                '}',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/input.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $names = [];
        $declared = [];
        $targets = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $names[] = $node->canonicalName;
                $declared[$node->localId] = true;
            }
            foreach ($contribution->edges as $edge) {
                $targets[] = $edge->targetReference;
            }
        }

        // The interface's own member stays; the anonymous shapes' members go.
        assertArrayContains('src/input.ts#Input::relFile', $names);
        foreach (['src/input.ts#Input::readFile', 'src/input.ts#Input::size', 'src/input.ts#run::write'] as $name) {
            assertSame(false, in_array($name, $names, true));
        }
        // And nothing points at them either.
        $dangling = array_filter($targets, fn(string $t): bool => !isset($declared[$t]) && str_starts_with($t, 'ts:') && !str_starts_with($t, 'ts:external_') && !str_starts_with($t, 'ts:package:'));
        assertSame([], array_values($dangling));
    }

    /**
     * `declare global { interface Window { api: Api } }` augments a type the
     * runtime defines, and `declare module 'x' { ... }` describes a module
     * someone else ships. Neither is code, exactly as a `.d.ts` is not, but
     * they sat in ordinary files and were reported as unreferenced.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerMarksAmbientDeclarationsInOrdinaryFiles(): void
    {
        $root = sys_get_temp_dir() . '/knossos-ts-ambient-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        $files = [
            'package.json' => '{"name":"ambient-fixture"}',
            'src/engines.ts' => implode("\n", [
                'declare global {',
                '    interface Window { player?: string }',
                '}',
                "declare module 'legacy-lib' {",
                '    export function start(): void;',
                '}',
                'export function play(): string { return window.player ?? ""; }',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['src/engines.ts']]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
        }

        $ambient = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $ambient[$node->canonicalName] = ($node->attributes['ambient'] ?? false) === true;
            }
        }

        assertSame(true, $ambient['src/engines.ts#global.Window']);
        assertSame(true, $ambient['src/engines.ts#global.Window::player']);
        assertSame(true, $ambient['src/engines.ts#legacy-lib.start']);
        assertSame(false, $ambient['src/engines.ts#play']);
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerExtractsCrossProjectArchitecture(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        assertSame('knossos.typescript', $client->initialize()->id);

        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $this->typescriptFixtureFiles(),
        ]));
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
        $serviceNames = array_map(fn(NodeFact $node): string => $node->canonicalName, $service->nodes);
        assertArrayContains('packages/app/src/service.ts#PaymentService', $serviceNames);
        assertSame(1, count(array_filter(
            $serviceNames,
            fn(string $name): bool => $name === 'packages/app/src/service.ts#PaymentService::format',
        )));

        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $service->edges,
        );
        assertArrayContains([
            'implements',
            'ts:class:packages/app/src/service.ts#PaymentService',
            'ts:interface:packages/shared/src/contracts.ts#Payable',
        ], $edgeTuples);
        assertArrayContains([
            'injects',
            'ts:class:packages/app/src/service.ts#PaymentService',
            'ts:class:packages/shared/src/contracts.ts#UserRepository',
        ], $edgeTuples);
        assertArrayContains([
            'constructs',
            'ts:method:packages/app/src/service.ts#PaymentService::pay',
            'ts:class:packages/shared/src/contracts.ts#Invoice',
        ], $edgeTuples);
        assertArrayContains([
            'calls',
            'ts:method:packages/app/src/service.ts#PaymentService::pay',
            'ts:method:packages/shared/src/contracts.ts#UserRepository::save',
        ], $edgeTuples);

        $sharedImports = array_values(array_filter(
            $service->edges,
            fn(EdgeFact $edge): bool => $edge->kind === 'imports'
                && $edge->targetReference === 'ts:module:packages/shared/src/contracts.ts',
        ));
        assertSame(1, count($sharedImports));
        assertSame([false, true], $sharedImports[0]->attributes['type_only_variants']);

        $shared = $byOwner['knossos.typescript:file:packages/shared/src/contracts.ts'];
        assertSame(1, count(array_filter(
            $shared->nodes,
            fn(NodeFact $node): bool => $node->canonicalName === 'packages/shared/src/contracts.ts#Payable',
        )));
        assertSame(false, file_exists($root . '/packages/app/src/EXECUTED'));
        $client->shutdown();
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerCapturesEsmCommonjsTsxExternalAndCompilerFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $this->typescriptFixtureFiles(),
        ]));
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
        assertSame(1, count(array_filter(
            $service->nodes,
            fn(NodeFact $node): bool => $node->kind === 'package' && $node->canonicalName === 'rxjs',
        )));
        assertContains('TS2307', implode(' ', array_map(
            fn(Diagnostic $diagnostic): string => $diagnostic->code,
            $service->diagnostics,
        )));

        $index = $byOwner['knossos.typescript:file:packages/app/src/index.ts'];
        assertContains('re_exports', implode(' ', array_map(fn(EdgeFact $edge): string => $edge->kind, $index->edges)));
        assertSame(true, (bool) array_values(array_filter(
            $index->edges,
            fn(EdgeFact $edge): bool => ($edge->attributes['dynamic'] ?? false) === true,
        ))[0]->attributes['dynamic']);

        $legacy = $byOwner['knossos.typescript:file:packages/app/src/legacy.cjs'];
        assertSame(true, (bool) array_values(array_filter(
            $legacy->edges,
            fn(EdgeFact $edge): bool => ($edge->attributes['commonjs'] ?? false) === true,
        ))[0]->attributes['commonjs']);

        $view = $byOwner['knossos.typescript:file:packages/app/src/view.tsx'];
        assertArrayContains('packages/app/src/view.tsx#CheckoutView', array_map(
            fn(NodeFact $node): string => $node->canonicalName,
            $view->nodes,
        ));

        $invalid = $byOwner['knossos.typescript:file:packages/app/src/invalid.ts'];
        assertContains('TS2322', implode(' ', array_map(
            fn(Diagnostic $diagnostic): string => $diagnostic->code,
            $invalid->diagnostics,
        )));
        $client->shutdown();
    }

    #[Group('typescript-scanner')]
    public function testTypescriptWorkerOutputIsDeterministicBoundedAndPathSafe(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-scanner';
        $client = $this->typescriptWorkerClient();
        $request = ['root' => $root, 'files' => ['packages/app/src/service.ts']];
        $first = iterator_to_array($client->scan($request));
        $second = iterator_to_array($client->scan($request));
        assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));

        $error = captureThrows(
            fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../package.json']])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);

        // A file over the byte cap is well-formed, so it costs only itself: the
        // request succeeds and the file arrives as a diagnostic-only contribution.
        $limited = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($limited->scan([
            'root' => $root,
            'files' => ['packages/app/src/service.ts'],
            'limits' => ['max_file_bytes' => 1],
        ]));
        assertSame(1, count($contributions));
        assertSame([], $contributions[0]->nodes);
        assertSame('TS_UNSCANNABLE_FILE', $contributions[0]->diagnostics[0]->code);
    }

    /**
     * The core compares each contribution's hash with discovery's hash of the
     * file on disk, so the TypeScript worker has to hash the bytes it read, not
     * the text the compiler decoded from them: a byte-order mark is dropped by
     * the decoder but is part of the file.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerReportsTheHashOfTheRawBytesItParsed(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'src/Bom.ts' => "\xEF\xBB\xBFexport class Bom {}\n",
            'src/Crlf.ts' => "export class Crlf {}\r\n",
            'src/Broken.ts' => "export class {\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->typescriptWorkerClient();
        try {
            assertSame(true, in_array('content_hash', $client->initialize()->capabilities, true));
            $byOwner = [];
            foreach ($client->scan(['root' => $root, 'files' => array_keys($files)]) as $contribution) {
                $byOwner[$contribution->ownerKey] = $contribution->contentHash;
            }

            foreach ($files as $relative => $bytes) {
                assertSame(hash('sha256', $bytes), $byOwner['knossos.typescript:file:' . $relative] ?? null, $relative);
            }
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * The checker resolves `src/User.ts` against `src/Base.ts`, so the result
     * names both reads, each by the hash of its raw bytes: the BOM that the
     * decoder drops is still in the hash discovery computes.
     */
    #[Group('typescript-scanner')]
    public function testTypescriptWorkerReportsEveryFileItsProgramRead(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'src/Base.ts' => "\xEF\xBB\xBFexport class Base {}\n",
            'src/User.ts' => "import { Base } from './Base';\nexport class User extends Base {}\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->typescriptWorkerClient();
        try {
            assertSame(true, in_array('input_hashes', $client->initialize()->capabilities, true));
            iterator_to_array($client->scan(['root' => $root, 'files' => ['src/User.ts']]));
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            $expected = array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files);
            assertSame($expected, $inputHashes);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * An extensionless script refused on its shebang is reported by the hash
     * of the whole file, judged again on those bytes, which a stable tree
     * matches. One over the byte cap has no whole-file hash and is null; one
     * refused by its name read nothing and is not reported.
     */
    #[Group('typescript-scanner')]
    public function testAShebangRefusalReportsTheHashItsVerdictRestedOn(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0o777, true);
        $python = "#!/usr/bin/env python3\nprint(1)\n";
        file_put_contents($root . '/bin/tool', $python);
        file_put_contents($root . '/bin/large', "#!/bin/sh\n" . str_repeat('#', 100) . "\n");
        file_put_contents($root . '/notes.txt', "text\n");
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['bin/large', 'bin/tool', 'notes.txt'],
                'limits' => ['max_file_bytes' => 64],
            ]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            assertSame(3, count($contributions));
            assertSame(['bin/large' => null, 'bin/tool' => hash('sha256', $python)], $inputHashes);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * Module resolution reads package.json through the compiler host, and its
     * fields decide how an import resolves, so the read is recorded: by the
     * hash of its bytes, or as null when it could not be read within the cap.
     */
    #[Group('typescript-scanner')]
    public function testPackageJsonReadsDuringModuleResolutionAreRecorded(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/sub', 0o777, true);
        $package = "{\"type\":\"module\"}\n";
        file_put_contents($root . '/tsconfig.json', '{"compilerOptions":{"module":"nodenext","moduleResolution":"nodenext"},"include":["src/**/*","sub/**/*"]}');
        file_put_contents($root . '/package.json', $package);
        file_put_contents($root . '/sub/package.json', '{"type":"module"}' . str_repeat(' ', 200) . "\n");
        file_put_contents($root . '/src/a.ts', "import { s } from '../sub/s.js';\nexport const a = s;\n");
        file_put_contents($root . '/sub/s.ts', "export const s = 1;\n");
        $client = $this->typescriptWorkerClient();
        try {
            iterator_to_array($client->scan(['root' => $root, 'files' => ['src/a.ts'], 'limits' => ['max_file_bytes' => 128]]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? [];

            assertSame(hash('sha256', $package), $inputHashes['package.json'] ?? 'absent');
            assertSame(true, array_key_exists('sub/package.json', $inputHashes));
            assertSame(null, $inputHashes['sub/package.json']);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * A tsconfig and the config it extends decide the program, so both reads
     * are recorded by the hash of their raw bytes, comments and all.
     */
    #[Group('typescript-scanner')]
    public function testTsconfigReadsAreRecordedByTheHashOfTheirRawBytes(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $base = "{\n  // shared\n  \"compilerOptions\": {\"strict\": true}\n}\n";
        $config = "\xEF\xBB\xBF{\"extends\": \"./tsconfig.base.json\", \"include\": [\"src\"]}\n";
        file_put_contents($root . '/tsconfig.base.json', $base);
        file_put_contents($root . '/tsconfig.json', $config);
        file_put_contents($root . '/src/a.ts', "export const a = 1;\n");
        $client = $this->typescriptWorkerClient();
        try {
            iterator_to_array($client->scan(['root' => $root, 'files' => ['src/a.ts'], 'config_files' => ['tsconfig.json']]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? [];

            assertSame(hash('sha256', $config), $inputHashes['tsconfig.json'] ?? 'absent');
            assertSame(hash('sha256', $base), $inputHashes['tsconfig.base.json'] ?? 'absent');
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }
}
