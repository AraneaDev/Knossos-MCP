<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags the modules a site framework loads by where they sit.
 *
 * Astro serves `src/pages/**` endpoints and runs `src/middleware`; VitePress
 * runs `*.data.*` loaders and `*.paths.*` dynamic-route generators. Nothing
 * imports any of them, so each carried an in-degree of zero while running on
 * every build or request. The positions only mean this inside an app whose
 * config file is there, so the rule is built with those app roots and says
 * nothing about paths outside them.
 */
final readonly class FrameworkFileConventionRule implements ClassificationRule
{
    /**
     * @param list<string> $appRoots project-relative app directories, `''` for the root
     * @param list<string> $patterns regexes over a path relative to an app root
     */
    private function __construct(private string $framework, private array $appRoots, private array $patterns) {}

    /**
     * Astro's endpoints, middleware and content configuration.
     *
     * @param list<string> $appRoots directories holding an `astro.config.*`
     */
    public static function astro(array $appRoots): self
    {
        return new self('astro', $appRoots, [
            '#^src/pages/.+\.[cm]?[jt]s$#',
            '#^src/pages/.+\.astro$#',
            '#^src/middleware(?:/index)?\.[cm]?[jt]s$#',
            '#^src/content(?:/config|\.config)\.[cm]?[jt]s$#',
        ]);
    }

    /**
     * VitePress's data loaders and dynamic-route path generators.
     *
     * @param list<string> $appRoots directories holding a `.vitepress/config.*`
     */
    public static function vitePress(array $appRoots): self
    {
        return new self('vitepress', $appRoots, [
            '#(?:^|/)[^/]+\.data\.[cm]?[jt]s$#',
            '#(?:^|/)[^/]+\.paths\.[cm]?[jt]s$#',
        ]);
    }

    /** {@inheritDoc} */
    public function id(): string
    {
        return $this->framework . '.conventions.v1';
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
            $relative = substr($path, strlen($prefix));
            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern, $relative) === 1) {
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
            }
        }

        return [];
    }
}
