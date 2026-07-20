<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PythonFrameworksTest extends KnossosTestCase
{
    #[Group('python-frameworks')]
    public function testPythonFrameworkEnrichmentExtractsFastapiDjangoDependenciesSettingsAndTasks(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python-frameworks';
        $files = [
            'app/__init__.py',
            'app/dependencies.py',
            'app/django_app.py',
            'app/fastapi_app.py',
            'app/settings.py',
        ];
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $diagnostics = array_merge(...array_map(fn(ScanContribution $item): array => $item->diagnostics, $contributions));
        $byCanonical = [];
        foreach ($nodes as $node) {
            $byCanonical[$node->canonicalName] = $node;
        }

        assertSame('fastapi', $byCanonical['GET /api/orders => app.fastapi_app.list_orders']->attributes['framework']);
        assertSame('django', $byCanonical['ANY /checkout/ => checkout_view']->attributes['framework']);
        assertSame(['django.model'], $byCanonical['app.django_app.Product']->attributes['python_framework_roles']);
        assertSame(['django.middleware'], $byCanonical['app.django_app.AuditMiddleware']->attributes['python_framework_roles']);
        assertSame(['python.task'], $byCanonical['app.django_app.reconcile_orders']->attributes['python_framework_roles']);
        assertSame(['app'], $byCanonical['app.settings.INSTALLED_APPS']->attributes['value']);

        $edgeTuples = array_map(fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $edges);
        assertArrayContains(['routes_to', 'py:route:GET /api/orders => app.fastapi_app.list_orders', 'py:function:app.fastapi_app.list_orders'], $edgeTuples);
        assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.require_admin'], $edgeTuples);
        assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.load_user'], $edgeTuples);
        assertArrayContains(['mounts', 'py:module:app.fastapi_app', 'py:router:app.fastapi_app.router'], $edgeTuples);
        assertArrayContains(['uses_middleware', 'py:module:app.fastapi_app', 'py:class:app.fastapi_app.AuthenticationMiddleware'], $edgeTuples);
        assertArrayContains(['routes_to', 'py:route:ANY /products/ => ProductView', 'py:class:app.django_app.ProductView'], $edgeTuples);
        assertArrayContains(['configures', 'py:module:app.settings', 'py:setting:app.settings.INSTALLED_APPS'], $edgeTuples);
        assertSame(['PY_DYNAMIC_ROUTE_PATH', 'PY_DYNAMIC_ROUTE_PATH'], array_column($diagnostics, 'code'));

        $database = tempnam(sys_get_temp_dir(), 'knossos-python-frameworks-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate Python framework database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Python Frameworks');
            assertSame(5, $result->data['parsed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'fastapi.route_handler'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.model'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.middleware'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'python.task'")->fetchColumn());
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }
}
