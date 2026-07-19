<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags modules that a test runner discovers by convention rather than by import.
 *
 * Such modules have an in-degree of zero in every project by construction, which
 * would otherwise make every one of them a dead-code candidate.
 */
final readonly class TestModuleRule implements ClassificationRule
{
    public const ROLE = 'quality.test_module';

    private const DIRECTORY_SEGMENTS = ['__tests__', '__test__', 'tests', 'test', 'spec'];

    public function id(): string
    {
        return 'core.test.modules.v1';
    }

    public function classify(NodeFact $node): array
    {
        // Every declaration inside a test file is glob-discovered too, so the role is
        // keyed on the file the node came from rather than on the module node alone.
        $path = str_replace('\\', '/', $node->evidence->relativePath);
        if (!$this->isTestPath($path)) {
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

    private function isTestPath(string $path): bool
    {
        $segments = explode('/', $path);
        $file = array_pop($segments) ?? '';
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::DIRECTORY_SEGMENTS, true)) {
                return true;
            }
        }

        // Filename conventions: foo.test.ts, foo.spec.js, test_foo.py, foo_test.py, FooTest.php.
        $stem = (string) preg_replace('/\.[^.]+$/', '', $file);
        $lower = strtolower($stem);

        if (
            str_ends_with($lower, '.test')
            || str_ends_with($lower, '.spec')
            || str_starts_with($lower, 'test_')
            || str_ends_with($lower, '_test')
        ) {
            return true;
        }

        // The PHPUnit convention is PascalCase (`ThingTest`), so the `Test` suffix only
        // counts at a word boundary. A bare lowercase `test` suffix is not a convention
        // anywhere and would swallow ordinary words such as `contest` or `latest`.
        return preg_match('/(?:^|[^A-Za-z])Test$|[a-z0-9]Test$/', $stem) === 1;
    }
}
