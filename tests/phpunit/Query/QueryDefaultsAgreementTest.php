<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Mcp\ToolCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;

/**
 * A PHP caller who omits an argument gets the default an MCP client gets.
 *
 * Every query method is named after its tool, and every parameter after the
 * tool's property, so the defaults written into the PHP signatures and the
 * defaults the schema advertises describe the same thing. Nothing compared
 * them, and one had drifted: ArchitecturePolicyQueryService::checkArchitecture
 * defaulted to 20,000 edges against the schema's 100,000, so review_diff, which
 * called it with the inner default, checked policies over a third of this
 * repository's graph.
 *
 * Read by reflection rather than restated, so the test states the rule and
 * covers every tool-named method in the query layer, including ones added later.
 */
final class QueryDefaultsAgreementTest extends KnossosTestCase
{
    #[Group('query')]
    public function testEveryQueryDefaultMatchesTheAdvertisedSchemaDefault(): void
    {
        $schemas = [];
        foreach (ToolCatalog::definitions() as $definition) {
            $schemas[$definition['name']] = (array) ($definition['inputSchema']['properties'] ?? []);
        }
        $compared = 0;
        foreach (glob(self::repositoryRoot() . '/src/Query/*.php') ?: [] as $file) {
            $class = 'Knossos\\Query\\' . basename($file, '.php');
            if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $tool = self::snake($method->getName());
                if ($method->getDeclaringClass()->getName() !== $class || !isset($schemas[$tool])) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $spec = $schemas[$tool][self::snake($parameter->getName())] ?? null;
                    if (!$parameter->isDefaultValueAvailable() || !is_array($spec) || !array_key_exists('default', $spec)) {
                        continue;
                    }
                    assertSame(
                        $spec['default'],
                        $parameter->getDefaultValue(),
                        sprintf('%s::%s($%s) defaults differently from %s.%s in the schema.', basename($file, '.php'), $method->getName(), $parameter->getName(), $tool, self::snake($parameter->getName())),
                    );
                    ++$compared;
                }
            }
        }

        // A guard against silently comparing nothing if the naming convention ever changes.
        assertSame(true, $compared >= 150, sprintf('Expected to compare at least 150 defaults, compared %d.', $compared));
    }

    private static function snake(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
