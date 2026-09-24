<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nest calls a fixed set of methods on the classes it manages: a guard's
 * `canActivate` through `@UseGuards`, an interceptor's `intercept`, a pipe's
 * `transform`, a filter's `catch`, and the lifecycle hooks. Nothing in the
 * project calls them. Each is called only on the role that has it: a
 * `canActivate` on a provider that is no guard is an ordinary method, as is
 * any of them on a class Nest does not manage.
 */
#[Group('typescript-scanner')]
final class TypescriptNestContractTest extends KnossosTestCase
{
    public function testTheMethodsNestCallsOnEachRoleAreFrameworkHandlers(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-nestjs',
                'files' => ['src/jwt.guard.ts', 'src/roles.ts', 'src/nest.d.ts'],
                'config_files' => ['tsconfig.json'],
                'frameworks' => ['nestjs'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $roles = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'method' && !str_starts_with($node->canonicalName, 'src/nest.d.ts#')) {
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
            'src/roles.ts#AdminGuard::canActivate' => true,
            'src/roles.ts#Events::afterInit' => true,
            'src/roles.ts#Events::canActivate' => false,
            'src/roles.ts#Events::handleConnection' => true,
            'src/roles.ts#Helper::canActivate' => false,
            'src/roles.ts#Helper::catch' => false,
            'src/roles.ts#Helper::handleConnection' => false,
            'src/roles.ts#Helper::intercept' => false,
            'src/roles.ts#Helper::onModuleInit' => true,
            'src/roles.ts#Helper::transform' => false,
            'src/roles.ts#Helper::use' => false,
            'src/roles.ts#HttpFilter::catch' => true,
            'src/roles.ts#LoggerMiddleware::use' => true,
            'src/roles.ts#LoggingInterceptor::intercept' => true,
            'src/roles.ts#LoggingInterceptor::transform' => false,
            'src/roles.ts#ParsePipe::transform' => true,
            'src/roles.ts#Presence::handleConnection' => false,
            'src/roles.ts#RolesGuard::canActivate' => true,
            'src/roles.ts#Timing::intercept' => true,
            'src/roles.ts#TokenCheck::canActivate' => true,
        ], $roles);
    }
}
