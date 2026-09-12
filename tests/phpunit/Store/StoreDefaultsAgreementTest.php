<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\GraphRepository;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;

/**
 * A caller of the store gets the defaults the interface advertises.
 *
 * The facade restates every default of GraphRepository, because PHP does not
 * inherit them, and the collaborators behind it declare none, so the facade's
 * copy is the one that takes effect. Nothing compared the two copies: the
 * facade could default findNodesByName() to 19 rows while the interface
 * promised 20.
 */
final class StoreDefaultsAgreementTest extends KnossosTestCase
{
    #[Group('store')]
    public function testEveryFacadeDefaultIsTheInterfaceDefault(): void
    {
        $compared = 0;
        foreach ((new ReflectionClass(GraphRepository::class))->getMethods() as $contract) {
            $implementation = new ReflectionMethod(SqliteGraphRepository::class, $contract->getName());
            foreach ($contract->getParameters() as $index => $parameter) {
                if (!$parameter->isDefaultValueAvailable()) {
                    continue;
                }
                $implemented = $implementation->getParameters()[$index];
                assertSame(
                    $parameter->getDefaultValue(),
                    $implemented->isDefaultValueAvailable() ? $implemented->getDefaultValue() : '(no default)',
                    sprintf('%s($%s) defaults differently from GraphRepository.', $contract->getName(), $parameter->getName()),
                );
                ++$compared;
            }
        }

        assertSame(true, $compared >= 6, sprintf('Expected at least six defaults to compare, compared %d.', $compared));
    }
}
