<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

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
    ];

    public function id(): string
    {
        return 'laravel.paths.v1';
    }

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
