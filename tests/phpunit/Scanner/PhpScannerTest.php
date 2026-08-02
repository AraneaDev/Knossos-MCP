<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

final class PhpScannerTest extends KnossosTestCase
{
    #[Group('php-scanner')]
    public function testPhpWorkerDiscoversComposerAndExtractsLabelledArchitecture(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $client = $this->phpWorkerClient();
        assertSame('knossos.php', $client->initialize()->id);
        assertSame(['composer.json'], $client->discover(['root' => $root])['config_files']);

        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/Architecture.php'],
        ]));
        $contribution = $contributions[0];
        $names = array_map(fn(NodeFact $node): string => $node->canonicalName, $contribution->nodes);
        sort($names, SORT_STRING);
        $expected = [
            'Fixture\\Invoice',
            'Fixture\\LogsPayments',
            'Fixture\\LogsPayments::audit',
            'Fixture\\Payable',
            'Fixture\\Payable::pay',
            'Fixture\\PaymentService',
            'Fixture\\PaymentService::__construct',
            'Fixture\\PaymentService::pay',
            'Fixture\\UserRepository',
            'Fixture\\UserRepository::save',
            'Fixture\\runPayment',
        ];
        sort($expected, SORT_STRING);
        assertSame($expected, $names);

        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contribution->edges,
        );
        assertArrayContains(
            ['implements', 'php:class:Fixture\\PaymentService', 'php:interface:Fixture\\Payable'],
            $edgeTuples,
        );
        assertArrayContains(
            ['uses_trait', 'php:class:Fixture\\PaymentService', 'php:trait:Fixture\\LogsPayments'],
            $edgeTuples,
        );
        assertArrayContains(
            ['injects', 'php:class:Fixture\\PaymentService', 'php:class:Fixture\\UserRepository'],
            $edgeTuples,
        );
        assertArrayContains(
            ['constructs', 'php:method:Fixture\\PaymentService::pay', 'php:class:Fixture\\Invoice'],
            $edgeTuples,
        );
        assertArrayContains(
            ['calls', 'php:method:Fixture\\PaymentService::pay', 'php:method:Fixture\\UserRepository::save'],
            $edgeTuples,
        );
        assertArrayContains(
            ['returns', 'php:method:Fixture\\PaymentService::pay', 'php:class:Fixture\\Invoice'],
            $edgeTuples,
        );
        assertSame([], $contribution->diagnostics);
        $client->shutdown();
    }

    /**
     * A static call, a constant fetch, a `::class` fetch, a static property
     * fetch, and an `instanceof` all name a class in an expression position.
     * Only the member was edged before, so a class or enum reached solely
     * through its static members carried an in-degree of zero and was reported
     * as an unreferenced-code candidate however heavily it was used.
     */
    #[Group('php-scanner')]
    public function testPhpWorkerReferencesTheClassNamedByStaticAccess(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/StaticAccess.php'],
        ]));
        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contributions[0]->edges,
        );

        // Ids::next(), Ids::PREFIX, Ids::class, and Ids::$counter each name Ids.
        assertArrayContains(
            ['references', 'php:method:Fixture\\Consumer::run', 'php:class:Fixture\\Ids'],
            $edgeTuples,
        );
        // The enum is reached only through a case fetch.
        assertArrayContains(
            ['references', 'php:method:Fixture\\Consumer::run', 'php:class:Fixture\\Mode'],
            $edgeTuples,
        );
        // The member edge is unchanged — the class edge is added beside it.
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Consumer::run', 'php:method:Fixture\\Ids::next'],
            $edgeTuples,
        );

        // `self::class` in Consumer::local() must not make Consumer look used.
        $selfReferences = array_filter(
            $edgeTuples,
            fn(array $tuple): bool => $tuple[0] === 'references'
                && $tuple[2] === 'php:class:Fixture\\Consumer',
        );
        assertSame([], array_values($selfReferences));
        $client->shutdown();
    }

    /**
     * `(new Foo())->bar()` names its receiver inline. Only variable, property,
     * and `$this` receivers were resolved before, so a method reached solely
     * through an immediately-constructed receiver carried no inbound call edge
     * and was reported as an unreferenced-code candidate.
     */
    #[Group('php-scanner')]
    public function testPhpWorkerResolvesCallsOnAnImmediatelyConstructedReceiver(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/ImmediateCall.php'],
        ]));
        $edges = $contributions[0]->edges;
        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $edges,
        );

        assertArrayContains(
            ['calls', 'php:method:Fixture\\Dispatcher::dispatch', 'php:method:Fixture\\Mailer::send'],
            $edgeTuples,
        );
        // The construction edge is unchanged — the call edge is added beside it.
        assertArrayContains(
            ['constructs', 'php:method:Fixture\\Dispatcher::dispatch', 'php:class:Fixture\\Mailer'],
            $edgeTuples,
        );

        // The receiver type is unambiguous at the call site, unlike a type
        // inferred from an earlier `$x = new Y` assignment.
        $call = array_values(array_filter(
            $edges,
            fn(EdgeFact $edge): bool => $edge->kind === 'calls'
                && $edge->sourceReference === 'php:method:Fixture\\Dispatcher::dispatch'
                && $edge->targetReference === 'php:method:Fixture\\Mailer::send',
        ));
        assertSame(1, count($call));
        assertSame('certain', $call[0]->confidence->value);

        // The anonymous-class receiver has no name to resolve, so it must emit
        // no call edge at all rather than one pointing at a made-up target.
        $anonymous = array_filter(
            $edgeTuples,
            fn(array $tuple): bool => $tuple[0] === 'calls'
                && $tuple[1] === 'php:method:Fixture\\Dispatcher::anonymous',
        );
        assertSame([], array_values($anonymous));
        $client->shutdown();
    }

    /**
     * `$x?->m()` is a distinct parser node from `$x->m()`, so nullsafe calls
     * produced no call edge at all — on either a variable or a property
     * receiver — however precisely the receiver was typed.
     */
    #[Group('php-scanner')]
    public function testPhpWorkerResolvesNullsafeCallsThroughDeclaredReceiverTypes(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/ImmediateCall.php'],
        ]));
        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contributions[0]->edges,
        );

        // `$mailer?->send()` — parameter receiver.
        // `$this->mailer?->send()` — promoted-property receiver.
        $calls = array_values(array_filter(
            $edgeTuples,
            fn(array $tuple): bool => $tuple[0] === 'calls'
                && $tuple[1] === 'php:method:Fixture\\Registry::notify'
                && $tuple[2] === 'php:method:Fixture\\Mailer::send',
        ));
        assertSame(2, count($calls));
        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerReportsParseErrorsWithoutExecutingProjectCode(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $marker = $root . '/src/EXECUTED';
        assertSame(false, file_exists($marker));

        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/Invalid.php', 'src/NoExecute.php'],
        ]));

        assertSame('PHP_PARSE_ERROR', $contributions[0]->diagnostics[0]->code);
        // By name, not by position: the fixture's own top-level call also
        // declares a module node, and which comes first is not the point here.
        assertArrayContains(
            'Fixture\\NoExecute',
            array_map(fn(NodeFact $node): string => $node->canonicalName, $contributions[1]->nodes),
        );
        assertSame(false, file_exists($marker));
        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerOutputIsDeterministicAndRejectsEscapingPaths(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $client = $this->phpWorkerClient();
        $request = ['root' => $root, 'files' => ['src/Architecture.php']];
        $first = iterator_to_array($client->scan($request));
        $second = iterator_to_array($client->scan($request));
        assertSame(
            json_encode($first, JSON_THROW_ON_ERROR),
            json_encode($second, JSON_THROW_ON_ERROR),
        );

        $error = captureThrows(
            fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../composer.json']])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);

        // A file over the byte cap is well-formed, so it costs only itself: the
        // request succeeds and the file arrives as a diagnostic-only contribution.
        $limited = $this->phpWorkerClient();
        $contributions = iterator_to_array($limited->scan([
            'root' => $root,
            'files' => ['src/Architecture.php'],
            'limits' => ['max_file_bytes' => 1],
        ]));
        assertSame(1, count($contributions));
        assertSame([], $contributions[0]->nodes);
        assertSame('PHP_UNSCANNABLE_FILE', $contributions[0]->diagnostics[0]->code);
    }

    #[Group('php-scanner')]
    public function testPhpWorkerValidatesEveryPublicRequestBoundary(): void
    {
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $server = new \KnossosPhpScanner\WorkerServer();
        $handle = new ReflectionMethod($server, 'handle');
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $invalidRequests = [
            [],
            ['method' => 'unknown', 'params' => []],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => 'invalid']],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => [], 'frameworks' => 'invalid']],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => [], 'limits' => ['max_files' => 0]]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => [1]]],
            ['method' => 'discover', 'params' => []],
            ['method' => 'discover', 'params' => ['root' => $root . '/missing']],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src//Architecture.php']]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['../composer.json']]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['/etc/passwd']]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['']]],
        ];
        foreach ($invalidRequests as $request) {
            assertThrows(fn() => $handle->invoke($server, $request), \KnossosPhpScanner\WorkerInputException::class);
        }
        // The other half of the contract — a well-formed path the worker cannot
        // scan is reported against that file and the request still succeeds — is
        // covered end to end over the real protocol by UnscannableFileTest.
        assertSame(null, $handle->invoke($server, ['method' => 'cancel', 'params' => []]));
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsLaravelRouteFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['routes/web.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $routeCanonicals = array_map(
            fn(NodeFact $node): string => $node->canonicalName,
            array_filter($contribution->nodes, fn(NodeFact $n): bool => $n->kind === 'route'),
        );
        assertArrayContains(
            'GET /shop/checkout => App\\Http\\Controllers\\CheckoutController::show',
            $routeCanonicals,
        );
        assertArrayContains(
            'GET|POST /matched => App\\Http\\Controllers\\CheckoutController::show',
            $routeCanonicals,
        );

        $middlewareNames = array_map(
            fn(NodeFact $node): string => $node->displayName,
            array_filter($contribution->nodes, fn(NodeFact $n): bool => $n->kind === 'middleware'),
        );
        assertArrayContains('web', $middlewareNames);
        assertArrayContains('auth', $middlewareNames);
        assertArrayContains('verified', $middlewareNames);

        $edgeKinds = array_map(fn(EdgeFact $e): string => $e->kind, $contribution->edges);
        assertArrayContains('routes_to', $edgeKinds);
        assertArrayContains('uses_middleware', $edgeKinds);

        assertSame(true, count($contribution->diagnostics) >= 2);
        $diagCodes = array_map(fn($d) => $d->code, $contribution->diagnostics);
        assertArrayContains('LARAVEL_DYNAMIC_ROUTE_URI', $diagCodes);
        assertArrayContains('LARAVEL_DYNAMIC_ROUTE', $diagCodes);

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsLaravelContainerAndProviderFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => [
                'app/Providers/AppServiceProvider.php',
                'app/Providers/EventServiceProvider.php',
            ],
            'frameworks' => ['laravel'],
        ]));

        // AppServiceProvider: binds + observes edges
        $appService = $contributions[0];
        $appEdgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $appService->edges,
        );
        assertArrayContains(
            ['binds', 'php:method:App\\Providers\\AppServiceProvider::register', 'php:class:App\\Services\\StripeGateway'],
            $appEdgeTuples,
        );
        assertArrayContains(
            ['observes', 'php:method:App\\Providers\\AppServiceProvider::boot', 'php:class:App\\Observers\\OrderObserver'],
            $appEdgeTuples,
        );

        // EventServiceProvider: listens_to + handles edges via provider maps
        $eventService = $contributions[1];
        $eventEdgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $eventService->edges,
        );
        assertArrayContains(
            ['listens_to', 'php:class:App\\Providers\\EventServiceProvider', 'php:class:App\\Events\\CheckoutCompleted'],
            $eventEdgeTuples,
        );
        assertArrayContains(
            ['handles', 'php:class:App\\Providers\\EventServiceProvider', 'php:class:App\\Models\\Order'],
            $eventEdgeTuples,
        );

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsLaravelDispatchFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['app/Http/Controllers/CheckoutController.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $contribution->edges,
        );
        // Static call: CheckoutCompleted::dispatch() in show() method
        assertArrayContains(
            ['dispatches', 'php:method:App\\Http\\Controllers\\CheckoutController::show', 'php:class:App\\Events\\CheckoutCompleted'],
            $edgeTuples,
        );

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerSkipsBusFacadeDispatchEdge(): void
    {
        // Laravel's Bus facade (Illuminate\\Bus\\Bus) ends with '\\Bus', so
        // LaravelDispatchFactCollector::staticFrameworkCall() skips creating
        // a 'dispatches' edge for Bus::dispatch(...). This tests the
        // negative branch of the str_ends_with guard.
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['app/Jobs/BusDispatcher.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $dispatchesEdges = array_filter(
            $contribution->edges,
            fn(EdgeFact $e): bool => $e->kind === 'dispatches',
        );
        assertSame([], $dispatchesEdges, 'Bus::dispatch() must not create a dispatches edge');

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerSkipsEventFacadeDispatchEdge(): void
    {
        // Laravel's Event facade (Illuminate\\Events\\Event) ends with '\\Event',
        // so LaravelDispatchFactCollector::staticFrameworkCall() skips creating
        // a 'dispatches' edge for Event::dispatch(...). This tests the
        // negative branch of the str_ends_with guard.
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel-event';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['EventDispatcher.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $dispatchesEdges = array_filter(
            $contribution->edges,
            fn(EdgeFact $e): bool => $e->kind === 'dispatches',
        );
        assertSame([], $dispatchesEdges, 'Event::dispatch() must not create a dispatches edge');

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsSymfonyMethodLevelAsMessageHandler(): void
    {
        // Method-level #[AsMessageHandler] on handle() enters via
        // $methodMessageHandler = true (not class-level handler +
        // __invoke). Exercises the second branch of the guard in
        // SymfonyFactCollector::enterMethod().
        $root = self::repositoryRoot() . '/tests/Fixtures/symfony-mh';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/MethodHandler.php'],
            'frameworks' => ['symfony'],
        ]));
        $contribution = $contributions[0];

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $contribution->edges,
        );
        assertArrayContains(
            ['handles_message', 'php:class:App\\MethodHandler', 'php:class:App\\InvoiceGenerated'],
            $edgeTuples,
        );

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsLaravelRouteEdgeCases(): void
    {
        // Exercises Route::any() (methods = ['ANY'], covers the
        // $methods = ['ANY'] branch), string-format controller actions
        // (Class@method str_contains path), and non-Route static calls
        // (isRouteFacade() returning false).
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['routes/edge-cases.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $routeCanonicals = array_map(
            fn(NodeFact $node): string => $node->canonicalName,
            array_filter($contribution->nodes, fn(NodeFact $n): bool => $n->kind === 'route'),
        );
        // Route::any('/any-catchall', ...) should produce 'ANY /any-catchall => ...'
        assertArrayContains(
            'ANY /any-catchall => App\\Http\\Controllers\\CheckoutController::show',
            $routeCanonicals,
        );
        // Route::get('/string-action', 'Controller@method') should parse the string action.
        // The label for string-form actions is the raw string (with @show), not the split format.
        assertArrayContains(
            'GET /string-action => App\\Http\\Controllers\\CheckoutController@show',
            $routeCanonicals,
        );

        // Only 2 route nodes — the string action is a single route
        assertSame(2, count(array_filter($contribution->nodes, fn(NodeFact $n): bool => $n->kind === 'route')));

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $contribution->edges,
        );
        // Both routes should have routes_to edges
        assertArrayContains(
            ['routes_to', 'php:route:ANY /any-catchall => App\\Http\\Controllers\\CheckoutController::show', 'php:method:App\\Http\\Controllers\\CheckoutController::show'],
            $edgeTuples,
        );
        // String action reference uses split format (Class::method), NOT @show
        assertArrayContains(
            ['routes_to', 'php:route:GET /string-action => App\\Http\\Controllers\\CheckoutController@show', 'php:method:App\\Http\\Controllers\\CheckoutController::show'],
            $edgeTuples,
        );

        assertSame([], $contribution->diagnostics);
        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsFreeFunctionDispatchEdge(): void
    {
        // dispatch() as a free function (not static call) exercises the
        // 'dispatch' name in LaravelDispatchFactCollector::functionDispatch().
        // The existing event() test covers the 'event' name, but dispatch()
        // as a function name was not covered.
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel-dispatch-func';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['FreeDispatch.php'],
            'frameworks' => ['laravel'],
        ]));
        $contribution = $contributions[0];

        $dispatchesEdges = array_filter(
            $contribution->edges,
            fn(EdgeFact $e): bool => $e->kind === 'dispatches',
        );
        // Both dispatch(new SomeEvent()) and event(new AnotherEvent()) should
        // create dispatches edges
        assertSame(2, count($dispatchesEdges), 'dispatch() and event() function calls must create dispatches edges');

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $dispatchesEdges,
        );
        assertArrayContains(
            ['dispatches', 'php:method:App\\FreeDispatch::handle', 'php:class:App\\SomeEvent'],
            $edgeTuples,
        );
        assertArrayContains(
            ['dispatches', 'php:method:App\\FreeDispatch::handle', 'php:class:App\\AnotherEvent'],
            $edgeTuples,
        );

        assertSame([], $contribution->diagnostics);
        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsSymfonyStringEventSubscriber(): void
    {
        // AsEventListener with string event name (not Class::class) exercises
        // the diagnostic branch in SymfonyFactCollector::enterClass() when
        // classArgument() returns null.
        // getSubscribedEvents with string key exercises the Scalar\String_
        // branch in eventReference().
        $root = self::repositoryRoot() . '/tests/Fixtures/symfony-string-events';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/EventListeners.php'],
            'frameworks' => ['symfony'],
        ]));
        $contribution = $contributions[0];

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $contribution->edges,
        );
        // StringEventSubscriber::getSubscribedEvents() with 'kernel.response' string key
        // should create a listens_to edge for the class
        assertArrayContains(
            ['listens_to', 'php:class:App\\StringEventSubscriber', 'php:event:kernel.response'],
            $edgeTuples,
        );

        // StringEventListener with AsEventListener(event: 'kernel.request') should
        // emit SYMFONY_DYNAMIC_EVENT diagnostic
        $diagCodes = array_map(fn($d) => $d->code, $contribution->diagnostics);
        assertArrayContains('SYMFONY_DYNAMIC_EVENT', $diagCodes);

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testPhpWorkerSkipsPathologicallyDeepFilesWithADiagnosticInsteadOfAFatal(): void
    {
        // A deeply nested literal would exhaust the native stack during the
        // recursive NameResolver/collector traversal (an uncatchable fatal that
        // corrupts the NDJSON channel). The depth pre-check skips it cleanly.
        $root = sys_get_temp_dir() . '/knossos-php-deep-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o700, true)) {
            throw new \RuntimeException('Unable to create deep PHP fixture.');
        }
        $nesting = 700;
        file_put_contents(
            $root . '/Deep.php',
            "<?php\n\$x = " . str_repeat('[', $nesting) . '1' . str_repeat(']', $nesting) . ";\n",
        );
        try {
            $client = $this->phpWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['Deep.php']]));
            $contribution = $contributions[0];
            assertSame([], $contribution->nodes);
            assertSame([], $contribution->edges);
            assertSame('PHP_AST_TOO_DEEP', $contribution->diagnostics[0]->code);
            $client->shutdown();
        } finally {
            @unlink($root . '/Deep.php');
            @rmdir($root);
        }
    }

    #[Group('php-scanner')]
    public function testPhpWorkerDegradesNonUtf8IdentifiersInsteadOfAbortingTheBatch(): void
    {
        // A class name carrying a raw ISO-8859-1 byte (0xE9) is valid on ext4 and
        // a legal PHP identifier, but invalid UTF-8. Without the substitute flag,
        // json_encode throws and aborts the whole request mid-stream.
        $root = sys_get_temp_dir() . '/knossos-php-utf8-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o700, true)) {
            throw new \RuntimeException('Unable to create non-UTF-8 PHP fixture.');
        }
        file_put_contents($root . '/Latin.php', "<?php\nnamespace Fx;\nclass Caf\xE9 {}\n");
        try {
            $client = $this->phpWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['Latin.php']]));
            assertSame(1, count($contributions));
            $classNodes = array_values(array_filter(
                $contributions[0]->nodes,
                fn(NodeFact $n): bool => $n->kind === 'class',
            ));
            assertSame(1, count($classNodes));
            // The bad byte degraded to U+FFFD rather than throwing a JsonException.
            assertContains("\u{FFFD}", $classNodes[0]->canonicalName);
            $client->shutdown();
        } finally {
            @unlink($root . '/Latin.php');
            @rmdir($root);
        }
    }

    #[Group('php-scanner')]
    public function testPhpWorkerLabelsFlowInferredCallsProbableAndInvalidatesReassignedVariables(): void
    {
        $root = sys_get_temp_dir() . '/knossos-php-flow-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o700, true)) {
            throw new \RuntimeException('Unable to create flow PHP fixture.');
        }
        file_put_contents($root . '/Flow.php', <<<'PHP'
        <?php

        namespace Fx;

        class A
        {
            public function m(): void {}
        }

        class B
        {
            public function run(): void
            {
                $x = new A();
                $x->m();
                $y = new A();
                // Reassigned to a value nothing declares a type for.
                $y = \getenv('ANYTHING');
                $y->m();
            }
        }
        PHP);
        try {
            $client = $this->phpWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['Flow.php']]));
            $contribution = $contributions[0];

            $callsToAm = array_values(array_filter(
                $contribution->edges,
                fn(EdgeFact $e): bool => $e->kind === 'calls'
                    && $e->sourceReference === 'php:method:Fx\\B::run'
                    && $e->targetReference === 'php:method:Fx\\A::m',
            ));
            // Only the live `$x` produces the edge; the reassigned `$y` does not.
            assertSame(1, count($callsToAm));
            // The `$x = new A()` flow inference is probable, not certain.
            assertSame('probable', $callsToAm[0]->confidence->value);
            $client->shutdown();
        } finally {
            @unlink($root . '/Flow.php');
            @rmdir($root);
        }
    }

    #[Group('php-scanner')]
    public function testPhpWorkerResetsRequestIdSoMalformedJsonIsNotAttributedToThePriorRequest(): void
    {
        // A valid request followed by a malformed line: the error frame must carry
        // a null id, not the previous request's id (verified via raw protocol).
        $root = self::repositoryRoot() . '/tests/Fixtures/php-scanner';
        $responses = $this->runPhpWorkerProtocol([
            json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => (object) []], JSON_THROW_ON_ERROR),
            'not-json',
            json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'shutdown', 'params' => (object) []], JSON_THROW_ON_ERROR),
        ], $root);
        $errors = array_values(array_filter($responses, fn(array $frame): bool => isset($frame['error'])));
        assertSame(1, count($errors));
        assertSame(null, $errors[0]['id']);
    }

    /** @param list<string> $messages @return list<array<string, mixed>> */
    private function runPhpWorkerProtocol(array $messages, string $root): array
    {
        $command = [PHP_BINARY, self::repositoryRoot() . '/workers/php/bin/worker'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start PHP worker protocol fixture.');
        }
        foreach ($messages as $message) {
            fwrite($pipes[0], $message . "\n");
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($stdout)))),
        );
    }

    #[Group('php-scanner')]
    public function testPhpWorkerExtractsSymfonyAttributeFacts(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/symfony';
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => ['src/Architecture.php'],
            'frameworks' => ['symfony'],
        ]));
        $contribution = $contributions[0];

        $nodeCanonicals = array_map(
            fn(NodeFact $node): string => $node->canonicalName,
            $contribution->nodes,
        );
        assertArrayContains(
            'GET|POST /shop/checkout => App\\CheckoutController::checkout',
            $nodeCanonicals,
        );
        assertArrayContains('app:reconcile', $nodeCanonicals);
        assertArrayContains('app.checkout_gateway', $nodeCanonicals);
        assertArrayContains('app.audit', $nodeCanonicals);

        $edgeTuples = array_map(
            fn(EdgeFact $e): array => [$e->kind, $e->sourceReference, $e->targetReference],
            $contribution->edges,
        );
        assertArrayContains(
            ['routes_to', 'php:route:GET|POST /shop/checkout => App\\CheckoutController::checkout', 'php:method:App\\CheckoutController::checkout'],
            $edgeTuples,
        );
        assertArrayContains(
            ['handles_message', 'php:class:App\\CheckoutHandler', 'php:class:App\\CheckoutRequested'],
            $edgeTuples,
        );

        $diagCodes = array_map(fn($d) => $d->code, $contribution->diagnostics);
        assertArrayContains('SYMFONY_DYNAMIC_ROUTE_PATH', $diagCodes);

        $client->shutdown();
    }
    #[Group('php-scanner')]
    public function testALocalAssignedFromAMethodWithADeclaredReturnTypeCarriesTheCall(): void
    {
        // Without this, a helper reached only through `$x = $this->make();
        // $x->use()` has no inbound edge and is reported as dead code. This
        // repository's own `ServerEnvironment::describe` and `::doctor` were
        // reported that way while `server_info` and `diagnose_runtime` called
        // them on every request.
        $client = $this->phpWorkerClient();
        $client->initialize();

        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/php-scanner',
            'files' => ['src/ReturnTypedLocal.php'],
        ]));
        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contributions[0]->edges,
        );

        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::post', 'php:method:Fixture\\Ledger::record'],
            $edgeTuples,
        );
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::postStatically', 'php:method:Fixture\\Ledger::record'],
            $edgeTuples,
        );
        // `$this->ledger()->record()` — chained, with no variable at all. This
        // is the shape `diagnose_runtime` uses, which is why the environment's
        // `doctor()` read as unreferenced.
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::postDirectly', 'php:method:Fixture\\Ledger::record'],
            $edgeTuples,
        );
        // `$enabled ? new Ledger() : null` then `$ledger?->record()`.
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::postOptionally', 'php:method:Fixture\\Ledger::record'],
            $edgeTuples,
        );
        // A later reassignment to an untracked value drops the inferred type,
        // so the only call attributed here is the one on the typed parameter.
        // Exactly one: the call on the typed parameter. A stale inferred type
        // would add a second, indistinguishable edge from the same source.
        $reassigned = array_values(array_filter(
            $edgeTuples,
            fn(array $tuple): bool => $tuple[0] === 'calls'
                && $tuple[1] === 'php:method:Fixture\\Accountant::postReassigned'
                && $tuple[2] === 'php:method:Fixture\\Ledger::record',
        ));
        assertSame(1, count($reassigned));

        // Where the receiver's type is declared in another file, the scanner
        // names the call it came from and leaves the resolution to the
        // reconciler, which is the only place every file's returns are known.
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::postThroughAbsentFactory', 'php:method_of_return:Fixture\\Elsewhere\\Registry::current::record'],
            $edgeTuples,
        );
        assertArrayContains(
            ['calls', 'php:method:Fixture\\Accountant::postThroughParameter', 'php:method_of_return:Fixture\\Registrar::ledger::record'],
            $edgeTuples,
        );
        // A ternary naming two different classes names no receiver at all.
        assertSame([], array_values(array_filter(
            $edgeTuples,
            fn(array $tuple): bool => $tuple[1] === 'php:method:Fixture\\Accountant::postAmbiguously' && $tuple[0] === 'calls',
        )));

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testCallsMadeAtFileScopeAreAttributedToTheFileModule(): void
    {
        // A procedural script — an entry point, a Laravel route file, a tools
        // script — calls things from file scope, where there is no enclosing
        // callable or class to attribute the edge to. Dropping those calls
        // leaves anything reached only that way with no inbound edge, which is
        // how this repository's own `declaredTypes` and `declaredFunctions`
        // came to be reported as dead while two tools scripts called them.
        $client = $this->phpWorkerClient();
        $client->initialize();

        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/php-scanner',
            'files' => ['src/TopLevelScript.php'],
        ]));
        $contribution = $contributions[0];
        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contribution->edges,
        );

        assertArrayContains(
            ['calls', 'php:module:src/TopLevelScript.php', 'php:function:knossosFixtureBootstrap'],
            $edgeTuples,
        );
        assertArrayContains(
            ['constructs', 'php:module:src/TopLevelScript.php', 'php:class:KnossosFixtureBootstrapper'],
            $edgeTuples,
        );
        assertArrayContains(
            ['calls', 'php:module:src/TopLevelScript.php', 'php:method:KnossosFixtureBootstrapper::boot'],
            $edgeTuples,
        );

        // The module is a real node so those edges have a source to resolve to.
        $modules = array_values(array_filter(
            $contribution->nodes,
            fn(NodeFact $node): bool => $node->kind === 'module',
        ));
        assertSame(1, count($modules));
        assertSame('src/TopLevelScript.php', $modules[0]->canonicalName);

        $client->shutdown();
    }

    #[Group('php-scanner')]
    public function testAFileWithoutFileScopeCallsDeclaresNoModule(): void
    {
        // The module exists to carry top-level edges. A file that only declares
        // types has none, and inventing a module for all 366 of this
        // repository's PHP files would add 366 unreferenced nodes to the graph.
        $client = $this->phpWorkerClient();
        $client->initialize();

        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/php-scanner',
            'files' => ['src/Architecture.php'],
        ]));
        $modules = array_values(array_filter(
            $contributions[0]->nodes,
            fn(NodeFact $node): bool => $node->kind === 'module',
        ));
        assertSame([], $modules);

        $client->shutdown();
    }
}
