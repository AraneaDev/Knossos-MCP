<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ResourceService;
use Knossos\Mcp\StdioServer;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ResourcesPromptsTest extends KnossosTestCase
{
    // Uses the Task 1 Fixtures-trait shape:
    // buildToolServiceWithScan returns [$tools, $projectId, $root, $pdo].

    #[Group('mcp')]
    public function testInitializeAdvertisesResourcesAndListReturnsPerProjectUris(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $server = new StdioServer($tools, resources: new ResourceService(new ArchitectureQueryService($pdo)));
            $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
            assertSame(['subscribe' => false, 'listChanged' => false], $init['result']['capabilities']['resources']);
            $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $list = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => []]);
            $uris = array_column($list['result']['resources'], 'uri');
            assertSame(true, in_array("knossos://{$projectId}/summary", $uris, true));
            assertSame(true, in_array("knossos://{$projectId}/brief", $uris, true));

            $read = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => "knossos://{$projectId}/summary"]]);
            $content = $read['result']['contents'][0];
            assertSame('application/json', $content['mimeType']);
            $decoded = json_decode($content['text'], true);
            assertSame($projectId, $decoded['project_id']);

            $brief = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/read', 'params' => ['uri' => "knossos://{$projectId}/brief"]]);
            assertSame('text/markdown', $brief['result']['contents'][0]['mimeType']);

            $missing = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/read', 'params' => ['uri' => 'knossos://project_' . str_repeat('0', 64) . '/summary']]);
            assertSame(-32002, $missing['error']['code']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('mcp')]
    public function testServerWithoutResourceServiceKeepsMethodNotFound(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $server = new StdioServer($tools);
            $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
            assertSame(false, array_key_exists('resources', $init['result']['capabilities']));
            $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
            $response = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => []]);
            assertSame(-32601, $response['error']['code']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('mcp')]
    public function testPromptsListAndGet(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $server = new StdioServer($tools, prompts: new \Knossos\Mcp\PromptService());
            $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
            assertSame(['listChanged' => false], $init['result']['capabilities']['prompts']);
            $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $list = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list', 'params' => []]);
            assertSame(['orient', 'review_diff'], array_column($list['result']['prompts'], 'name'));

            $get = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => ['name' => 'review_diff', 'arguments' => ['base_ref' => 'origin/main']]]);
            $text = $get['result']['messages'][0]['content']['text'];
            assertSame(true, str_contains($text, 'review_diff'));
            assertSame(true, str_contains($text, 'origin/main'));
            // A base_ref review must instruct passing base_ref directly (the
            // review_diff tool takes base_ref without a working_tree flag).
            assertSame(true, str_contains($text, 'pass base_ref: "origin/main"'));

            $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/get', 'params' => ['name' => 'nope']]);
            assertSame(-32602, $unknown['error']['code']);

            // Non-string argument values are filtered out before reaching PromptService,
            // so an int base_ref falls back to the default working-tree wording.
            $nonString = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/get', 'params' => ['name' => 'review_diff', 'arguments' => ['base_ref' => 123]]]);
            $nonStringText = $nonString['result']['messages'][0]['content']['text'];
            assertSame(false, str_contains($nonStringText, '123'));
            assertSame(true, str_contains($nonStringText, 'omit base_ref'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
