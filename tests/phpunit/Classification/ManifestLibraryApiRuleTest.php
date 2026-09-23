<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\LibraryPublicApiRule;
use Knossos\Classification\ManifestLibraryApiRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** What a manifest publishes as a PHP or Python library's API. */
#[Group('classification')]
final class ManifestLibraryApiRuleTest extends TestCase
{
    public function testPhpTypesAndTheirCallableMethodsArePublished(): void
    {
        $rule = new ManifestLibraryApiRule(['sdk/src'], []);

        foreach (['class', 'interface', 'trait', 'enum'] as $kind) {
            self::assertTrue($this->published($rule, $this->node('php', $kind, 'Sdk\\Thing', 'sdk/src/Thing.php')), $kind);
        }
        self::assertTrue($this->published($rule, $this->node('php', 'method', 'Sdk\\Thing::run', 'sdk/src/Thing.php', ['visibility' => 'protected'])));
        self::assertFalse($this->published($rule, $this->node('php', 'method', 'Sdk\\Thing::keep', 'sdk/src/Thing.php', ['visibility' => 'private'])));
        self::assertFalse($this->published($rule, $this->node('php', 'function', 'Sdk\\helper', 'sdk/src/helpers.php')));
        // Outside the published directory, and in a sibling that only shares its prefix.
        self::assertFalse($this->published($rule, $this->node('php', 'class', 'App\\Thing', 'app/Thing.php')));
        self::assertFalse($this->published($rule, $this->node('php', 'class', 'Sdk\\Other', 'sdk/src2/Other.php')));
    }

    public function testPythonNamesArePublishedUnlessPrivateByConvention(): void
    {
        $rule = new ManifestLibraryApiRule([], ['']);

        self::assertTrue($this->published($rule, $this->node('py', 'module', 'pylib.core', 'pylib/core.py')));
        self::assertTrue($this->published($rule, $this->node('py', 'method', 'pylib.core.Api::__call__', 'pylib/core.py')));
        self::assertFalse($this->published($rule, $this->node('py', 'function', 'pylib.core._helper', 'pylib/core.py')));
        self::assertFalse($this->published($rule, $this->node('py', 'class', 'pylib._impl.Api', 'pylib/_impl.py')));
        self::assertFalse($this->published($rule, $this->node('py', 'variable', 'pylib.core.VALUE', 'pylib/core.py')));
        // A library's own tests publish nothing, and another language is not its business.
        self::assertFalse($this->published($rule, $this->node('py', 'function', 'tests.test_core.test_call', 'tests/test_core.py')));
        self::assertFalse($this->published($rule, $this->node('ts', 'function', 'src/a.ts#f', 'src/a.ts')));
    }

    public function testTheFactCarriesTheLibraryRole(): void
    {
        $facts = (new ManifestLibraryApiRule([''], []))->classify($this->node('php', 'class', 'Sdk\\Thing', 'src/Thing.php'));

        self::assertCount(1, $facts);
        self::assertSame(LibraryPublicApiRule::ROLE, $facts[0]->role);
        self::assertSame('library.manifest_api.v1', $facts[0]->ruleId);
    }

    private function published(ManifestLibraryApiRule $rule, NodeFact $node): bool
    {
        return $rule->classify($node) !== [];
    }

    /** @param array<string, mixed> $attributes */
    private function node(string $language, string $kind, string $canonical, string $path, array $attributes = []): NodeFact
    {
        return new NodeFact(
            $language . ':' . $kind . ':' . $canonical,
            $kind,
            $canonical,
            $canonical,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($path, 1, 10),
            $attributes,
        );
    }
}
