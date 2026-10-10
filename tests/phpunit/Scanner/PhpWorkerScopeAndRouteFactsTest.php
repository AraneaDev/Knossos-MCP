<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP worker's own facts for closure scopes, Laravel route groups and
 * actions, Symfony routes and names written in another case, read straight
 * from the worker: what each file says, before any other file is consulted.
 */
#[Group('php-scanner')]
final class PhpWorkerScopeAndRouteFactsTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-php-worker-facts-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * Each closure and arrow function types its own variables: parameters by
     * their declared types, captures by what they held where captured, and a
     * by-reference capture that is rebound leaves the variable untyped.
     */
    public function testClosureAndArrowFunctionScopes(): void
    {
        $this->write('src/Svc.php', <<<'PHP'
            <?php
            namespace App;
            class Foo { public function run(): void {} }
            class Bar { public function run(): void {} }
            class Svc {
                public function m(Bar $x, array $list, string $name): void {
                    array_map(fn (Foo $x): Bar => $x->run(), $list);
                    array_map(function (Foo $y, $x): ?Foo { $x->run(); $y->run(); return null; }, $list, $list);
                    $x->run();
                    $kept = new Bar();
                    $same = function () use (&$kept) { $kept->run(); };
                    $kept->run();
                    $z = new Bar();
                    $k = function () use (&$z, $missing) { $z = new Foo(); };
                    $z->run();
                    $card = 'App\\Cards\\' . $name;
                    $build = fn () => new $card();
                    $shadow = fn ($card) => new $card();
                    $closure = function () use ($card) { return new $card(); };
                }
            }
            PHP);
        [, $edges] = $this->scan(['src/Svc.php']);

        $calls = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'calls' && str_ends_with($edge->targetReference, '::run')) {
                $calls[] = $edge->evidence->startLine . ' ' . $edge->targetReference . ' ' . $edge->confidence->value;
            }
        }
        sort($calls);
        self::assertSame([
            '11 php:method:App\\Bar::run probable',
            '12 php:method:App\\Bar::run probable',
            '7 php:method:App\\Foo::run certain',
            '8 php:method:App\\Foo::run certain',
            '9 php:method:App\\Bar::run certain',
        ], $calls, 'the untyped closure parameter and the rebound by-reference capture type nothing');

        $references = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'references' && $edge->sourceReference === 'php:method:App\\Svc::m') {
                $references[] = $edge->evidence->startLine . ' ' . $edge->targetReference;
            }
        }
        sort($references);
        self::assertSame([
            '17 php:class_prefix:App\\Cards',
            '19 php:class_prefix:App\\Cards',
            '6 php:class:App\\Bar',
            '7 php:class:App\\Bar',
            '7 php:class:App\\Foo',
            '8 php:class:App\\Foo',
            '8 php:class:App\\Foo',
        ], $references, 'an arrow function parameter shadows a captured prefix');
    }

    /**
     * A class name prefix belongs to the scope that built it: a closure sees
     * only what it captures, nested closures and arrow functions pass a
     * capture inward, a by-reference capture rebound inside a closure does not
     * reach the code around it, and a function body never sees the file's.
     */
    public function testClassPrefixesStayInTheirScope(): void
    {
        $this->write('src/Cards.php', <<<'PHP'
            <?php
            namespace App;
            class Svc {
                public function m(string $name, string $q): void {
                    $p = 'App\\Cards\\' . $name;
                    $c1 = function () use (&$p, $q) {
                        $p = 'App\\Other\\' . $q;
                        $own = 'App\\Own\\' . $q;
                        new $p();
                        $inner = function () use ($p) { new $p(); };
                        $arrow = fn () => fn () => new $p();
                    };
                    new $p();
                    $c2 = function () use ($p) { new $p(); };
                    $c3 = function () { new $p(); };
                    $c4 = function () use ($own) { new $own(); };
                    $nested = fn () => (function () use ($p) { new $p(); })();
                }
            }
            $f = 'App\\Scripts\\' . $argv[1];
            $g = function () use ($f) { new $f(); };
            function inner(): void { new $f(); }
            new $f();
            PHP);
        [, $edges] = $this->scan(['src/Cards.php']);

        $prefixes = [];
        foreach ($edges as $edge) {
            if (str_starts_with($edge->targetReference, 'php:class_prefix:')) {
                $prefixes[] = $edge->evidence->startLine . ' ' . $edge->targetReference;
            }
        }
        sort($prefixes);
        self::assertSame([
            '10 php:class_prefix:App\\Other',
            '11 php:class_prefix:App\\Other',
            '13 php:class_prefix:App\\Cards',
            '14 php:class_prefix:App\\Cards',
            '17 php:class_prefix:App\\Cards',
            '21 php:class_prefix:App\\Scripts',
            '23 php:class_prefix:App\\Scripts',
            '9 php:class_prefix:App\\Other',
        ], $prefixes);
    }

    /** A class naming itself in another case is not a use of it, and its return types match in any case. */
    public function testNamesInAnotherCaseWithinOneFile(): void
    {
        $this->write('src/Foo.php', <<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function make(): Bar { return new Bar(); }
                public function chain(): void { $made = $this->MAKE(); $made->go(); FOO::helper(); }
                public static function helper(): void {}
            }
            PHP);
        [, $edges] = $this->scan(['src/Foo.php']);
        $tuples = array_map(static fn(EdgeFact $e): string => $e->kind . ' ' . $e->sourceReference . ' -> ' . $e->targetReference . ' ' . $e->confidence->value, $edges);

        self::assertContains('calls php:method:App\\Foo::chain -> php:method:App\\Bar::go probable', $tuples);
        self::assertNotContains('references php:method:App\\Foo::chain -> php:class:App\\Foo certain', $tuples);
    }

    /**
     * Every group form applies its attributes, and every way of naming a
     * controller action reaches a method.
     */
    public function testLaravelGroupsAndActions(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            use App\Http\Controllers\UserController;
            use App\Http\Controllers\InvokableController;
            Route::group(['prefix' => 'admin', 'middleware' => 'auth', 'as' => 'admin.', 'namespace' => 'App\Http\Controllers', 'unknown' => 'x', 7 => 'y'], function () {
                Route::group(['namespace' => 'Admin', 'name' => 'nested.', 'middleware' => ['verified']], function () {
                    Route::get('/users', 'UserController@index');
                    Route::group(['namespace' => '\Other'], function () {
                        Route::get('/other', 'OtherController');
                    });
                });
                Route::resource('photos', 'PhotoController')->only('index');
                Route::resource('things', $dynamic);
            });
            Route::controller(UserController::class)->prefix('ctl')->group(function () {
                Route::get('/list', 'index');
                Route::group(['controller' => InvokableController::class], function () {
                    Route::get('/inner', 'show');
                });
            });
            Route::get('/plain', InvokableController::class);
            Route::get('/string', '\App\Http\Controllers\InvokableController');
            Route::get('/empty', '');
            PHP);
        [$nodes, $edges] = $this->scan(['routes/web.php'], ['laravel']);

        $routes = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'route') {
                $routes[$node->canonicalName] = [$node->attributes['name'], $node->attributes['middleware']];
            }
        }
        ksort($routes);
        self::assertSame([
            'GET /admin/other => Other\\OtherController' => ['admin.nested.', ['auth', 'verified']],
            'GET /admin/photos => App\\Http\\Controllers\\PhotoController::index' => ['admin.photos.index', ['auth']],
            'GET /admin/users => App\\Http\\Controllers\\Admin\\UserController@index' => ['admin.nested.', ['auth', 'verified']],
            'GET /ctl/inner => App\\Http\\Controllers\\InvokableController::show' => ['', []],
            'GET /ctl/list => App\\Http\\Controllers\\UserController::index' => ['', []],
            'GET /empty => closure' => ['', []],
            'GET /plain => App\\Http\\Controllers\\InvokableController' => ['', []],
            'GET /string => App\\Http\\Controllers\\InvokableController' => ['', []],
        ], $routes);

        $targets = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'routes_to') {
                $targets[] = $edge->targetReference;
            }
        }
        sort($targets);
        self::assertSame([
            'php:method:App\\Http\\Controllers\\Admin\\UserController::index',
            'php:method:App\\Http\\Controllers\\InvokableController::__invoke',
            'php:method:App\\Http\\Controllers\\InvokableController::__invoke',
            'php:method:App\\Http\\Controllers\\InvokableController::show',
            'php:method:App\\Http\\Controllers\\PhotoController::index',
            'php:method:App\\Http\\Controllers\\UserController::index',
            'php:method:Other\\OtherController::__invoke',
        ], $targets);
    }

    /**
     * A class-level route on an invokable controller is its route, a string
     * `methods` is one method, and the class-level methods and name prefix
     * apply to the routes its methods declare.
     */
    public function testSymfonyInvokableAndStringMethods(): void
    {
        $this->write('src/Controllers.php', <<<'PHP'
            <?php
            namespace App\Controller;
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/health', name: 'health', methods: 'get')]
            #[Route(path: '/ping')]
            final class HealthController { public function __invoke(): void {} }
            #[Route(self::PATH)]
            final class DynamicController { const PATH = '/d'; public function __invoke(): void {} }
            #[Route('/api', name: 'api_', methods: ['GET'])]
            final class ApiController {
                #[Route('/items', name: 'items', methods: 'POST')]
                public function items(): void {}
                #[Route('/list')]
                public function list(): void {}
                public function __invoke(): void {}
            }
            PHP);
        [$nodes, $edges, $diagnostics] = $this->scan(['src/Controllers.php'], ['symfony']);

        $routes = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'route') {
                $routes[$node->canonicalName] = $node->attributes['name'];
            }
        }
        ksort($routes);
        self::assertSame([
            'ANY /ping => App\\Controller\\HealthController::__invoke' => null,
            'GET /api/list => App\\Controller\\ApiController::list' => null,
            'GET /health => App\\Controller\\HealthController::__invoke' => 'health',
            'GET|POST /api/items => App\\Controller\\ApiController::items' => 'api_items',
        ], $routes);
        $targets = array_values(array_unique(array_map(static fn(EdgeFact $e): string => $e->targetReference, array_filter($edges, static fn(EdgeFact $e): bool => $e->kind === 'routes_to'))));
        sort($targets);
        self::assertSame([
            'php:method:App\\Controller\\ApiController::items',
            'php:method:App\\Controller\\ApiController::list',
            'php:method:App\\Controller\\HealthController::__invoke',
        ], $targets);
        self::assertContains('SYMFONY_DYNAMIC_ROUTE_PATH', array_map(static fn($d): string => $d->code, $diagnostics), 'a computed class path is reported, not guessed');
    }

    /**
     * @param list<string> $files
     * @param list<string> $frameworks
     * @return array{0: list<NodeFact>, 1: list<EdgeFact>, 2: list<\Knossos\Scanner\Protocol\Diagnostic>}
     */
    private function scan(array $files, array $frameworks = []): array
    {
        $client = $this->phpWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['root' => $this->root, 'files' => $files, 'frameworks' => $frameworks]));
        } finally {
            $client->shutdown();
        }

        return [
            array_merge(...array_map(static fn(ScanContribution $c): array => $c->nodes, $contributions)),
            array_merge(...array_map(static fn(ScanContribution $c): array => $c->edges, $contributions)),
            array_merge(...array_map(static fn(ScanContribution $c): array => $c->diagnostics, $contributions)),
        ];
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }
}
