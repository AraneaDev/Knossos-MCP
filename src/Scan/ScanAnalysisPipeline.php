<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Boundary\BoundaryInference;
use Knossos\Classification\{
    ClassificationEngine,
    LaravelPathRoleRule,
    LaravelRoleRule,
    ManifestEntryPointRule,
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
}
