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
     * Every project-relative path the discovered manifests name as a binary,
     * an entry module, or a script.
     *
     * @return list<string>
     */
    private static function manifestEntryPoints(ScanPlan $plan): array
    {
        $paths = [];
        foreach ($plan->preparation->discovery->units as $unit) {
            foreach ($unit->metadata['entry_points'] ?? [] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }
}
