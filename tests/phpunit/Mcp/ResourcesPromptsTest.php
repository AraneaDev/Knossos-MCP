<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ResourceService;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class ResourcesPromptsTest extends KnossosTestCase
{
    use StdioFrames;

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

    /**
     * A JSON error raised while answering a request was reported as a parse
     * error with id null, so the client waited forever for its answer. A
     * project name holding invalid UTF-8 is such an error: the resource
     * encoder now substitutes U+FFFD, and the request keeps its id.
     */
    #[Group('mcp')]
    public function testAResourceWithInvalidUtf8IsAnsweredUnderItsRequestId(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $pdo->prepare('UPDATE projects SET name = :name WHERE id = :project')
                ->execute(['name' => "shop\xff", 'project' => $projectId]);
            $server = new StdioServer($tools, resources: new ResourceService(new ArchitectureQueryService($pdo)));

            $read = $this->runFrames($server, $this->readSession("knossos://{$projectId}/summary"))[1];

            assertSame(7, $read['id']);
            assertSame(true, isset($read['result']), 'The resource must be answered, not replaced by an error.');
            $summary = json_decode($read['result']['contents'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            $readable = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            assertSame(true, str_contains($readable, "shop\u{FFFD}"), 'The invalid byte becomes U+FFFD.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A failure while handling a decoded request is an internal error answered under that request's id. */
    #[Group('mcp')]
    public function testAFailureWhileReadingAResourceKeepsTheRequestId(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $pdo->exec('ALTER TABLE projects RENAME TO projects_unreadable');
            $server = new StdioServer($tools, resources: new ResourceService(new ArchitectureQueryService($pdo)));

            $read = $this->runFrames($server, $this->readSession("knossos://{$projectId}/summary"))[1];

            assertSame(7, $read['id']);
            assertSame(-32603, $read['error']['code']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** resources/list stopped at 100 projects and gave no way to reach the rest. */
    #[Group('mcp')]
    public function testResourcesBeyondTheFirstHundredProjectsAreReachableByCursor(): void
    {
        [$tools, , $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $insert = $pdo->prepare(
                "INSERT INTO projects (id, name, root_realpath, created_at, updated_at) VALUES (:id, :name, :root, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')",
            );
            foreach (range(1, 100) as $n) {
                $insert->execute(['id' => 'project_' . hash('sha256', (string) $n), 'name' => 'extra-' . $n, 'root' => '/nonexistent/extra-' . $n]);
            }
            $server = $this->initializedResourceServer($tools, $pdo);

            $first = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => []]);
            assertSame(300, count($first['result']['resources']));
            assertSame(true, is_string($first['result']['nextCursor']));
            $second = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list', 'params' => ['cursor' => $first['result']['nextCursor']]]);

            assertSame(3, count($second['result']['resources']));
            assertSame(false, array_key_exists('nextCursor', $second['result']));
            $uris = array_column([...$first['result']['resources'], ...$second['result']['resources']], 'uri');
            assertSame(303, count(array_unique($uris)), 'No project is listed twice or skipped.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A cursor is opaque, but only the server's own cursors decode: anything
     * else, or one that addresses an offset past the catalog's bound, is invalid params.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'not a cursor' => ['not-a-cursor'];
        yield 'not a string' => [42];
        yield 'empty' => [''];
        yield 'negative offset' => [rtrim(strtr(base64_encode('offset:-1'), '+/', '-_'), '=')];
        yield 'past the bound' => [rtrim(strtr(base64_encode('offset:100001'), '+/', '-_'), '=')];
        yield 'not a number' => [rtrim(strtr(base64_encode('offset:ten'), '+/', '-_'), '=')];
    }

    #[Group('mcp')]
    #[DataProvider('invalidCursors')]
    public function testAnInvalidCursorIsInvalidParams(mixed $cursor): void
    {
        [$tools, , $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $server = $this->initializedResourceServer($tools, $pdo);

            $response = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => ['cursor' => $cursor]]);

            assertSame(-32602, $response['error']['code']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The last offset the catalog accepts is still a valid cursor; it lists what lies there (nothing). */
    #[Group('mcp')]
    public function testACursorAtTheBoundIsValid(): void
    {
        [$tools, , $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $server = $this->initializedResourceServer($tools, $pdo);

            $response = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => [
                'cursor' => rtrim(strtr(base64_encode('offset:100000'), '+/', '-_'), '='),
            ]]);

            assertSame([], $response['result']['resources']);
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

    #[Group('mcp')]
    public function testPromptsGetOrientVariants(): void
    {
        $prompts = new \Knossos\Mcp\PromptService();

        $withPath = $prompts->get('orient', ['path' => 'some/path']);
        $withPathText = $withPath['messages'][0]['content']['text'];
        assertSame('Architecture orientation workflow', $withPath['description']);
        assertSame(true, str_contains($withPathText, 'Call scan_project with path "some/path"'));
        assertSame(true, str_contains($withPathText, 'Call architecture_health'));

        $withoutPath = $prompts->get('orient', []);
        $withoutPathText = $withoutPath['messages'][0]['content']['text'];
        assertSame(true, str_contains($withoutPathText, 'call list_projects to find the project_id'));
        assertSame(true, str_contains($withoutPathText, 'Call architecture_health'));
    }

    #[Group('mcp')]
    public function testResourceServiceReadHandlesInvalidUriAndBoundariesSection(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $service = new ResourceService(new ArchitectureQueryService($pdo));

            assertSame(null, $service->read('not-a-valid-uri'));

            $boundaries = $service->read("knossos://{$projectId}/boundaries");
            $content = $boundaries['contents'][0];
            assertSame('application/json', $content['mimeType']);
            $decoded = json_decode($content['text'], true);
            assertSame(true, is_array($decoded));
        } finally {
            $this->removeTempTree($root);
        }
    }

    private function initializedResourceServer(ToolService $tools, \PDO $pdo): StdioServer
    {
        $server = new StdioServer($tools, resources: new ResourceService(new ArchitectureQueryService($pdo)));
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $server;
    }

    /**
     * The stdio lines of a session that initializes and then reads one resource as request 7.
     *
     * @return list<string>
     */
    private function readSession(string $uri): array
    {
        return array_map(
            static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR) . "\n",
            [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/read', 'params' => ['uri' => $uri]],
            ],
        );
    }
}
