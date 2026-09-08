<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags files a package manifest names as its binaries, entry modules, or
 * scripts.
 *
 * `npm run build` invokes `scripts/build.mjs` by name and Composer invokes
 * `bin/console` the same way; nothing in the project imports either, so both
 * carry an in-degree of zero however central they are. A self-scan of an
 * 111-file TypeScript project reported five such scripts as unreferenced code.
 *
 * The paths come from discovery, which is the only stage that reads the
 * manifests. Matching is by exact project-relative path: a manifest token that
 * names something no scanner emitted simply never matches, which is what keeps
 * the loose shell-command tokenising in {@see \Knossos\Discovery\ProjectDiscoverer}
 * from turning into false positives here.
 *
 * The role this rule produces suppresses a dead-code candidate, and HTML
 * shells, YAML files and tool configs widened the set of manifests that can
 * claim one, so each entry point is stored alongside the path of the file
 * that named it and carried into the emitted fact. Without that, a maintainer
 * looking at a file nothing appears to use would have no way to find out
 * which of the project's manifests spoke for it.
 */
final readonly class ManifestEntryPointRule implements ClassificationRule
{
    public const ROLE = 'application.entry_point';

    /** @var array<string, string> entry-point path => path of the file naming it */
    private array $entryPoints;

    /** @param array<string, string> $entryPoints Project-relative paths named by a manifest, mapped to the manifest naming each. */
    public function __construct(array $entryPoints)
    {
        $this->entryPoints = $entryPoints;
    }

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'core.manifest.entrypoints.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        // Every declaration inside an entry-point file is reached the same way
        // the file is, so the role is keyed on the file it came from.
        $path = $node->evidence->relativePath;
        if (!isset($this->entryPoints[$path])) {
            return [];
        }

        return [
            new ClassificationFact(
                $node->localId,
                self::ROLE,
                $this->id(),
                Origin::Derived,
                Confidence::Probable,
                $node->evidence,
                ['matched_path' => $path, 'named_by' => $this->entryPoints[$path]],
            ),
        ];
    }
}
