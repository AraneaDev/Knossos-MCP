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
 */
final readonly class ManifestEntryPointRule implements ClassificationRule
{
    public const ROLE = 'application.entry_point';

    /** @var array<string, true> */
    private array $entryPoints;

    /** @param list<string> $entryPoints Project-relative paths named by a manifest. */
    public function __construct(array $entryPoints)
    {
        $this->entryPoints = array_fill_keys($entryPoints, true);
    }

    public function id(): string
    {
        return 'core.manifest.entrypoints.v1';
    }

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
                ['matched_path' => $path],
            ),
        ];
    }
}
