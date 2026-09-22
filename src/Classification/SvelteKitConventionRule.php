<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags the modules SvelteKit loads by where they sit.
 *
 * Route modules (`+page`, `+layout`, their `.server` variants, `+server`)
 * under `src/routes/`, the hooks files, param matchers in `src/params/` and
 * the service worker are entered by the framework and imported by nothing, so
 * each carried an in-degree of zero while serving every request.
 *
 * The positions only mean this inside a SvelteKit app, which is a directory
 * holding a `svelte.config.*`. Outside one, `src/hooks.ts` is as likely to be
 * a module of React hooks as anything the framework loads, so the rule needs
 * the app roots and says nothing about paths outside them.
 */
final readonly class SvelteKitConventionRule implements ClassificationRule
{
    /** @param list<string> $appRoots project-relative directories holding a `svelte.config.*`, `''` for the root */
    public function __construct(private array $appRoots) {}

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'sveltekit.conventions.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        $path = $node->evidence->relativePath;
        foreach ($this->appRoots as $root) {
            $prefix = $root === '' ? '' : $root . '/';
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            if (!self::isConventionPath(substr($path, strlen($prefix)))) {
                continue;
            }

            return [
                new ClassificationFact(
                    $node->localId,
                    ManifestEntryPointRule::ROLE,
                    $this->id(),
                    Origin::Derived,
                    Confidence::Probable,
                    $node->evidence,
                    ['matched_path' => $path, 'app_root' => $root],
                ),
            ];
        }

        return [];
    }

    /** Whether a path relative to a SvelteKit app root is one the framework loads by position. */
    private static function isConventionPath(string $path): bool
    {
        return preg_match('#^src/routes/(?:.+/)?\+(?:page|layout)(?:\.server)?\.[cm]?[jt]s$#', $path) === 1
            || preg_match('#^src/routes/(?:.+/)?\+server\.[cm]?[jt]s$#', $path) === 1
            || preg_match('#^src/hooks(?:\.server|\.client)?\.[cm]?[jt]s$#', $path) === 1
            || preg_match('#^src/params/[^/]+\.[cm]?[jt]s$#', $path) === 1
            || preg_match('#^src/service-worker(?:/index)?\.[cm]?[jt]s$#', $path) === 1;
    }
}
