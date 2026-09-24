<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nest calls a fixed set of methods on the classes it manages: a guard's
 * `canActivate` through `@UseGuards`, an interceptor's `intercept`, a pipe's
 * `transform`, a filter's `catch`, and the lifecycle hooks. Nothing in the
 * project calls them. The same name on a class Nest does not manage is an
 * ordinary method.
 */
#[Group('typescript-scanner')]
final class TypescriptNestContractTest extends KnossosTestCase
{
    public function testTheMethodsNestCallsOnItsProvidersAreFrameworkHandlers(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-nestjs',
                'files' => ['src/jwt.guard.ts', 'src/nest.d.ts'],
                'config_files' => ['tsconfig.json'],
                'frameworks' => ['nestjs'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $roles = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'method') {
                    $roles[$node->canonicalName] = in_array('nestjs.framework_handler', $node->attributes['nestjs_roles'] ?? [], true);
                }
            }
        }
        ksort($roles);

        self::assertSame([
            'src/jwt.guard.ts#JwtAuthGuard::canActivate' => true,
            'src/jwt.guard.ts#JwtAuthGuard::helper' => false,
            'src/jwt.guard.ts#JwtAuthGuard::onModuleInit' => true,
            'src/jwt.guard.ts#Plain::canActivate' => false,
        ], $roles);
    }
}
