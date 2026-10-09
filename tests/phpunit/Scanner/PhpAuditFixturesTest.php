<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The audit's PHP scope and route fixtures through the whole scan: each call
 * reaches the class its receiver holds in that scope, and each route carries
 * what its groups and attributes give it.
 */
#[Group('php-scanner')]
final class PhpAuditFixturesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-php-audit-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * A closure or arrow function has its own variables: a typed parameter
     * types its calls, an assignment inside does not retype the variable
     * outside, and a captured variable keeps the type it had where captured.
     */
    public function testM26ClosureAndArrowFunctionScopes(): void
    {
        $this->write('src/Svc.php', <<<'PHP'
            <?php
            namespace App;
            class Foo { public function run(): void {} }
            class Bar { public function run(): void {} }
            class Svc {
                public function m(Bar $x, array $list): void {
                    array_map(fn (Foo $x) => $x->run(), $list);
                    array_map(function (Foo $y) { $x = new Foo(); $x->run(); }, $list);
                    $x->run();
                    $z = new Bar();
                    $f = function () use ($z) { $z->run(); };
                    $g = fn () => $z->run();
                    $h = function () { $z->run(); };
                    $k = function () use (&$z) { $z = new Foo(); };
                    $z->run();
                }
            }
            PHP);
        $pdo = $this->scan();

        $calls = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['kind'] === 'calls' && $edge['source'] === 'App\\Svc::m' && str_ends_with($edge['target'], '::run')) {
                $calls[] = $edge['start_line'] . ' ' . $edge['target'] . ' ' . $edge['confidence'];
            }
        }
        sort($calls);
        self::assertSame([
            // Captured, by `use` and implicitly.
            '11 App\\Bar::run probable',
            '12 App\\Bar::run probable',
            // The arrow function's parameter, not the method's `Bar $x`.
            '7 App\\Foo::run certain',
            '8 App\\Foo::run probable',
            // The method's `$x` is still the `Bar` it was declared as.
            '9 App\\Bar::run certain',
        ], $calls, 'line 13 types nothing, and line 15 may hold either class after a by-reference capture');
        $declared = $this->nodes($pdo);
        foreach ($declared as $node) {
            if (str_starts_with($node['canonical_name'], 'App\\')) {
                self::assertStringNotContainsString('external', $node['kind'], $node['canonical_name']);
            }
        }
        $references = array_values(array_filter(
            $this->edges($pdo),
            static fn(array $e): bool => $e['kind'] === 'references' && $e['source'] === 'App\\Svc::m' && $e['target'] === 'App\\Foo',
        ));
        self::assertNotSame([], $references, 'a closure parameter type is a class the method names');
    }

    /**
     * `Route::group([...], fn)` applies its prefix, middleware, name and
     * namespace, and a namespace from either group form qualifies the
     * `Class@method` actions inside it.
     */
    public function testM28RouteGroupArrayFormAndNamespace(): void
    {
        $this->write('composer.json', '{"require": {"laravel/framework": "^11.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');
        $this->write('app/Http/Controllers/Admin/UserController.php', "<?php\nnamespace App\\Http\\Controllers\\Admin;\nclass UserController { public function index() {} }\n");
        $this->write('app/Http/Controllers/Admin/Reports/DailyController.php', "<?php\nnamespace App\\Http\\Controllers\\Admin\\Reports;\nclass DailyController { public function show() {} }\n");
        $this->write('app/Http/Controllers/Admin/PhotoController.php', "<?php\nnamespace App\\Http\\Controllers\\Admin;\nclass PhotoController { public function index() {} }\n");
        $this->write('app/Http/Controllers/RootController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass RootController { public function show() {} }\n");
        $this->write('routes/web.php', <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            Route::group(['prefix' => 'admin', 'middleware' => ['auth'], 'namespace' => 'App\Http\Controllers\Admin', 'as' => 'admin.'], function () {
                Route::get('/users', 'UserController@index')->name('users');
                Route::get('/root', '\App\Http\Controllers\RootController@show');
                Route::resource('photos', 'PhotoController')->only(['index']);
                Route::namespace('Reports')->prefix('reports')->group(function () {
                    Route::get('/daily', 'DailyController@show');
                });
            });
            Route::get('/plain', 'PlainController@index');
            PHP);
        $pdo = $this->scan();

        $routes = [];
        foreach ($this->nodes($pdo) as $node) {
            if ($node['kind'] === 'route') {
                $attributes = json_decode($node['attributes_json'], true);
                $routes[$node['canonical_name']] = [$attributes['name'], $attributes['middleware']];
            }
        }
        ksort($routes);
        self::assertSame([
            'GET /admin/photos => App\\Http\\Controllers\\Admin\\PhotoController::index' => ['admin.photos.index', ['auth']],
            'GET /admin/reports/daily => App\\Http\\Controllers\\Admin\\Reports\\DailyController@show' => ['admin.', ['auth']],
            'GET /admin/root => \\App\\Http\\Controllers\\RootController@show' => ['admin.', ['auth']],
            'GET /admin/users => App\\Http\\Controllers\\Admin\\UserController@index' => ['admin.users', ['auth']],
            'GET /plain => PlainController@index' => ['', []],
        ], $routes);

        $targets = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['kind'] === 'routes_to') {
                $targets[$edge['target']] = $edge['target_kind'];
            }
            if ($edge['kind'] === 'uses_middleware') {
                self::assertSame('laravel.middleware:auth', $edge['target']);
            }
        }
        ksort($targets);
        self::assertSame([
            'App\\Http\\Controllers\\Admin\\PhotoController::index' => 'method',
            'App\\Http\\Controllers\\Admin\\Reports\\DailyController::show' => 'method',
            'App\\Http\\Controllers\\Admin\\UserController::index' => 'method',
            'App\\Http\\Controllers\\RootController::show' => 'method',
            'PlainController::index' => 'external_method',
        ], $targets);
    }

    /**
     * A class-level `#[Route]` on an invokable controller is its route, and a
     * `methods` given as one string is that method rather than any.
     */
    public function testL24InvokableControllerAndStringMethods(): void
    {
        $this->write('composer.json', '{"require": {"symfony/framework-bundle": "^7.0"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        $this->write('src/Controller/Controllers.php', <<<'PHP'
            <?php
            namespace App\Controller;
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/health', name: 'health', methods: 'GET')]
            final class HealthController { public function __invoke(): void {} }
            #[Route('/api', name: 'api_', methods: ['GET'])]
            final class ApiController {
                #[Route('/items', name: 'items', methods: 'POST')]
                public function items(): void {}
                #[Route('/list', name: 'list')]
                public function list(): void {}
            }
            #[Route('/page')]
            final class PageController {
                #[Route('/view', name: 'view')]
                public function __invoke(): void {}
            }
            PHP);
        $pdo = $this->scan();

        $routes = [];
        foreach ($this->nodes($pdo) as $node) {
            if ($node['kind'] === 'route') {
                $routes[$node['canonical_name']] = json_decode($node['attributes_json'], true)['name'];
            }
        }
        ksort($routes);
        self::assertSame([
            'ANY /page/view => App\\Controller\\PageController::__invoke' => 'view',
            'GET /api/list => App\\Controller\\ApiController::list' => 'api_list',
            'GET /health => App\\Controller\\HealthController::__invoke' => 'health',
            'GET|POST /api/items => App\\Controller\\ApiController::items' => 'api_items',
        ], $routes);
        $targets = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['kind'] === 'routes_to') {
                $targets[] = $edge['target'] . ' [' . $edge['target_kind'] . ']';
            }
        }
        sort($targets);
        self::assertSame([
            'App\\Controller\\ApiController::items [method]',
            'App\\Controller\\ApiController::list [method]',
            'App\\Controller\\HealthController::__invoke [method]',
            'App\\Controller\\PageController::__invoke [method]',
        ], $targets);
    }

    private function scan(): PDO
    {
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        return $pdo;
    }

    /** @return list<array<string, mixed>> */
    private function nodes(PDO $pdo): array
    {
        return $pdo->query("SELECT n.kind, n.canonical_name, n.attributes_json FROM nodes n WHERE n.language = 'php' ORDER BY n.canonical_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function edges(PDO $pdo): array
    {
        return $pdo->query('SELECT e.kind, s.canonical_name source, t.canonical_name target, t.kind target_kind, e.confidence, e.start_line FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id ORDER BY e.id')->fetchAll(PDO::FETCH_ASSOC);
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
