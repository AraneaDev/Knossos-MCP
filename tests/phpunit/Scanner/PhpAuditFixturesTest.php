<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The audit's PHP scope, route and name-matching fixtures through the whole
 * scan: each call reaches the class its receiver holds in that scope, each
 * route carries what its groups and attributes give it, and a name written in
 * another case reaches the declaration it names.
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
     * A controller action without `@` is a controller, never a closure: a
     * method of the group's controller inside `Route::controller(...)`, and
     * the class's `__invoke` otherwise, however the class is written.
     */
    public function testLaravelStringActionsWithoutAt(): void
    {
        $this->write('composer.json', '{"require": {"laravel/framework": "^11.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');
        $this->write('app/Http/Controllers/UserController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass UserController { public function index() {} }\n");
        $this->write('app/Http/Controllers/InvokableController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass InvokableController { public function __invoke() {} }\n");
        $this->write('routes/web.php', <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            use App\Http\Controllers\InvokableController;
            use App\Http\Controllers\UserController;
            Route::get('/plain', InvokableController::class);
            Route::get('/plain-string', 'App\Http\Controllers\InvokableController');
            Route::group(['namespace' => 'App\Http\Controllers'], function () {
                Route::get('/inv', 'InvokableController');
            });
            Route::controller(UserController::class)->prefix('ctl')->group(function () {
                Route::get('/users', 'index');
            });
            Route::group(['controller' => UserController::class, 'prefix' => 'arr'], function () {
                Route::get('/users', 'index');
            });
            PHP);
        $pdo = $this->scan();

        $targets = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['kind'] === 'routes_to') {
                $targets[] = $edge['source'] . ' -> ' . $edge['target'] . ' [' . $edge['target_kind'] . ']';
            }
        }
        sort($targets);
        self::assertSame([
            'GET /arr/users => App\\Http\\Controllers\\UserController::index -> App\\Http\\Controllers\\UserController::index [method]',
            'GET /ctl/users => App\\Http\\Controllers\\UserController::index -> App\\Http\\Controllers\\UserController::index [method]',
            'GET /inv => App\\Http\\Controllers\\InvokableController -> App\\Http\\Controllers\\InvokableController::__invoke [method]',
            'GET /plain => App\\Http\\Controllers\\InvokableController -> App\\Http\\Controllers\\InvokableController::__invoke [method]',
            'GET /plain-string => App\\Http\\Controllers\\InvokableController -> App\\Http\\Controllers\\InvokableController::__invoke [method]',
        ], $targets);
        foreach ($this->nodes($pdo) as $node) {
            self::assertStringNotContainsString('=> closure', $node['canonical_name']);
        }
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

    /**
     * A class, function or method named in another case is the same symbol:
     * the edge reaches the declaration, which keeps its id and its spelling,
     * and an undeclared class named two ways is one external.
     */
    public function testL25NamesMatchWhateverTheirCase(): void
    {
        $this->writeL25Fixture();
        $pdo = $this->scan();

        $nodes = $this->nodes($pdo);
        $kinds = [];
        foreach ($nodes as $node) {
            $kinds[$node['canonical_name']] = $node['kind'];
        }
        self::assertSame('class', $kinds['App\\Foo'] ?? null);
        self::assertSame('method', $kinds['App\\Foo::doThing'] ?? null);
        self::assertSame('function', $kinds['App\\helper'] ?? null);
        $externals = array_keys(array_filter($kinds, static fn(string $kind): bool => str_starts_with($kind, 'external_')));
        sort($externals);
        self::assertSame(['DateTime', 'DateTime::createFromFormat'], $externals, 'no twin of a declared or an external symbol');

        $calls = [];
        foreach ($this->edges($pdo) as $edge) {
            if (in_array($edge['source'], ['App\\User2::run', 'App\\Child::go', 'App\\Foo::chain'], true) && in_array($edge['kind'], ['calls', 'constructs', 'references'], true)) {
                $calls[] = $edge['source'] . ' ' . $edge['kind'] . ' ' . $edge['target'] . ' [' . $edge['target_kind'] . ']';
            }
        }
        $calls = array_values(array_unique($calls));
        sort($calls);
        self::assertSame([
            'App\\Child::go calls App\\Foo::doThing [method]',
            // The class naming itself in another case is not a use of it.
            'App\\Foo::chain calls App\\Foo::doThing [method]',
            'App\\Foo::chain calls App\\Foo::make [method]',
            'App\\User2::run calls App\\Foo::doThing [method]',
            'App\\User2::run calls App\\Foo::make [method]',
            'App\\User2::run calls App\\helper [function]',
            'App\\User2::run calls DateTime::createFromFormat [external_method]',
            'App\\User2::run constructs App\\Foo [class]',
            'App\\User2::run constructs DateTime [external_class]',
            'App\\User2::run references App\\Foo [class]',
            'App\\User2::run references DateTime [external_class]',
        ], $calls);
    }

    /** A class name built at runtime from a namespace written in another case reaches the classes in it. */
    public function testL25NamespacePrefixMatchesWhateverItsCase(): void
    {
        $this->write('src/Cards/Ace.php', "<?php\nnamespace App\\Cards;\nclass Ace {}\n");
        $this->write('src/Deck.php', "<?php\nnamespace App;\nclass Deck { public function draw(string \$name): object { return new ('app\\\\cards\\\\' . \$name)(); } }\n");
        $pdo = $this->scan();

        $targets = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['source'] === 'App\\Deck::draw' && $edge['kind'] === 'references') {
                $targets[] = $edge['target'] . ' [' . $edge['target_kind'] . ']';
            }
        }
        self::assertSame(['App\\Cards\\Ace [class]'], $targets);
    }

    /** An incremental scan after a file changes the spellings in use equals a full scan of the result. */
    public function testL25IncrementalEqualsFull(): void
    {
        $this->writeL25Fixture();
        $pdo = $this->scan();
        $this->write('src/Use.php', str_replace('new \\DateTime()', 'new \\DATETIME()', (string) file_get_contents($this->root . '/src/Use.php')));
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
        $fresh = $this->scan();

        self::assertSame($this->graph($fresh), $this->graph($pdo));
        self::assertContains('DATETIME', array_column($this->nodes($fresh), 'canonical_name'), 'the only spelling left names the external');
    }

    private function writeL25Fixture(): void
    {
        $this->write('src/Foo.php', <<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function doThing(): void {}
                public static function make(): self { return new self(); }
                public function chain(): void { $made = $this->MAKE(); $made->doThing(); FOO::make(); }
            }
            function helper(): void {}
            PHP);
        $this->write('src/Use.php', <<<'PHP'
            <?php
            namespace App;
            class User2 {
                public function run(): void {
                    $f = new foo();
                    $f->DoThing();
                    FOO::MAKE();
                    \app\HELPER();
                    $d = \datetime::createFromFormat('Y', '2026');
                    $e = new \DateTime();
                }
            }
            class Child extends foo {
                public function go(): void { $this->DOTHING(); }
            }
            PHP);
    }

    /** @return list<string> */
    private function graph(PDO $pdo): array
    {
        $rows = array_map(static fn(array $n): string => $n['kind'] . ' ' . $n['canonical_name'], $this->nodes($pdo));
        foreach ($this->edges($pdo) as $e) {
            $rows[] = $e['kind'] . ' ' . $e['source'] . ' -> ' . $e['target'] . ' [' . $e['target_kind'] . '] ' . $e['confidence'] . ' @' . $e['start_line'];
        }
        $rows = array_values(array_unique($rows));
        sort($rows);

        return $rows;
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
