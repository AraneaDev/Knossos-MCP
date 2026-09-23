<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags the public API of a PHP or Python library its manifest publishes.
 *
 * A Composer `library` and a buildable Python package that installs no
 * command are installed by code outside the repository, which calls what
 * they make public; nothing inside has to, so an unused public method read
 * as dead. Discovery names the directories they publish (see
 * ProjectDiscoverer::composerLibraryRoots() and pythonLibraryRoots()); this
 * tags what is public there, with the role {@see LibraryPublicApiRule} gives
 * a JavaScript package's exports. What a library keeps private stays
 * reportable.
 */
final readonly class ManifestLibraryApiRule implements ClassificationRule
{
    /**
     * @param list<string> $phpRoots project-relative directories, `''` for the root
     * @param list<string> $pythonRoots project-relative directories, `''` for the root
     */
    public function __construct(private array $phpRoots, private array $pythonRoots) {}

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'library.manifest_api.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        $path = $node->evidence->relativePath;
        $public = match (true) {
            str_starts_with($node->localId, 'php:') && self::under($path, $this->phpRoots) => self::phpPublic($node),
            str_starts_with($node->localId, 'py:') && self::under($path, $this->pythonRoots) => self::pythonPublic($node),
            default => false,
        };
        // A library's own tests sit beside it and publish nothing.
        if (!$public || (new TestModuleRule())->classify($node) !== []) {
            return [];
        }

        return [
            new ClassificationFact(
                $node->localId,
                LibraryPublicApiRule::ROLE,
                $this->id(),
                Origin::Derived,
                Confidence::Probable,
                $node->evidence,
                ['published_by' => 'manifest'],
            ),
        ];
    }

    /**
     * Whether `$path` lies inside one of `$roots`, `''` being the whole project.
     *
     * @param list<string> $roots
     */
    private static function under(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($root === '' || str_starts_with($path, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A type, or a method a consumer can call or override. */
    private static function phpPublic(NodeFact $node): bool
    {
        return match ($node->kind) {
            'class', 'interface', 'trait', 'enum' => true,
            'method' => in_array($node->attributes['visibility'] ?? 'public', ['public', 'protected'], true),
            default => false,
        };
    }

    /**
     * A module, class, function or method no segment of whose name is
     * private by Python's convention: a leading underscore, other than a
     * dunder, which the language itself calls.
     */
    private static function pythonPublic(NodeFact $node): bool
    {
        if (!in_array($node->kind, ['module', 'class', 'function', 'method'], true)) {
            return false;
        }
        foreach (preg_split('/::|\./', $node->canonicalName) ?: [] as $segment) {
            if (str_starts_with($segment, '_') && !(str_starts_with($segment, '__') && str_ends_with($segment, '__'))) {
                return false;
            }
        }

        return true;
    }
}
