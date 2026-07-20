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
}
