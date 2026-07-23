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
        assertSame('Fixture\\NoExecute', $contributions[1]->nodes[0]->canonicalName);
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

        $limited = $this->phpWorkerClient();
        $error = captureThrows(
            fn() => iterator_to_array($limited->scan([
                'root' => $root,
                'files' => ['src/Architecture.php'],
                'limits' => ['max_file_bytes' => 1],
            ])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
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
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src/Architecture.php'], 'limits' => ['max_file_bytes' => 1]]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src//Architecture.php']]],
            ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src/Missing.php']]],
        ];
        foreach ($invalidRequests as $request) {
            assertThrows(fn() => $handle->invoke($server, $request), \KnossosPhpScanner\WorkerInputException::class);
        }
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
                $y = self::make();
                $y->m();
            }

            public static function make(): A
            {
                return new A();
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
}
