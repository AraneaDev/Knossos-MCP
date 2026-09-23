<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vue, Svelte and Astro components read by the TypeScript worker, driven the
 * way the core drives it. Each fixture project holds one of every construct
 * the component reader handles, so each assertion names the fact that
 * construct has to produce.
 */
#[Group('typescript-scanner')]
final class ComponentWorkerTest extends KnossosTestCase
{
    public function testAVueProjectsComponentsImportsAndTemplatesBecomeFacts(): void
    {
        $scan = $this->scanFixture('vue', ['tsconfig.json'], ['vue_projects' => ['']]);

        // Template usages: interpolations, multi-statement handlers, v-for iterables, kebab-case tags.
        foreach (['src/util.ts#formatDate', 'src/App.vue#select', 'src/App.vue#track', 'src/App.vue#reset'] as $target) {
            self::assertTrue($scan->reaches('src/App.vue', $target), $target);
        }
        self::assertTrue($scan->imports('src/App.vue', 'src/components/UserCard.vue'));
        // A v-for that destructures its item keeps its iterable.
        self::assertTrue($scan->reaches('src/App.vue', 'src/App.vue#listUsers'));
        // Vite's array form with a root-relative replacement.
        self::assertTrue($scan->imports('src/main.js', 'src/util.ts'));
        // `./Card.vue` means the component, though a Card.vue.ts sits beside it.
        self::assertTrue($scan->imports('src/components/DataTable.vue', 'src/components/Card.vue'));
        // A path joined from a root variable.
        self::assertTrue($scan->imports('tools/shots.js', 'src/util.ts'));
        // Extensionless imports resolve to a component, through a bundler alias too.
        self::assertTrue($scan->imports('src/main.js', 'src/App.vue'));
        self::assertTrue($scan->imports('src/main.js', 'src/store/index.js'));
        // require.context names its directory for the core to expand.
        self::assertSame(
            ['src/layouts', 'src/layouts', 'src/store/modules'],
            $scan->contextDirectories('src/store/index.js'),
        );
        // Options API: hooks, watchers and prop factories are called by Vue; used members are referenced.
        self::assertSame(['data', 'default', 'handler', 'metaInfo', 'mounted', 'n', 'validator'], $scan->runtimeInvoked('src/components/Legacy.vue'));
        foreach (['label', 'save', 'helper', 'recount'] as $member) {
            self::assertTrue($scan->reachesMember('src/components/Legacy.vue', $member), $member);
        }
        self::assertFalse($scan->reachesMember('src/components/Legacy.vue', 'unused'));
        self::assertTrue($scan->reachesMember('src/components/Defined.vue', 'total'));
        // A plain JavaScript script's require() imports what it names.
        self::assertTrue($scan->imports('src/components/Legacy.vue', 'src/util.ts'));
        self::assertTrue($scan->imports('src/components/Legacy.vue', 'src/components/UserCard.vue'));
        // A real X.vue.ts keeps its own facts beside the component.
        self::assertContains('src/components/Card.vue#fromVue', $scan->canonicalNames());
        self::assertContains('src/components/Card.vue.ts#fromTs', $scan->canonicalNames());
        // Unreadable components keep a module and say why; template and framework-global diagnostics are dropped.
        self::assertSame(['src/components/Broken.vue', 'src/components/Tsx.vue'], $scan->diagnosticPaths('COMPONENT_UNPARSED'));
        self::assertContains('src/components/Tsx.vue', $scan->moduleNames());
        self::assertSame(['src/App.vue'], $scan->diagnosticPaths('TS2322'));
        self::assertSame([], $scan->diagnosticPaths('TS2304'));
    }

    public function testASvelteKitAppsAliasesBlocksAndMarkupBecomeFacts(): void
    {
        $scan = $this->scanFixture('svelte', ['tsconfig.json']);

        // $lib and kit.alias resolve without the generated tsconfig.
        self::assertTrue($scan->imports('src/routes/+page.svelte', 'src/components/Button.svelte'));
        self::assertTrue($scan->imports('src/routes/+page.svelte', 'src/lib/format.ts'));
        // Block tags, directives, stores and expressions after comments and comparisons.
        foreach (['src/lib/format.ts#format', 'src/lib/format.ts#load', 'src/lib/count.ts#increment'] as $target) {
            self::assertTrue($scan->reaches('src/routes/+page.svelte', $target), $target);
        }
        self::assertSame([], $scan->diagnosticPaths('COMPONENT_UNPARSED'));
        self::assertContains('src/routes/about/+page.svelte', $scan->moduleNames());
        // A script ending inside a line comment leaves the rest of that line
        // blank, and the markup after it is still read.
        self::assertTrue($scan->reaches('src/routes/comment/+page.svelte', 'src/lib/format.ts#format'));
        // The `//` of a regular expression is no comment.
        self::assertTrue($scan->reaches('src/routes/regex/+page.svelte', 'src/lib/format.ts#load'));
        // lang="typescript" is TypeScript, so its type errors are reported.
        self::assertSame(['src/routes/comment/+page.svelte'], $scan->diagnosticPaths('TS2322'));
    }

    public function testAnAstroSitesFrontmatterMarkupAndScriptsBecomeFacts(): void
    {
        $scan = $this->scanFixture('astro', ['tsconfig.json']);

        self::assertTrue($scan->imports('src/pages/index.astro', 'src/components/Card.astro'));
        self::assertTrue($scan->reaches('src/pages/index.astro', 'src/lib/site.ts#title'));
        // A bundled client script imports what it names; an inline one is not read.
        self::assertTrue($scan->reaches('src/pages/index.astro', 'src/lib/site.ts#boot'));
        // Astro reads a component's Props by name.
        self::assertTrue($scan->reaches('src/components/Card.astro', 'src/components/Card.astro#Props'));
        self::assertTrue($scan->reaches('src/components/Tag.astro', 'src/components/Tag.astro#Props'));
        // What Astro's own compiler makes moot is not reported.
        foreach (['TS1108', 'TS2708', 'TS2300', 'TS2451'] as $code) {
            self::assertSame([], $scan->diagnosticPaths($code), $code);
        }
        self::assertSame(['src/pages/broken.astro'], $scan->diagnosticPaths('COMPONENT_UNPARSED'));
        // `Astro.props` is the component's Props, as Astro types it: its
        // fields are not typed from their destructuring defaults.
        self::assertSame([], $scan->diagnosticCodes('src/components/Pager.astro'));
    }

    /**
     * @param list<string> $configFiles
     * @param array<string, mixed> $extra
     */
    private function scanFixture(string $name, array $configFiles, array $extra = []): ComponentScan
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/components/' . $name;
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr((string) $file, strlen($root) + 1);
            if (preg_match('/\.(?:[cm]?[jt]s|vue|svelte|astro)$/', $relative) === 1) {
                $files[] = $relative;
            }
        }
        sort($files, SORT_STRING);
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files, 'config_files' => $configFiles, ...$extra]), false);
        } finally {
            $client->shutdown();
        }

        return new ComponentScan($contributions);
    }
}

/** The facts of one worker scan, asked the questions the tests above ask. */
final readonly class ComponentScan
{
    /** @param list<ScanContribution> $contributions */
    public function __construct(private array $contributions) {}

    /** Whether `file` has an edge other than `contains` to a target whose reference ends with `target`. */
    public function reaches(string $file, string $target): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->kind !== 'contains' && str_contains($edge->sourceReference, $file) && str_ends_with($edge->targetReference, ':' . $target)) {
                return true;
            }
        }

        return false;
    }

    /** Whether `file`'s module has a reference to a member named `member` of its own. */
    public function reachesMember(string $file, string $member): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->kind === 'references' && $edge->sourceReference === 'ts:module:' . $file && str_ends_with($edge->targetReference, '::' . $member)) {
                return true;
            }
        }

        return false;
    }

    /** Whether `from`, or a declaration in it, imports `to`'s module. */
    public function imports(string $from, string $to): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->kind === 'imports' && self::inFile($edge->sourceReference, $from) && $edge->targetReference === 'ts:module:' . $to) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the directories `file`'s `require.context` calls name, sorted */
    public function contextDirectories(string $file): array
    {
        $directories = [];
        foreach ($this->edges() as $edge) {
            if ($edge->sourceReference === 'ts:module:' . $file && str_starts_with($edge->targetReference, 'ts:module_context:')) {
                $directories[] = (string) json_decode(substr($edge->targetReference, strlen('ts:module_context:')), true)['directory'];
            }
        }
        sort($directories, SORT_STRING);

        return $directories;
    }

    /** @return list<string> display names of the nodes in `file` marked as called by the runtime, sorted */
    public function runtimeInvoked(string $file): array
    {
        $names = [];
        foreach ($this->nodes() as $node) {
            if ($node->evidence->relativePath === $file && ($node->attributes['runtime_invoked'] ?? false) === true) {
                $names[] = $node->displayName;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<string> */
    public function canonicalNames(): array
    {
        return array_map(static fn($node): string => $node->canonicalName, $this->nodes());
    }

    /** @return list<string> */
    public function moduleNames(): array
    {
        return array_values(array_map(
            static fn($node): string => $node->canonicalName,
            array_filter($this->nodes(), static fn($node): bool => $node->kind === 'module'),
        ));
    }

    /** @return list<string> the paths carrying a diagnostic with `code`, sorted and unique */
    public function diagnosticPaths(string $code): array
    {
        $paths = [];
        foreach ($this->contributions as $contribution) {
            foreach ($contribution->diagnostics as $diagnostic) {
                if ($diagnostic->code === $code) {
                    $paths[str_replace('knossos.typescript:file:', '', $contribution->ownerKey)] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<string> the diagnostic codes reported on `file`, sorted */
    public function diagnosticCodes(string $file): array
    {
        $codes = [];
        foreach ($this->contributions as $contribution) {
            if ($contribution->ownerKey === 'knossos.typescript:file:' . $file) {
                foreach ($contribution->diagnostics as $diagnostic) {
                    $codes[] = $diagnostic->code;
                }
            }
        }
        sort($codes, SORT_STRING);

        return $codes;
    }

    /** Whether a reference names `file`'s module or a declaration in it. */
    private static function inFile(string $reference, string $file): bool
    {
        return $reference === 'ts:module:' . $file || str_contains($reference, ':' . $file . '#');
    }

    /** @return list<\Knossos\Scanner\Protocol\EdgeFact> */
    private function edges(): array
    {
        return array_merge(...array_map(static fn(ScanContribution $c): array => $c->edges, $this->contributions));
    }

    /** @return list<\Knossos\Scanner\Protocol\NodeFact> */
    private function nodes(): array
    {
        return array_merge(...array_map(static fn(ScanContribution $c): array => $c->nodes, $this->contributions));
    }
}
