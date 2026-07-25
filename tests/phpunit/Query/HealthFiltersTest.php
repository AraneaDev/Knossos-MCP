<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class HealthFiltersTest extends KnossosTestCase
{
    #[Group('query')]
    public function testHubsExcludeExternalAndTestNodesByDefault(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // An external node referenced heavily (like the PHP builtin `count`).
        $external = StableId::symbol($ids['project'], 'php', 'external_function', 'count');
        $repository->saveNode($external, $ids['project'], 'php', 'external_function', 'count', 'count', null, $ids['file'], 1, 1, 'derived', 'possible', ['unresolved' => true], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['checkout'], $external, 'x:1'), $ids['project'], 'calls', $ids['checkout'], $external, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        // A test-role helper called by production code (like assertSame).
        $helper = StableId::symbol($ids['project'], 'php', 'function', 'assertWidgets');
        $repository->saveNode($helper, $ids['project'], 'php', 'function', 'assertWidgets', 'assertWidgets', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $helper, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $helper, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $ids['file'], 40, 44, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['invoice'], $helper, 'y:1'), $ids['project'], 'calls', $ids['invoice'], $helper, $ids['file'], 25, 25, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $default = $queries->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $hub): string => $hub['component']['canonical_name'], $default['hubs']);
        assertSame(false, in_array('count', $names, true));
        assertSame(false, in_array('assertWidgets', $names, true));
        assertSame(1, $default['bounds']['excluded_external_components']);
        assertSame(1, $default['bounds']['excluded_test_components']);
        $hotspotNames = array_map(static fn(array $spot): string => $spot['component']['canonical_name'], $default['static_hotspots']);
        assertSame(false, in_array('count', $hotspotNames, true));
        assertSame(false, in_array('assertWidgets', $hotspotNames, true));

        $included = $queries->architectureHealth($ids['project'], includeExternal: true, includeTests: true)->data;
        $allNames = array_map(static fn(array $hub): string => $hub['component']['canonical_name'], $included['hubs']);
        assertSame(true, in_array('count', $allNames, true));
        assertSame(true, in_array('assertWidgets', $allNames, true));
        assertSame(0, $included['bounds']['excluded_external_components']);
    }

    #[Group('query')]
    public function testHealthFlagsPassThroughToolDispatch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('architecture_health', ['project_id' => $ids['project'], 'include_external' => true, 'include_tests' => true]);
        assertSame(0, $result->data['bounds']['excluded_external_components']);
        assertThrows(fn() => $tools->call('architecture_health', ['project_id' => $ids['project'], 'include_tests' => 'yes']), \InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testDeadCodeExcludesMethodsDeclaredByAnInternalAncestor(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $gateway = StableId::symbol($ids['project'], 'php', 'interface', 'App\\PaymentGateway');
        $gatewayCharge = StableId::symbol($ids['project'], 'php', 'method', 'App\\PaymentGateway::charge');
        $stripe = StableId::symbol($ids['project'], 'php', 'class', 'App\\StripeGateway');
        $stripeCharge = StableId::symbol($ids['project'], 'php', 'method', 'App\\StripeGateway::charge');
        foreach ([
            [$gateway, 'interface', 'App\\PaymentGateway', 'PaymentGateway'],
            [$gatewayCharge, 'method', 'App\\PaymentGateway::charge', 'charge'],
            [$stripe, 'class', 'App\\StripeGateway', 'StripeGateway'],
            [$stripeCharge, 'method', 'App\\StripeGateway::charge', 'charge'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $gateway, $gatewayCharge, 'c1'), $ids['project'], 'contains', $gateway, $gatewayCharge, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $stripe, $stripeCharge, 'c2'), $ids['project'], 'contains', $stripe, $stripeCharge, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'implements', $stripe, $gateway, 'i1'), $ids['project'], 'implements', $stripe, $gateway, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $candidateNames = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        assertSame(false, in_array('App\\StripeGateway::charge', $candidateNames, true));
        assertSame(1, $data['bounds']['excluded_inherited_methods']);
    }

    #[Group('query')]
    public function testDeadCodeDemotesMethodsOfClassesWithExternalAncestors(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $visitor = StableId::symbol($ids['project'], 'php', 'external_interface', 'Vendor\\NodeVisitor');
        $collector = StableId::symbol($ids['project'], 'php', 'class', 'App\\FactCollector');
        $enterNode = StableId::symbol($ids['project'], 'php', 'method', 'App\\FactCollector::enterNode');
        $repository->saveNode($visitor, $ids['project'], 'php', 'external_interface', 'Vendor\\NodeVisitor', 'NodeVisitor', null, $file, 1, 1, 'derived', 'possible', ['unresolved' => true], $owner, $ids['scan']);
        $repository->saveNode($collector, $ids['project'], 'php', 'class', 'App\\FactCollector', 'FactCollector', null, $file, 1, 9, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveNode($enterNode, $ids['project'], 'php', 'method', 'App\\FactCollector::enterNode', 'enterNode', null, $file, 3, 5, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $collector, $enterNode, 'c3'), $ids['project'], 'contains', $collector, $enterNode, $file, 3, 3, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'implements', $collector, $visitor, 'i2'), $ids['project'], 'implements', $collector, $visitor, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $byName = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $byName[$candidate['component']['canonical_name']] = $candidate;
        }
        assertSame('possible', $byName['App\\FactCollector::enterNode']['confidence']);
        assertSame(true, str_contains($byName['App\\FactCollector::enterNode']['reason'], 'NodeVisitor'));
    }

    #[Group('query')]
    public function testDeadCodeExcludesConstructorsOfTypesThatAreReferenced(): void
    {
        // `new App\Invoice(...)` is recorded as a `constructs` edge to the CLASS,
        // never to `App\Invoice::__construct`, so every constructor in the graph
        // has in-degree 0. Reporting them buried the real candidates: on a scan
        // of a mid-sized TypeScript project five of thirteen surviving
        // candidates were constructors of demonstrably instantiated classes.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $invoice = StableId::symbol($ids['project'], 'php', 'class', 'App\\Invoice');
        $invoiceCtor = StableId::symbol($ids['project'], 'php', 'method', 'App\\Invoice::__construct');
        $orphan = StableId::symbol($ids['project'], 'php', 'class', 'App\\Orphan');
        $orphanCtor = StableId::symbol($ids['project'], 'php', 'method', 'App\\Orphan::__construct');
        foreach ([
            [$invoice, 'class', 'App\\Invoice', 'Invoice'],
            [$invoiceCtor, 'method', 'App\\Invoice::__construct', '__construct'],
            [$orphan, 'class', 'App\\Orphan', 'Orphan'],
            [$orphanCtor, 'method', 'App\\Orphan::__construct', '__construct'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $invoice, $invoiceCtor, 'k1'), $ids['project'], 'contains', $invoice, $invoiceCtor, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $orphan, $orphanCtor, 'k2'), $ids['project'], 'contains', $orphan, $orphanCtor, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        // Only App\Invoice is ever instantiated.
        $repository->saveEdge(StableId::edge($ids['project'], 'constructs', $ids['checkout'], $invoice, 'k3'), $ids['project'], 'constructs', $ids['checkout'], $invoice, $file, 7, 7, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertSame(false, in_array('App\\Invoice::__construct', $names, true));
        assertSame(1, $data['bounds']['excluded_constructors']);
        // A constructor of a type nothing references stays reportable — and so
        // does the unreferenced type itself, which is the unit worth deleting.
        assertArrayContains('App\\Orphan::__construct', $names);
        assertArrayContains('App\\Orphan', $names);
    }

    #[Group('query')]
    public function testDeadCodeExcludesEngineInvokedMembersOfTypesThatAreReferenced(): void
    {
        // A destructor runs when the runtime drops the last reference, and
        // `__toString` runs on a string cast — neither is ever named at a call
        // site, so both carry the same structural in-degree of zero a
        // constructor does. PHP reserves the `__` prefix for exactly these
        // engine-dispatched members, and Python spells its protocol methods
        // the same way (`__repr__`, `__enter__`, `__eq__`).
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $invoice = StableId::symbol($ids['project'], 'php', 'class', 'App\\Invoice');
        $destructor = StableId::symbol($ids['project'], 'php', 'method', 'App\\Invoice::__destruct');
        $toString = StableId::symbol($ids['project'], 'php', 'method', 'App\\Invoice::__toString');
        $plain = StableId::symbol($ids['project'], 'php', 'method', 'App\\Invoice::total');
        foreach ([
            [$invoice, 'class', 'App\\Invoice', 'Invoice'],
            [$destructor, 'method', 'App\\Invoice::__destruct', '__destruct'],
            [$toString, 'method', 'App\\Invoice::__toString', '__toString'],
            [$plain, 'method', 'App\\Invoice::total', 'total'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'constructs', $ids['checkout'], $invoice, 'm1'), $ids['project'], 'constructs', $ids['checkout'], $invoice, $file, 7, 7, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertSame(false, in_array('App\\Invoice::__destruct', $names, true));
        assertSame(false, in_array('App\\Invoice::__toString', $names, true));
        assertSame(2, $data['bounds']['excluded_constructors']);
        // An ordinary method of the same referenced class is still reportable —
        // the exclusion is about engine dispatch, not about the owning type.
        assertArrayContains('App\\Invoice::total', $names);
    }

    /**
     * A tool loads its own config by filename, so `vitest.config.ts` and its
     * kind have an in-degree of zero in every project — the same structural
     * blindness that made test modules candidates.
     */
    #[Group('query')]
    public function testDeadCodeExcludesToolConfigModules(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $config = StableId::symbol($ids['project'], 'typescript', 'module', 'vitest.config.ts');
        $repository->saveNode($config, $ids['project'], 'typescript', 'module', 'vitest.config.ts', 'vitest.config.ts', null, $ids['file'], 1, 20, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(
            StableId::classification($ids['project'], $config, 'tooling.config', 'core.tooling.config.v1'),
            $ids['project'],
            $config,
            'tooling.config',
            'derived',
            'probable',
            'core.tooling.config.v1',
            $ids['file'],
            1,
            20,
            [],
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertSame(false, in_array('vitest.config.ts', $names, true));
    }

    /**
     * The mirror of the inherited-method exclusion. That one drops the
     * override because the ancestor carries the contract; nothing dropped the
     * declaration when the implementation is what call sites reach. A call
     * only edges to the interface when its receiver is typed as the
     * interface — `foreach ($this->rules as $rule) $rule->classify(...)`
     * types nothing, so this repository reported `ClassificationRule::classify`
     * and five sibling contracts as unreferenced while ten implementations
     * ran on every scan.
     */
    #[Group('query')]
    public function testDeadCodeExcludesContractMethodsAnImplementationCarries(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $contract = StableId::symbol($ids['project'], 'php', 'interface', 'App\\Rule');
        $declaration = StableId::symbol($ids['project'], 'php', 'method', 'App\\Rule::classify');
        $implementation = StableId::symbol($ids['project'], 'php', 'class', 'App\\NameRule');
        $override = StableId::symbol($ids['project'], 'php', 'method', 'App\\NameRule::classify');
        foreach ([
            [$contract, 'interface', 'App\\Rule', 'Rule'],
            [$declaration, 'method', 'App\\Rule::classify', 'classify'],
            [$implementation, 'class', 'App\\NameRule', 'NameRule'],
            [$override, 'method', 'App\\NameRule::classify', 'classify'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $contract, $declaration, 'q1'), $ids['project'], 'contains', $contract, $declaration, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $implementation, $override, 'q2'), $ids['project'], 'contains', $implementation, $override, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'implements', $implementation, $contract, 'q3'), $ids['project'], 'implements', $implementation, $contract, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        // The contract itself is used: something names the interface as a type.
        $repository->saveEdge(StableId::edge($ids['project'], 'references', $ids['checkout'], $contract, 'q4'), $ids['project'], 'references', $ids['checkout'], $contract, $file, 5, 5, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertSame(false, in_array('App\\Rule::classify', $names, true));
        assertSame(1, $data['bounds']['excluded_contract_methods']);
    }

    /**
     * The exclusion is earned by an implementation, not by being an interface:
     * a contract nothing implements has nothing dispatching to it.
     */
    #[Group('query')]
    public function testDeadCodeKeepsAContractMethodNothingImplements(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $contract = StableId::symbol($ids['project'], 'php', 'interface', 'App\\Rule');
        $declaration = StableId::symbol($ids['project'], 'php', 'method', 'App\\Rule::classify');
        foreach ([
            [$contract, 'interface', 'App\\Rule', 'Rule'],
            [$declaration, 'method', 'App\\Rule::classify', 'classify'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $contract, $declaration, 'r1'), $ids['project'], 'contains', $contract, $declaration, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'references', $ids['checkout'], $contract, 'r2'), $ids['project'], 'references', $ids['checkout'], $contract, $file, 5, 5, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertArrayContains('App\\Rule::classify', $names);
        assertSame(0, $data['bounds']['excluded_contract_methods']);
    }

    /**
     * When nothing references the declaring type, the type and its members are
     * all reportable — the same call the constructor exclusion makes, for the
     * same reason: the type is the unit worth deleting.
     */
    #[Group('query')]
    public function testDeadCodeKeepsAContractMethodOfAnUnreferencedType(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        $contract = StableId::symbol($ids['project'], 'php', 'interface', 'App\\Rule');
        $declaration = StableId::symbol($ids['project'], 'php', 'method', 'App\\Rule::classify');
        $implementation = StableId::symbol($ids['project'], 'php', 'class', 'App\\NameRule');
        $override = StableId::symbol($ids['project'], 'php', 'method', 'App\\NameRule::classify');
        foreach ([
            [$contract, 'interface', 'App\\Rule', 'Rule'],
            [$declaration, 'method', 'App\\Rule::classify', 'classify'],
            [$implementation, 'class', 'App\\NameRule', 'NameRule'],
            [$override, 'method', 'App\\NameRule::classify', 'classify'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $contract, $declaration, 's1'), $ids['project'], 'contains', $contract, $declaration, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $implementation, $override, 's2'), $ids['project'], 'contains', $implementation, $override, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        // `implements` is the only inbound edge the interface has, and an
        // implementation is not evidence that anything uses the contract.
        $repository->saveEdge(StableId::edge($ids['project'], 'implements', $implementation, $contract, 's3'), $ids['project'], 'implements', $implementation, $contract, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);

        assertArrayContains('App\\Rule::classify', $names);
    }

    #[Group('query')]
    public function testDeadCodeSuppressionsFromProjectConfigAreHonored(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = StableId::symbol($ids['project'], 'php', 'class', 'App\\Legacy\\Exporter');
        $repository->saveNode($orphan, $ids['project'], 'php', 'class', 'App\\Legacy\\Exporter', 'Exporter', null, $ids['file'], 50, 60, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveProject($ids['project'], 'Fixture Shop', '/workspace/fixture-shop', ['dead_code_suppressions' => ['App\\Legacy\\*']]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        assertSame(false, in_array('App\\Legacy\\Exporter', $names, true));
        assertSame(1, $data['bounds']['suppressed_candidates']);
    }

    #[Group('query')]
    public function testDeadCodeIgnoresInDegreeMeasuredOnATruncatedEdgeSlice(): void
    {
        // In-degree is accumulated over the edge slice the scan actually read.
        // When that slice is cut short (edge/node/time limit) the dropped edges
        // make referenced symbols look unreferenced: a self-scan of this
        // repository reported five live PHP-scanner methods as dead purely
        // because the graph's 25k edges overran the 20k default.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        $reporter = StableId::symbol($ids['project'], 'php', 'class', 'App\\Reporter');
        $repository->saveNode($reporter, $ids['project'], 'php', 'class', 'App\\Reporter', 'Reporter', null, $ids['file'], 60, 70, 'ast', 'certain', [], $owner, $ids['scan']);
        // Two inbound edges, so a one-edge budget must drop exactly one of them.
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $reporter, $ids['checkout'], 't1'), $ids['project'], 'calls', $reporter, $ids['checkout'], $ids['file'], 61, 61, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'], maxEdges: 1);
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $result->data['dead_code_candidates']);

        assertSame(true, in_array('edge_limit', $result->data['bounds']['truncation_reasons'], true));
        // Whichever edge survived the cut, neither target is unreferenced.
        assertSame(false, in_array('App\\Checkout', $names, true));
        assertSame(false, in_array('App\\InvoiceService', $names, true));
        // The genuinely unreferenced class is still reported.
        assertArrayContains('App\\Reporter', $names);
    }
}
