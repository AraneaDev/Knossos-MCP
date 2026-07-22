<?php

declare(strict_types=1);

use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\ResourceService;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;

require dirname(__DIR__) . '/vendor/autoload.php';

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/mcp') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';
    return;
}

$rootsValue = getenv('KNOSSOS_ALLOWED_ROOTS');
$allowedRoots = is_string($rootsValue) ? array_values(array_filter(explode(PATH_SEPARATOR, $rootsValue))) : [];
if ($allowedRoots === []) {
    http_response_code(500);
    echo '{"error":"KNOSSOS_ALLOWED_ROOTS is required"}';
    return;
}
$hostsValue = getenv('KNOSSOS_HTTP_ALLOWED_HOSTS');
$allowedHosts = is_string($hostsValue) && $hostsValue !== ''
    ? array_map('strtolower', array_values(array_filter(array_map('trim', explode(',', $hostsValue)))))
    : ['127.0.0.1:8080', 'localhost:8080', '[::1]:8080'];
$originsValue = getenv('KNOSSOS_HTTP_ALLOWED_ORIGINS');
$allowedOrigins = is_string($originsValue) && $originsValue !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $originsValue))))
    : ['http://127.0.0.1:8080', 'http://localhost:8080', 'http://[::1]:8080'];
$tokenValue = getenv('KNOSSOS_HTTP_BEARER_TOKEN');
$token = is_string($tokenValue) && $tokenValue !== '' ? $tokenValue : null;

$runtime = new RuntimeFactory(dirname(__DIR__));
$pdo = $runtime->database();
$enricher = new \Knossos\Mcp\ResultEnricher(
    new \Knossos\Query\StalenessProbe($pdo),
    new \Knossos\Mcp\NextStepPlanner(),
);
$queries = new ArchitectureQueryService($pdo, gitHistory: new ProcessGitHistoryProvider());
$tools = new ToolService(
    new ProjectScanService($pdo, $runtime->installationRoot(), $allowedRoots),
    $queries,
    new DatabaseMaintenanceService($pdo, $runtime->defaultDatabasePath()),
    $enricher,
);
$endpoint = new HttpEndpoint($tools, new HttpSessionStore($pdo), $allowedHosts, $allowedOrigins, $token, resources: new ResourceService($queries));
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = file_get_contents('php://input', false, null, 0, 1_048_577);
$response = $endpoint->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $headers, is_string($body) ? $body : '');
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
