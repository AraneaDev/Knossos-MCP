<?php

declare(strict_types=1);

use Knossos\Discovery\AllowedRoots;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\McpServerAssembly;
use Knossos\Runtime\RuntimeFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/mcp') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';
    return;
}

$rootsValue = getenv('KNOSSOS_ALLOWED_ROOTS');
$staticRoots = is_string($rootsValue) ? array_values(array_filter(explode(PATH_SEPARATOR, $rootsValue))) : [];
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
// Resolved after the runtime so the roots file can sit beside the database.
$allowedRoots = new AllowedRoots(
    $staticRoots,
    AllowedRoots::defaultConfigPath($runtime->defaultDatabasePath()),
);
if ($allowedRoots->current() === []) {
    // Same headers the 404 above and HttpEndpoint apply, and the roots-file path
    // that `serve` and `server_info` both name: an operator reading this in a
    // container log should not have to go looking for which file to create.
    http_response_code(500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'error' => sprintf(
            'No allowed roots. Set KNOSSOS_ALLOWED_ROOTS, or create %s containing {"roots": ["/absolute/path"]}.',
            (string) $allowedRoots->configPath(),
        ),
    ], JSON_UNESCAPED_SLASHES);
    return;
}
$pdo = $runtime->database();
// Shared with `serve`, so a capability cannot land on one transport only.
$assembly = new McpServerAssembly(
    $pdo,
    $runtime->installationRoot(),
    $runtime->defaultDatabasePath(),
    $allowedRoots,
);
$endpoint = new HttpEndpoint(
    $assembly->tools,
    new HttpSessionStore($pdo),
    $allowedHosts,
    $allowedOrigins,
    $token,
    resources: $assembly->resources(),
    prompts: $assembly->prompts(),
);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = file_get_contents('php://input', false, null, 0, 1_048_577);
$peer = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
try {
    $response = $endpoint->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $headers, is_string($body) ? $body : '', $peer);
} catch (\Throwable $error) {
    // Last-resort backstop: never leak a stack trace or internal message to the
    // client. Log the raw detail for the operator and return a generic error.
    error_log('knossos http-router: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    return;
}
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
