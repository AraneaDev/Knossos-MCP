<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/** Infers roles from Laravel's conventional directory layout — app/Http/Controllers and friends. */
final readonly class LaravelPathRoleRule implements ClassificationRule
{
    private const PATH_ROLES = [
        '/Http/Controllers/' => 'laravel.controller',
        // A module keeps its own `Middleware` directory beside `Http/Middleware`.
        '/Middleware/' => 'laravel.middleware',
        '/Console/Commands/' => 'laravel.command',
        '/Jobs/' => 'laravel.job',
        '/Events/' => 'laravel.event',
        '/Listeners/' => 'laravel.listener',
        '/Providers/' => 'laravel.provider',
        '/Policies/' => 'laravel.policy',
        '/Models/' => 'laravel.model',
        '/Repositories/' => 'laravel.repository',
        // Run by `artisan migrate`, `db:seed` and model factories, which find
        // them by directory; nothing references them by name.
        '/database/migrations/' => 'laravel.migration',
        '/database/seeders/' => 'laravel.seeder',
        '/database/seeds/' => 'laravel.seeder',
        '/database/factories/' => 'laravel.factory',
    ];

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'laravel.paths.v1';
    }

    /** {@inheritDoc} */
    /** Class role => the methods Laravel itself calls on such a class. */
    private const ENTRY_METHODS = [
        'laravel.middleware' => ['handle', 'terminate'],
        'laravel.job' => ['handle', 'failed', 'middleware', 'retryUntil', 'backoff', '__invoke'],
        'laravel.listener' => ['handle', 'failed', 'shouldQueue', '__invoke'],
        'laravel.command' => ['handle', '__invoke'],
    ];

    public function classify(NodeFact $node): array
    {
        if ($node->kind === 'method') {
            return $this->entryMethod($node);
        }
        if ($node->kind !== 'class') {
            return [];
        }
        $path = '/' . ltrim($node->evidence->relativePath, '/');
        foreach (self::PATH_ROLES as $fragment => $role) {
            if (str_contains($path, $fragment)) {
                return [new ClassificationFact(
                    $node->localId,
                    $role,
                    $this->id(),
                    Origin::FrameworkConvention,
                    Confidence::Probable,
                    $node->evidence,
                    ['matched_path' => $fragment],
                )];
            }
        }
        return [];
    }

    /**
     * The method the framework calls on a middleware, job, listener or command.
     *
     * The class is a convention; its entry method is named by nothing in the
     * project either, so it gets a convention role of its own.
     *
     * @return list<ClassificationFact>
     */
    private function entryMethod(NodeFact $node): array
    {
        $path = '/' . ltrim($node->evidence->relativePath, '/');
        foreach (self::PATH_ROLES as $fragment => $role) {
            if (!str_contains($path, $fragment)) {
                continue;
            }
            if (!in_array($node->displayName, self::ENTRY_METHODS[$role] ?? [], true)) {
                return [];
            }

            return [new ClassificationFact(
                $node->localId,
                'laravel.entry_method',
                $this->id(),
                Origin::FrameworkConvention,
                Confidence::Probable,
                $node->evidence,
                ['matched_path' => $fragment, 'class_role' => $role],
            )];
        }

        return [];
    }
}
