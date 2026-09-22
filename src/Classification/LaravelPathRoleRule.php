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
        '/Http/Middleware/' => 'laravel.middleware',
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
    public function classify(NodeFact $node): array
    {
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
}
