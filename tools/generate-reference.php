<?php

declare(strict_types=1);

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$check = ($argv[1] ?? '') === '--check';
$runtime = new RuntimeFactory($root);
$pdo = $runtime->database(':memory:');
$tools = new ToolService(
    new ProjectScanService($pdo, $root, [$root]),
    new ArchitectureQueryService($pdo),
    new DatabaseMaintenanceService($pdo, ':memory:'),
    new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
);

$process = proc_open([PHP_BINARY, $root . '/bin/knossos', 'help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to execute CLI help generator.');
}
$help = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || !is_string($help) || $help === '') {
    throw new RuntimeException('CLI help generation failed: ' . trim((string) $errors));
}

$cli = "# CLI reference\n\nThis file is generated from `bin/knossos help`; edit the application help, not this file.\n\n```text\n" . rtrim($help) . "\n```\n";
$mcp = "# MCP tool reference\n\nThis file is generated from the live `ToolService` definitions; edit the source schemas, not this file.\n\n";
foreach ($tools->definitions() as $definition) {
    $schema = $definition['inputSchema'];
    $required = array_fill_keys($schema['required'] ?? [], true);
    $mcp .= sprintf("## `%s`\n\n%s\n\n| Input | Type | Required | Constraints/default |\n| --- | --- | --- | --- |\n", $definition['name'], $definition['description']);
    foreach ($schema['properties'] ?? [] as $name => $property) {
        $type = is_array($property['type'] ?? null) ? implode(' or ', $property['type']) : ($property['type'] ?? 'any');
        $details = [];
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'maxItems', 'default'] as $constraint) {
            if (array_key_exists($constraint, $property)) {
                $details[] = $constraint . '=' . json_encode($property[$constraint], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        if (isset($property['enum'])) {
            $details[] = 'enum=' . implode(', ', array_map('strval', $property['enum']));
        }
        $mcp .= sprintf("| `%s` | %s | %s | %s |\n", $name, $type, isset($required[$name]) ? 'yes' : 'no', $details === [] ? '—' : implode('; ', $details));
    }
    if (($schema['properties'] ?? []) === []) {
        $mcp .= "| — | — | — | No inputs |\n";
    }
    $annotations = $definition['annotations'] ?? [];
    $mcp .= sprintf("\nAnnotations: read-only `%s`; destructive `%s`; idempotent `%s`; open-world `%s`.\n\n", $annotations['readOnlyHint'] ? 'yes' : 'no', $annotations['destructiveHint'] ? 'yes' : 'no', $annotations['idempotentHint'] ? 'yes' : 'no', $annotations['openWorldHint'] ? 'yes' : 'no');
}
$mcp = rtrim($mcp) . "\n";

$api = "# Language API reference\n\nThis file is generated from enforced PHP interface docblocks and the isolated TypeScript and Python worker surfaces.\n\n## PHP extension interfaces\n\n";
$interfaces = [];
foreach (glob($root . '/src/*/*.php') ?: [] as $path) {
    $source = (string) file_get_contents($path);
    if (preg_match('/namespace\s+([^;]+);/', $source, $namespace) === 1 && preg_match('/interface\s+(\w+)/', $source, $name) === 1) {
        $interface = $namespace[1] . '\\' . $name[1];
        if (interface_exists($interface)) {
            $interfaces[] = $interface;
        }
    }
}
sort($interfaces, SORT_STRING);
foreach ($interfaces as $interface) {
    $reflection = new ReflectionClass($interface);
    $api .= '### `' . $interface . "`\n\n";
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $interface) {
            continue;
        }
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = ($parameter->hasType() ? reflectionType($parameter->getType()) . ' ' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName() . ($parameter->isDefaultValueAvailable() ? ' = ' . json_encode($parameter->getDefaultValue(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '');
        }
        $return = $method->hasReturnType() ? ': ' . reflectionType($method->getReturnType()) : '';
        $api .= sprintf("- `%s(%s)%s` — %s\n", $method->getName(), implode(', ', $parameters), $return, documentationSummary((string) $method->getDocComment()));
    }
    $api .= "\n";
}

$javascript = (string) file_get_contents($root . '/workers/typescript/src/scanner.js');
$python = (string) file_get_contents($root . '/workers/python/bin/worker.py');
$api .= "## Isolated worker APIs\n\n| Runtime | Contract | Responsibility |\n| --- | --- | --- |\n";
foreach (['discover', 'scan'] as $method) {
    preg_match('/\/\*\*(.*?)\*\/\s*' . $method . '\s*\(/s', $javascript, $documentation);
    $api .= sprintf("| TypeScript | `TypeScriptScanner.%s` | %s |\n", $method, documentationSummary($documentation[1] ?? ''));
}
foreach (['PythonAstFactCollector' => 'class', 'scan' => 'def', 'discover' => 'def', 'handle' => 'def'] as $symbol => $kind) {
    preg_match('/^' . $kind . '\s+' . $symbol . '\b[^\n]*:\n\s+"""([^"\n]+)"""/m', $python, $documentation);
    $api .= sprintf("| Python | `%s` | %s |\n", $symbol, $documentation[1] ?? 'Missing documentation');
}
$api = rtrim($api) . "\n";

$outputs = [$root . '/docs/CLI-REFERENCE.md' => $cli, $root . '/docs/MCP-REFERENCE.md' => $mcp, $root . '/docs/API-REFERENCE.md' => $api];
$stale = [];
foreach ($outputs as $path => $contents) {
    if ($check) {
        if (!is_file($path) || file_get_contents($path) !== $contents) {
            $stale[] = str_replace($root . '/', '', $path);
        }
        continue;
    }
    file_put_contents($path, $contents);
}
if ($stale !== []) {
    fwrite(STDERR, 'Generated reference is stale: ' . implode(', ', $stale) . PHP_EOL);
    exit(1);
}
echo $check ? "Generated reference is current.\n" : "Generated CLI and MCP reference.\n";

function documentationSummary(string $documentation): string
{
    foreach (preg_split('/\R/', $documentation) ?: [] as $line) {
        $line = trim($line, " \t/*");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return rtrim($line, '.');
        }
    }
    return 'Missing documentation';
}

function reflectionType(?ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '') . $type->getName();
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(static fn(ReflectionType $member): string => reflectionType($member), $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(static fn(ReflectionType $member): string => reflectionType($member), $type->getTypes()));
    }
    return 'mixed';
}
