<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An object literal typed as an interface is how TypeScript writes an
 * implementation without a class: `export const charon: View = { query() {} }`.
 * A caller typed as `View` reaches `View::query`, so the literal's own member
 * carried no inbound edge and every view, tool and strategy object written
 * this way was reported as dead. The member was also named after the module
 * alone, so two such literals in one file shared a name.
 */
#[Group('query')]
final class ObjectLiteralImplementationTest extends KnossosTestCase
{
    /**
     * An object literal passed straight to a parameter typed as an interface,
     * `register({ handle() {} })`, or to a library, `new Proxy(target, { get() {} })`
     * or tiptap's `Node.create({ addAttributes() {} })`, implements the type
     * of the place it is passed to. Its methods were members of the enclosing
     * function, tied to nothing, and every one read as probably dead.
     */
    public function testMembersOfAnObjectLiteralPassedWhereATypeIsExpectedAreReachedThroughIt(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-contextual-literal-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'package.json' => '{"name":"contextual-literal-fixture"}',
            'tsconfig.json' => '{"compilerOptions":{"strict":true,"noEmit":true,"lib":["es2022"]},"include":["src"]}',
            'src/handler.ts' => implode("\n", [
                'export interface Handler {',
                '    handle(): void;',
                '}',
                'export function register(handler: Handler): void {',
                '    handler.handle();',
                '}',
                'export function setup(): unknown {',
                '    register({ handle() {} });',
                '    return new Proxy({}, { get() { return 1; } });',
                '}',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $byName = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $byName[$candidate['component']['display_name']] = $candidate['confidence'];
        }
        // Reached through the internal interface it implements.
        self::assertArrayNotHasKey('handle', $byName);
        // A library's contract: possibly unused at most, never probably.
        self::assertNotSame('probable', $byName['get'] ?? null);
    }

    public function testMembersOfAnObjectLiteralTypedAsAnInterfaceAreNotDeadCode(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-object-literal-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'package.json' => '{"name":"object-literal-fixture"}',
            'tsconfig.json' => '{"compilerOptions":{"strict":true,"noEmit":true},"include":["src"]}',
            'src/types.ts' => "export interface View {\n    describe(): string;\n    query(): number;\n}\n",
            'src/views.ts' => implode("\n", [
                "import type { View } from './types';",
                'export const charon: View = {',
                "    describe() { return 'charon'; },",
                '    query() { return 1; },',
                '};',
                'export const lethe = {',
                "    describe() { return 'lethe'; },",
                '    query() { return 2; },',
                '} satisfies View;',
                'export const loose = {',
                '    unused() { return 3; },',
                '};',
                '',
            ]),
            'src/main.ts' => implode("\n", [
                "import type { View } from './types';",
                "import { charon, lethe, loose } from './views';",
                'export function render(view: View): string {',
                '    return view.describe() + view.query();',
                '}',
                'render(charon);',
                'render(lethe);',
                'export const all = [loose];',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId)->data;
            $candidates = array_map(
                static fn(array $candidate): string => $candidate['component']['canonical_name'],
                $data['dead_code_candidates'],
            );
            $names = $pdo->query("SELECT canonical_name FROM nodes WHERE kind = 'method' ORDER BY canonical_name")->fetchAll(\PDO::FETCH_COLUMN);
        } finally {
            $this->removeTempTree($root);
        }

        // Each literal's members are named after the literal, so they no longer collide.
        foreach (['charon', 'lethe'] as $literal) {
            foreach (['describe', 'query'] as $member) {
                self::assertContains("src/views.ts#{$literal}::{$member}", $names);
                self::assertNotContains("src/views.ts#{$literal}::{$member}", $candidates);
            }
        }
        // The bindings are used as values, so they are not dead either.
        foreach (['charon', 'lethe', 'loose'] as $literal) {
            self::assertNotContains("src/views.ts#{$literal}", $candidates);
        }
        // A literal that satisfies nothing still has its unused member reported.
        self::assertContains('src/views.ts#loose::unused', $candidates);
    }
}
