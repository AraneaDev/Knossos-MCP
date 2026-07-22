<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use InvalidArgumentException;
use Knossos\Bundle\BundleIdMapBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('bundle-id-map-builder')]
final class BundleIdMapBuilderTest extends TestCase
{
    // ----- shape -----

    public function testClassIsFinal(): void
    {
        $this->assertTrue((new \ReflectionClass(BundleIdMapBuilder::class))->isFinal());
    }

    public function testBuildIsPublicInstanceMethod(): void
    {
        $method = (new \ReflectionClass(BundleIdMapBuilder::class))->getMethod('build');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    public function testMappedIdIsPublicStaticMethod(): void
    {
        $method = (new \ReflectionClass(BundleIdMapBuilder::class))->getMethod('mappedId');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    // ----- mappedId() -----

    public function testMappedIdReturnsStringStartingWithBundlePrefix(): void
    {
        $id = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');

        $this->assertIsString($id);
        $this->assertStringStartsWith('bundle-', $id);
    }

    public function testMappedIdHasExpectedTotalLength(): void
    {
        // 'bundle-' (7 chars) + sha256 hex truncated to 48 chars = 55 chars total.
        $id = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');

        assertSame(55, strlen($id));
    }

    public function testMappedIdIsDeterministic(): void
    {
        $a = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');
        $b = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');

        assertSame($a, $b);
    }

    public function testMappedIdVariesOnProjectId(): void
    {
        $a = BundleIdMapBuilder::mappedId('proj-A', 'files', 'old-1');
        $b = BundleIdMapBuilder::mappedId('proj-B', 'files', 'old-1');

        $this->assertNotSame($a, $b);
    }

    public function testMappedIdVariesOnKind(): void
    {
        $a = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');
        $b = BundleIdMapBuilder::mappedId('proj', 'nodes', 'old-1');

        $this->assertNotSame($a, $b);
    }

    public function testMappedIdVariesOnOld(): void
    {
        $a = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');
        $b = BundleIdMapBuilder::mappedId('proj', 'files', 'old-2');

        $this->assertNotSame($a, $b);
    }

    public function testMappedIdHashPortionIsLowercaseHex(): void
    {
        $id = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');

        // Strip 'bundle-' prefix, assert remainder is a valid sha256 hex string
        // (lowercase a-f, 0-9, no uppercase, no whitespace).
        $this->assertMatchesRegularExpression('/^bundle-[0-9a-f]{48}$/', $id);
    }

    public function testMappedIdFirst48OfSha256Hex(): void
    {
        // The trailing 48 chars are the first 48 chars of sha256(projectId."\0".kind."\0".old).
        $expectedHex = substr(hash('sha256', 'proj' . "\0" . 'files' . "\0" . 'old-1'), 0, 48);
        $id = BundleIdMapBuilder::mappedId('proj', 'files', 'old-1');

        assertSame('bundle-' . $expectedHex, $id);
    }

    // ----- build() ---

    public function testBuildReturnsThreeEmptyMapsForEmptyPayload(): void
    {
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', ['files' => [], 'nodes' => [], 'boundaries' => []]);

        assertSame(['files' => [], 'nodes' => [], 'boundaries' => []], $maps);
    }

    public function testBuildResultHasExactlyThreeKeys(): void
    {
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', ['files' => [], 'nodes' => [], 'boundaries' => []]);

        assertSame(['files', 'nodes', 'boundaries'], array_keys($maps));
    }

    public function testBuildMapsSingleFileRow(): void
    {
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', [
            'files' => [['id' => 'f-1']],
            'nodes' => [],
            'boundaries' => [],
        ]);

        assertSame(1, count($maps['files']));
        assertSame(BundleIdMapBuilder::mappedId('proj', 'files', 'f-1'), $maps['files']['f-1']);
        assertSame([], $maps['nodes']);
        assertSame([], $maps['boundaries']);
    }

    public function testBuildMapsMultipleRowsPerTable(): void
    {
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', [
            'files' => [['id' => 'f-1'], ['id' => 'f-2'], ['id' => 'f-3']],
            'nodes' => [['id' => 'n-1'], ['id' => 'n-2']],
            'boundaries' => [['id' => 'b-1']],
        ]);

        assertSame(3, count($maps['files']));
        assertSame(2, count($maps['nodes']));
        assertSame(1, count($maps['boundaries']));
        assertSame(BundleIdMapBuilder::mappedId('proj', 'files', 'f-2'), $maps['files']['f-2']);
        assertSame(BundleIdMapBuilder::mappedId('proj', 'nodes', 'n-2'), $maps['nodes']['n-2']);
        assertSame(BundleIdMapBuilder::mappedId('proj', 'boundaries', 'b-1'), $maps['boundaries']['b-1']);
    }

    public function testBuildIgnoresExtraFieldsOnRows(): void
    {
        // The row $payload[$table] entries may carry additional fields beyond 'id'
        // (e.g. relative_path for files, kind for nodes). build() only consults
        // the 'id' key, so other fields are silently ignored.
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', [
            'files' => [['id' => 'f-1', 'relative_path' => 'src/A.php', 'language' => 'php']],
            'nodes' => [],
            'boundaries' => [],
        ]);

        assertSame(BundleIdMapBuilder::mappedId('proj', 'files', 'f-1'), $maps['files']['f-1']);
    }

    public function testBuildProducesIndependentMapsPerTable(): void
    {
        // The same 'id' string used in two different tables MUST NOT collide —
        // the mappedId() signature folds `kind` (table) into the hash input,
        // so each (table, id) pair has a stable, distinct mapping.
        $builder = new BundleIdMapBuilder();

        $maps = $builder->build('proj', [
            'files' => [['id' => 'shared']],
            'nodes' => [['id' => 'shared']],
            'boundaries' => [['id' => 'shared']],
        ]);

        assertSame(1, count($maps['files']));
        assertSame(1, count($maps['nodes']));
        assertSame(1, count($maps['boundaries']));
        $this->assertNotSame($maps['files']['shared'], $maps['nodes']['shared']);
        $this->assertNotSame($maps['files']['shared'], $maps['boundaries']['shared']);
        $this->assertNotSame($maps['nodes']['shared'], $maps['boundaries']['shared']);
    }

    // ----- rejection: not-an-object row -----

    public function testBuildRejectsRowThatIsNotAnObject(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['id' => 'f-1'], 'not-an-object'],
                'nodes' => [],
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle files row must be an object.', $error->getMessage());
    }

    public function testBuildRejectsRowThatIsAList(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['id' => 'f-1']],
                'nodes' => [['id' => 'n-1'], [1, 2, 3]], // list, not object
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle nodes row must be an object.', $error->getMessage());
    }

    public function testBuildRejectsRowWithMissingId(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['relative_path' => 'src/A.php']], // no 'id' key
                'nodes' => [],
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle entity ID is invalid.', $error->getMessage());
    }

    public function testBuildRejectsRowWithNonStringId(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['id' => 42]],
                'nodes' => [],
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle entity ID is invalid.', $error->getMessage());
    }

    public function testBuildRejectsRowWithEmptyStringId(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['id' => '']],
                'nodes' => [],
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle entity ID is invalid.', $error->getMessage());
    }

    public function testBuildRejectsNullId(): void
    {
        $builder = new BundleIdMapBuilder();

        $error = captureThrows(
            static fn () => $builder->build('proj', [
                'files' => [['id' => null]],
                'nodes' => [],
                'boundaries' => [],
            ]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle entity ID is invalid.', $error->getMessage());
    }

    public function testBuildRejectionSurfacesTableNameInError(): void
    {
        // The row-must-be-an-object error names which table is wrong. Verify
        // each table's name appears in the message for its own violation.
        // All three table keys MUST be supplied so the source's
        // `foreach (array_keys($maps) as $table)` loop iterates cleanly
        // without Undefined-array-key or null-foreach notices — the only
        // bad row is the one in the targeted table.
        $builder = new BundleIdMapBuilder();

        foreach (['files', 'nodes', 'boundaries'] as $table) {
            $error = captureThrows(
                static fn () => $builder->build('proj', [
                    'files' => [],
                    'nodes' => [],
                    'boundaries' => [],
                    $table => ['not-an-object'],
                ]),
                InvalidArgumentException::class,
            );
            $this->assertStringContainsString('Bundle ' . $table . ' row must be an object.', $error->getMessage());
        }
    }
}
