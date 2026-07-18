<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Boundary\BoundaryInference;
use Knossos\Classification\{
    ClassificationEngine,
    LaravelPathRoleRule,
    LaravelRoleRule,
    NameSuffixRule,
    NestJsRoleRule,
    PythonFrameworkRoleRule,
    SymfonyRoleRule,
    TypeScriptFrameworkRoleRule
};
use Knossos\Scanner\Protocol\Confidence;

final readonly class ScanAnalysisPipeline
{
    /** @param list<object> $contributions */
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
}
