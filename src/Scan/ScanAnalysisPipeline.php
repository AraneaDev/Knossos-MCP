<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Boundary\BoundaryInference;
use Knossos\Classification\{
    ClassificationEngine,
    FrameworkFileConventionRule,
    LaravelPathRoleRule,
    LaravelRoleRule,
    LibraryPublicApiRule,
    ManifestEntryPointRule,
    ManifestLibraryApiRule,
    NameSuffixRule,
    NestJsRoleRule,
    PythonFrameworkRoleRule,
    RustFrameworkRoleRule,
    SymfonyRoleRule,
    TestModuleRule,
    ToolConfigModuleRule,
    TypeScriptFrameworkRoleRule
};
use Knossos\Scanner\Protocol\Confidence;

/**
 * Derives roles and boundaries from facts the workers reported.
 *
 * Runs after reconciliation because it reasons over the whole graph — a role rule
 * may depend on a class's inheritance, which is only known once every file's facts
 * are present.
 */
final readonly class ScanAnalysisPipeline
{
    /**
     * Derive roles and boundaries once the whole graph is present.
     *
     * @param list<object> $contributions
     */
    public function analyze(ScanPlan $plan, array $contributions): ScanAnalysis
    {
        $rules = [
            new NameSuffixRule('core.naming.roles.v1', [
                'Controller' => 'application.controller', 'Service' => 'application.service',
                'Repository' => 'persistence.repository', 'Middleware' => 'application.middleware',
                'Listener' => 'messaging.listener', 'Event' => 'messaging.event', 'Job' => 'messaging.job',
                'Command' => 'application.command',
            ]),
            new NestJsRoleRule(),
            new PythonFrameworkRoleRule(),
            new RustFrameworkRoleRule(),
            new TypeScriptFrameworkRoleRule(),
            new TestModuleRule(),
            new ToolConfigModuleRule(),
            new ManifestEntryPointRule(self::manifestEntryPoints($plan)),
            FrameworkFileConventionRule::svelteKit(self::svelteKitRoots($plan)),
            new LibraryPublicApiRule(...self::publishedApi($plan, $contributions)),
            new ManifestLibraryApiRule(self::libraryRoots($plan, 'composer'), self::libraryRoots($plan, 'python')),
            FrameworkFileConventionRule::astro(self::appRoots($plan, '#(?:^|/)astro\\.config\\.[cm]?[jt]s$#', 0)),
            FrameworkFileConventionRule::vitePress(self::appRoots($plan, '#(?:^|/)\\.vitepress/config\\.[cm]?[jt]s$#', 1)),
        ];
        if ($plan->preparation->laravel) {
            $rules[] = new LaravelRoleRule();
            $rules[] = new LaravelPathRoleRule();
            $rules[] = new NameSuffixRule('laravel.naming.roles.v1', [
                'Controller' => 'laravel.controller', 'Command' => 'laravel.command', 'Job' => 'laravel.job',
                'Event' => 'laravel.event', 'Listener' => 'laravel.listener', 'Middleware' => 'laravel.middleware',
                'Provider' => 'laravel.provider', 'Policy' => 'laravel.policy', 'Repository' => 'laravel.repository',
            ], confidence: Confidence::Possible);
        }
        if ($plan->preparation->symfony) {
            $rules[] = new SymfonyRoleRule();
        }
        return new ScanAnalysis(
            (new ClassificationEngine($rules))->classify($contributions),
            (new BoundaryInference())->infer(
                $plan->preparation->discovery->units,
                $contributions,
                $plan->preparation->explicitBoundaries,
            ),
        );
    }

    /**
     * Every project-relative path the discovered manifests name as a binary, an
     * entry module, a script, or a reference from a non-code file, mapped to
     * the manifest that named it.
     *
     * The claimant travels with the path because the role it produces
     * SUPPRESSES a dead-code candidate. Without it, a maintainer looking at a
     * file nothing appears to use has no way to find out which of the
     * project's manifests, HTML shells, YAML files or tool configs spoke for
     * it.
     *
     * First claimant wins, and units arrive sorted by kind and path, so the
     * attribution is deterministic across scans of the same tree.
     *
     * @return array<string, string> entry-point path => path of the file naming it
     */
    private static function manifestEntryPoints(ScanPlan $plan): array
    {
        $paths = [];
        foreach ($plan->preparation->discovery->units as $unit) {
            foreach ($unit->metadata['entry_points'] ?? [] as $path) {
                if (is_string($path) && $path !== '' && !isset($paths[$path])) {
                    $paths[$path] = $unit->configPath;
                }
            }
        }
        ksort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * The directories holding a `svelte.config.*`, which is what makes their
     * `src/routes` and `src/hooks.*` SvelteKit's rather than ordinary modules.
     *
     * @return list<string>
     */
    private static function svelteKitRoots(ScanPlan $plan): array
    {
        $roots = [];
        foreach ($plan->preparation->discovery->files as $file) {
            if (preg_match('#(?:^|/)svelte\.config\.[cm]?[jt]s$#', $file->relativePath) === 1) {
                $directory = dirname($file->relativePath);
                $roots[$directory === '.' ? '' : $directory] = true;
            }
        }
        $roots = array_keys($roots);
        sort($roots, SORT_STRING);

        return array_map(strval(...), $roots);
    }

    /**
     * The app directories a framework's config file marks: the directory
     * holding a matching file, `$up` levels above it (`.vitepress/config.ts`
     * sits one below the site).
     *
     * @return list<string>
     */
    private static function appRoots(ScanPlan $plan, string $configPattern, int $up): array
    {
        $roots = [];
        foreach ($plan->preparation->discovery->files as $file) {
            if (preg_match($configPattern, $file->relativePath) === 1) {
                $directory = dirname($file->relativePath, $up + 1);
                $roots[$directory === '.' ? '' : $directory] = true;
            }
        }
        $roots = array_map(strval(...), array_keys($roots));
        sort($roots, SORT_STRING);

        return $roots;
    }

    /**
     * The directories the `$kind` manifests of the project publish as a library.
     *
     * @return list<string>
     */
    private static function libraryRoots(ScanPlan $plan, string $kind): array
    {
        $roots = [];
        foreach ($plan->preparation->discovery->units as $unit) {
            if ($unit->kind !== $kind) {
                continue;
            }
            foreach ($unit->metadata['library_roots'] ?? [] as $root) {
                if (is_string($root)) {
                    $roots[] = $root;
                }
            }
        }

        return $roots;
    }

    /**
     * What each file publishes as a library's API, and which declarations are exported.
     *
     * Starts from every non-private package's public entries, each of which
     * publishes all it exports, and follows re-export edges: a named
     * re-export passes on its names, `export *` passes on what its source
     * publishes. Returns the arguments of {@see LibraryPublicApiRule}.
     *
     * @param list<object> $contributions
     * @return array{0: array<string, true|array<string, true>>, 1: array<string, true>}
     */
    private static function publishedApi(ScanPlan $plan, array $contributions): array
    {
        $published = [];
        foreach ($plan->preparation->discovery->units as $unit) {
            foreach ($unit->metadata['public_entry_points'] ?? [] as $path) {
                if (is_string($path)) {
                    $published[$path] = true;
                }
            }
        }
        if ($published === []) {
            return [[], []];
        }
        $reExports = [];
        $exported = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes ?? [] as $node) {
                if (($node->attributes['exported'] ?? false) === true || ($node->attributes['default'] ?? false) === true) {
                    $exported[$node->canonicalName] = true;
                }
            }
            foreach ($contribution->edges ?? [] as $edge) {
                if ($edge->kind !== 're_exports'
                    || !str_starts_with($edge->sourceReference, 'ts:module:')
                    || !str_starts_with($edge->targetReference, 'ts:module:')) {
                    continue;
                }
                $names = is_array($edge->attributes['names'] ?? null) ? $edge->attributes['names'] : null;
                $reExports[substr($edge->sourceReference, 10)][] = [substr($edge->targetReference, 10), $names];
            }
        }
        $pending = array_keys($published);
        while ($pending !== []) {
            $source = array_pop($pending);
            $spec = $published[$source];
            foreach ($reExports[$source] ?? [] as [$target, $names]) {
                if ($names === null) {
                    $passed = $spec;
                } else {
                    $passed = [];
                    foreach ($names as $name) {
                        if (is_string($name) && ($spec === true || isset($spec[$name]))) {
                            $passed[$name] = true;
                        }
                    }
                }
                $before = $published[$target] ?? null;
                $after = $before === true || $passed === true ? true : [...($before ?? []), ...$passed];
                if ($after !== $before && $after !== []) {
                    $published[$target] = $after;
                    $pending[] = $target;
                }
            }
        }

        return [$published, $exported];
    }
}
