<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags what a library publishes to consumers outside the repository.
 *
 * A package that is not private and names an entry (`main`, `exports`) is a
 * library, and what its entry re-exports is API: nothing in the repository
 * has to call it for it to be wanted, so an unused method of a published
 * class read as dead. The published set is worked out from the scan's
 * re-export edges before classification (see ScanAnalysisPipeline); this rule
 * tags exported declarations whose names are in it, and the members of the
 * published classes. What the entry does not publish stays reportable.
 */
final readonly class LibraryPublicApiRule implements ClassificationRule
{
    public const ROLE = 'library.public_api';

    /**
     * @param array<string, true|array<string, true>> $published file path => every exported name (true) or the names published
     * @param array<string, true> $exported canonical names of exported declarations
     */
    public function __construct(private array $published, private array $exported) {}

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'library.public_api.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        $names = $this->published[$node->evidence->relativePath] ?? null;
        if ($names === null) {
            return [];
        }
        $owner = str_contains($node->canonicalName, '::')
            ? substr($node->canonicalName, 0, (int) strpos($node->canonicalName, '::'))
            : $node->canonicalName;
        if (!isset($this->exported[$owner])) {
            return [];
        }
        $ownerName = substr($owner, (int) strrpos($owner, '#') + 1);
        if ($names !== true && !isset($names[$ownerName])) {
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
                ['published_as' => $ownerName],
            ),
        ];
    }
}
