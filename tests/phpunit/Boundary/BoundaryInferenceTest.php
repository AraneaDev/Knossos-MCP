<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Boundary;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryFact;
use Knossos\Boundary\BoundaryInference;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('boundary-inference')]
final class BoundaryInferenceTest extends TestCase
{
    public function testInferWithEmptyInputsReturnsEmptyFacts(): void
    {
        $facts = (new BoundaryInference())->infer([], [], []);

        assertSame([], $facts);
    }

    public function testInferWithSingleComposerUnitCreatesComposerBoundary(): void
    {
        $units = [$this->makeUnit('composer', 'composer.json', ['name' => 'vendor/app'])];
        $contributions = [];

        $facts = (new BoundaryInference())->infer($units, $contributions, []);

        assertSame(1, count($facts));
        // Inferred rules carry the kind prefix in the array key; the BoundaryFact name
        // ($rule['display'] ?? $name) falls back to that key.
        assertSame('composer:vendor/app', $facts[0]->name);
        assertSame('inferred', $facts[0]->source);
        assertSame(['type' => 'path_prefix', 'value' => ''], $facts[0]->matcher);
        assertSame([], $facts[0]->nodeReferences);
        // Unmerged inferred boundaries leave identityName null: the stable id derives
        // from $name directly (no suffix to strip).
        assertSame(null, $facts[0]->identityName);
    }

    public function testInferWithSingleNodeUnitCreatesNodeBoundary(): void
    {
        $units = [$this->makeUnit('node', 'package.json', ['name' => 'web-app'])];
        $contributions = [];

        $facts = (new BoundaryInference())->infer($units, $contributions, []);

        assertSame(1, count($facts));
        assertSame('node:web-app', $facts[0]->name);
        assertSame(['type' => 'path_prefix', 'value' => ''], $facts[0]->matcher);
    }

    public function testInferWithSingleTypescriptUnitCreatesTypescriptBoundary(): void
    {
        $units = [$this->makeUnit('typescript', 'tsconfig.json', [])];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame(1, count($facts));
        assertSame('typescript:tsconfig.json', $facts[0]->name);
        assertSame(['type' => 'path_prefix', 'value' => ''], $facts[0]->matcher);
    }

    public function testInferWithSinglePythonUnitCreatesPythonBoundary(): void
    {
        $units = [$this->makeUnit('python', 'pyproject.toml', ['name' => 'core-lib'])];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame(1, count($facts));
        assertSame('python:core-lib', $facts[0]->name);
    }

    public function testInferWithUnknownUnitKindProducesNoBoundary(): void
    {
        $units = [$this->makeUnit('mystery', 'config.yml', [])];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame([], $facts);
    }

    public function testInferComposerUnitFallsBackToRootLabelWhenNameMissing(): void
    {
        $units = [$this->makeUnit('composer', 'composer.json', [])];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame(1, count($facts));
        assertSame('composer:root', $facts[0]->name);
        assertSame(['type' => 'path_prefix', 'value' => ''], $facts[0]->matcher);
    }

    public function testInferWithPhpNamespaceNodeCreatesNamespaceBoundary(): void
    {
        $node = $this->makeNode('php:class:App\\Checkout\\Service', 'App\\Checkout\\Service', 'src/Checkout/Service.php');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $namespaces = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'namespace:')));
        assertSame(1, count($namespaces));
        assertSame('namespace:App', $namespaces[0]->name);
        // Source produces matcher value "App" . "\\" = "App\" (4 chars).
        assertSame(['type' => 'namespace_prefix', 'value' => "App\\"], $namespaces[0]->matcher);
        assertSame(['php:class:App\\Checkout\\Service'], $namespaces[0]->nodeReferences);
    }

    public function testInferWithPhpRootNamespaceNodeCreatesNoNamespaceBoundary(): void
    {
        $node = $this->makeNode('php:class:GlobalClass', 'GlobalClass', 'src/Global.php');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $namespaces = array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'namespace:'));
        assertSame([], $namespaces);
    }

    public function testInferWithTypescriptModuleNodeCreatesModuleBoundary(): void
    {
        $node = $this->makeNode('ts:class:packages/app/src/index.ts#Index', 'packages/app/src/index.ts#Index', 'packages/app/src/index.ts');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $modules = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'module:')));
        assertSame(1, count($modules));
        $module = $modules[0];
        assertSame('module:packages', $module->name);
        assertSame(['type' => 'path_prefix', 'value' => 'packages/'], $module->matcher);
    }

    public function testInferWithTypescriptFlatNodeCreatesNoModuleBoundary(): void
    {
        $node = $this->makeNode('ts:class:single.ts#Foo', 'single.ts#Foo', 'single.ts');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $modules = array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'module:'));
        assertSame([], $modules);
    }

    public function testInferWithPythonPackageNodeCreatesPythonPackageBoundary(): void
    {
        $node = $this->makeNode('py:class:shop/api.py#create_order', 'shop/api.py#create_order', 'shop/api.py');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $pkgs = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'python-package:')));
        assertSame(1, count($pkgs));
        $pkg = $pkgs[0];
        assertSame('python-package:shop', $pkg->name);
        assertSame(['py:class:shop/api.py#create_order'], $pkg->nodeReferences);
    }

    public function testInferWithPythonFlatNodeCreatesNoPackageBoundary(): void
    {
        $node = $this->makeNode('py:class:plain.py#Foo', 'plain.py#Foo', 'plain.py');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $pkgs = array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'python-package:'));
        assertSame([], $pkgs);
    }

    public function testInferWithJavaLanguageNodeCreatesNoExplicitRule(): void
    {
        $node = $this->makeNode('java:class:Example', 'Example', 'src/Example.java');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        assertSame([], $facts);
    }

    public function testInferWithExplicitPathPrefixCreatesExplicitBoundary(): void
    {
        $explicit = [['name' => 'payments', 'path_prefix' => 'src/payments/']];

        $facts = (new BoundaryInference())->infer([], [], $explicit);

        // Explicit rules carry 'display' = $rule['name'], so BoundaryFact name uses that
        // fallback (display key wins over the 'explicit:' keyed name).
        $exact = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'payments'));
        assertSame(1, count($exact));
        assertSame('explicit', $exact[0]->source);
        assertSame(['type' => 'path_prefix', 'value' => 'src/payments/'], $exact[0]->matcher);
    }

    public function testInferWithExplicitNamespacePrefixCreatesExplicitBoundary(): void
    {
        $explicit = [['name' => 'checkout', 'namespace_prefix' => "App\\Checkout\\"]];

        $facts = (new BoundaryInference())->infer([], [], $explicit);

        $exact = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'checkout'));
        assertSame(1, count($exact));
        // Source applies ltrim(..., '\\') to the namespace_prefix, removing leading backslashes.
        assertSame(['type' => 'namespace_prefix', 'value' => "App\\Checkout\\"], $exact[0]->matcher);
    }

    public function testInferWithExplicitBoundaryWithoutPrefixThrows(): void
    {
        $explicit = [['name' => 'orphan']];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testInferWithExplicitBoundaryWithoutNameThrows(): void
    {
        $explicit = [['path_prefix' => 'src/']];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testInferWithExplicitBoundaryThatIsNotAnArrayThrows(): void
    {
        $explicit = ['not-an-array'];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testInferWithExplicitPathPrefixContainingDotDotThrows(): void
    {
        $explicit = [['name' => 'evil', 'path_prefix' => '../etc/passwd']];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testInferMembersAreSortedAlphabeticallyAcrossBoundaries(): void
    {
        $nodeA = $this->makeNode('php:class:App\\A\\Foo', 'App\\A\\Foo', 'src/A/Foo.php');
        $nodeZ = $this->makeNode('php:class:App\\Z\\Bar', 'App\\Z\\Bar', 'src/Z/Bar.php');
        $contributions = [$this->makeContribution([$nodeZ, $nodeA])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        foreach ($facts as $fact) {
            if (str_starts_with($fact->name, 'namespace:')) {
                $refs = $fact->nodeReferences;
                $sorted = $refs;
                sort($sorted, SORT_STRING);
                assertSame($sorted, $refs);
            }
        }
    }

    public function testInferDeduplicatesDuplicateNodeMemberships(): void
    {
        $node = $this->makeNode('php:class:App\\Foo', 'App\\Foo', 'src/Foo.php');
        $contributions = [$this->makeContribution([$node]), $this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, []);

        $ns = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'namespace:App'));
        assertSame(1, count($ns));
        assertSame(['php:class:App\\Foo'], $ns[0]->nodeReferences);
    }

    public function testInferWithUnitInSubdirectoryCreatesPrefixedMatcher(): void
    {
        $units = [$this->makeUnit('composer', 'packages/payments/composer.json', ['name' => 'acme/payments'])];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame(1, count($facts));
        $fact = $facts[0];
        // configPath is unix-style so dirname returns 'packages/payments' → prefix already uses '/'.
        assertSame(['type' => 'path_prefix', 'value' => 'packages/payments/'], $fact->matcher);
    }

    public function testInferSortsBoundaryNamesAlphabetically(): void
    {
        $units = [
            $this->makeUnit('node', 'a-package/package.json', ['name' => 'a']),
            $this->makeUnit('composer', 'composer.json', ['name' => 'z']),
            $this->makeUnit('python', 'pyproject.toml', ['name' => 'm']),
        ];

        $facts = (new BoundaryInference())->infer($units, [], []);

        $names = array_map(static fn (BoundaryFact $f): string => $f->name, $facts);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        assertSame($sorted, $names);
    }

    public function testInferWithMatchingNodeForPathPrefixRuleReturnsNodeInMembers(): void
    {
        $units = [$this->makeUnit('composer', 'src/payments/composer.json', ['name' => 'payments-svc'])];
        $node = $this->makeNode('php:class:App\\Payments\\Charge', 'App\\Payments\\Charge', 'src/payments/Charge.php');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer($units, $contributions, []);

        $composer = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => str_starts_with($f->name, 'composer:')));
        assertSame(1, count($composer));
        assertSame(['php:class:App\\Payments\\Charge'], $composer[0]->nodeReferences);

        // The namespace:App boundary should ALSO contain the node.
        $namespaces = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'namespace:App'));
        assertSame(1, count($namespaces));
        assertSame(['php:class:App\\Payments\\Charge'], $namespaces[0]->nodeReferences);
    }

    public function testInferExplicitPathPrefixWithBackslashIsConvertedToSlash(): void
    {
        // The pathPrefix private helper applies str_replace('\\\\', '/', ...) then trim.
        // For unit configPaths, dirname() on POSIX uses only '/' so a backslash-only path
        // collapses to '.'. To specifically verify the backslash→slash normalization,
        // an explicit-boundary path_prefix is the cleanest fixture.
        $explicit = [['name' => 'platform', 'path_prefix' => 'packages\\billing\\']];

        $facts = (new BoundaryInference())->infer([], [], $explicit);

        $exact = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'platform'));
        assertSame(1, count($exact));
        $value = $exact[0]->matcher['value'];
        // Backslashes in the input are converted to slashes by pathPrefix().
        assertSame(true, str_contains($value, '/') && !str_contains($value, '\\'));
        assertSame('packages/billing/', $value);
    }

    public function testInferNamespaceMatchingUsesBackslashTrimmedPrefix(): void
    {
        $explicit = [['name' => 'trim-check', 'namespace_prefix' => "\\App\\Checkout\\"]];
        $node = $this->makeNode('php:class:App\\Checkout\\Service', 'App\\Checkout\\Service', 'src/Checkout/Service.php');
        $contributions = [$this->makeContribution([$node])];

        $facts = (new BoundaryInference())->infer([], $contributions, $explicit);

        $exact = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'trim-check'));
        assertSame(1, count($exact));
        assertSame(['php:class:App\\Checkout\\Service'], $exact[0]->nodeReferences);
    }

    public function testInferExplicitNamespacePrefixIsSeparatorAnchoredAndDoesNotMatchSiblingPrefix(): void
    {
        // "App" must match "App\Service" but NOT "Apple\Service" — an explicit
        // namespace prefix is anchored with a trailing separator like the
        // inferred rules, so policy membership is not inflated.
        $explicit = [['name' => 'app', 'namespace_prefix' => 'App']];
        $inside = $this->makeNode('php:class:App\\Service', 'App\\Service', 'src/Service.php');
        $sibling = $this->makeNode('php:class:Apple\\Service', 'Apple\\Service', 'src/Apple/Service.php');
        $contributions = [$this->makeContribution([$inside, $sibling])];

        $facts = (new BoundaryInference())->infer([], $contributions, $explicit);

        $exact = array_values(array_filter($facts, static fn (BoundaryFact $f): bool => $f->name === 'app'));
        assertSame(1, count($exact));
        assertSame(['type' => 'namespace_prefix', 'value' => "App\\"], $exact[0]->matcher);
        assertSame(['php:class:App\\Service'], $exact[0]->nodeReferences);
    }

    public function testInferRejectsDuplicateExplicitBoundaryNames(): void
    {
        $explicit = [
            ['name' => 'core', 'path_prefix' => 'src/core/'],
            ['name' => 'core', 'namespace_prefix' => 'App\\Core'],
        ];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testInferRejectsExplicitBoundaryDeclaringBothMatchers(): void
    {
        $explicit = [['name' => 'core', 'path_prefix' => 'src/core/', 'namespace_prefix' => 'App\\Core']];

        assertThrows(
            static fn(): array => (new BoundaryInference())->infer([], [], $explicit),
            InvalidArgumentException::class,
        );
    }

    public function testPhpRouteNodeDoesNotSeedNamespaceBoundary(): void
    {
        $route = new NodeFact(
            'php:route:GET /shop/checkout',
            'route',
            'GET /shop/checkout => App\\Http\\Controllers\\CheckoutController::show',
            'GET /shop/checkout',
            Origin::FrameworkConvention,
            Confidence::Certain,
            new Evidence('routes/web.php', 1, 1),
        );
        $contribution = new ScanContribution('knossos.php:file:routes/web.php', [$route], [], []);

        $facts = (new BoundaryInference())->infer([], [$contribution], []);

        assertSame([], $facts);
    }

    public function testTypescriptRouteNodeDoesNotSeedModuleBoundary(): void
    {
        $route = new NodeFact(
            'ts:route:GET /cats',
            'route',
            'GET /cats => src/cats.controller.ts#CatsController::findAll',
            'GET /cats',
            Origin::FrameworkConvention,
            Confidence::Certain,
            new Evidence('src/cats.controller.ts', 1, 1),
        );
        $contribution = new ScanContribution('knossos.typescript:file:src/cats.controller.ts', [$route], [], []);

        $facts = (new BoundaryInference())->infer([], [$contribution], []);

        assertSame([], $facts);
    }

    public function testInferredRulesWithIdenticalMatcherMergeIntoOneBoundary(): void
    {
        // A composer root and a node root both produce matcher path_prefix:"".
        $units = [
            $this->makeUnit('composer', 'composer.json', ['name' => 'vendor/app']),
            $this->makeUnit('node', 'package.json', ['name' => 'web-app']),
        ];

        $facts = (new BoundaryInference())->infer($units, [], []);

        assertSame(1, count($facts));
        assertSame('composer:vendor/app (+node:web-app)', $facts[0]->name);
        assertSame(['type' => 'path_prefix', 'value' => ''], $facts[0]->matcher);
        assertSame('inferred', $facts[0]->source);
        // identityName pins the stable id to the surviving primary rule's base name so
        // that adding/removing merge partners renames the display only, not the id.
        assertSame('composer:vendor/app', $facts[0]->identityName);
    }

    public function testExplicitRuleIsNotMergedWithInferredRuleSharingItsMatcher(): void
    {
        $units = [$this->makeUnit('composer', 'composer.json', ['name' => 'vendor/app'])];
        $explicit = [['name' => 'my-root', 'path_prefix' => '']];

        $facts = (new BoundaryInference())->infer($units, [], $explicit);

        assertSame(2, count($facts));
    }

    public function testNonIdentifierNamespaceSegmentDoesNotSeedBoundary(): void
    {
        // Backstop: even a symbol-kind node whose leading namespace segment is not
        // a valid PHP identifier (here: contains spaces and '=>') seeds nothing.
        $node = new NodeFact(
            'php:class:Weird',
            'class',
            'GET /odd => App\\Thing',
            'Thing',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('src/Thing.php', 1, 1),
        );
        $contribution = new ScanContribution('knossos.php:file:src/Thing.php', [$node], [], []);

        $facts = (new BoundaryInference())->infer([], [$contribution], []);

        assertSame([], $facts);
    }

    // ----- helpers -----

    private function makeNode(string $localId, string $canonicalName, string $relativePath): NodeFact
    {
        return new NodeFact(
            $localId,
            'class',
            $canonicalName,
            $canonicalName,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 5),
        );
    }

    private function makeContribution(array $nodes): ScanContribution
    {
        $localIds = array_map(static fn (NodeFact $n): string => 'knossos.php:file:' . $n->evidence->relativePath, $nodes);
        $ownerKey = empty($localIds) ? 'knossos.php:file:empty' : $localIds[0];

        return new ScanContribution($ownerKey, $nodes, [], []);
    }

    private function makeUnit(string $kind, string $configPath, array $metadata = []): ProjectUnit
    {
        return new ProjectUnit($kind, $configPath, hash('sha256', $configPath), $metadata);
    }
}
