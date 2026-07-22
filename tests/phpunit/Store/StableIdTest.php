<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\StableId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('stable-id')]
final class StableIdTest extends TestCase
{
    public function testProjectReturnsPrefixAndDeterministicHash(): void
    {
        $id = StableId::project('my-app');

        $payload = $this->payload(['my-app']);
        assertSame('project_' . hash('sha256', $payload), $id);
    }

    public function testFileReturnsPrefixAndHash(): void
    {
        $id = StableId::file('proj-1', 'src/Foo.php');

        $payload = $this->payload(['proj-1', 'src/Foo.php']);
        assertSame('file_' . hash('sha256', $payload), $id);
    }

    public function testScanReturnsPrefixAndHash(): void
    {
        $id = StableId::scan('proj-1', 'nonce-abc');

        $payload = $this->payload(['proj-1', 'nonce-abc']);
        assertSame('scan_' . hash('sha256', $payload), $id);
    }

    public function testSymbolWithoutSignatureReturnsFourPartHash(): void
    {
        // Default signature='' is OMITTED from the parts list (the source skips empty parts).
        $id = StableId::symbol('proj-1', 'php', 'class', 'App\\Foo');

        $payload = $this->payload(['proj-1', 'php', 'class', 'App\\Foo']);
        assertSame('symbol_' . hash('sha256', $payload), $id);
    }

    public function testSymbolWithExplicitSignatureIncludesSignature(): void
    {
        $id = StableId::symbol('proj-1', 'php', 'class', 'App\\Foo', 'public function bar(): void');

        $payload = $this->payload(['proj-1', 'php', 'class', 'App\\Foo', 'public function bar(): void']);
        assertSame('symbol_' . hash('sha256', $payload), $id);
    }

    public function testSymbolWithEmptySignatureMatchesSymbolWithoutSignature(): void
    {
        // The source skips '' parts, so passing signature='' should equal not passing it.
        $withEmpty = StableId::symbol('proj-1', 'php', 'class', 'App\\Foo', '');
        $omitted = StableId::symbol('proj-1', 'php', 'class', 'App\\Foo');

        assertSame($omitted, $withEmpty);
    }

    public function testRouteSortsMethodsBeforeHashingSoOrderIsIrrelevant(): void
    {
        // sort() with SORT_STRING normalizes 'GET' vs 'POST' ordering → same hash regardless of input order.
        $a = StableId::route('proj-1', ['POST', 'GET'], '/users', 'UsersController::create');
        $b = StableId::route('proj-1', ['GET', 'POST'], '/users', 'UsersController::create');
        $c = StableId::route('proj-1', ['POST', 'GET'], '/users', 'UsersController::create');

        assertSame($a, $b);
        assertSame($b, $c);
        $this->assertStringStartsWith('route_', $a);
    }

    public function testRouteWithDifferentMethodsProducesDifferentHash(): void
    {
        $get = StableId::route('proj-1', ['GET'], '/users', 'list');
        $post = StableId::route('proj-1', ['POST'], '/users', 'list');

        assertNotSame($get, $post);
    }

    public function testEdgeReturnsPrefixAndHash(): void
    {
        $id = StableId::edge('proj-1', 'calls', 'symbol-a', 'symbol-b', 'evidence-x');

        $payload = $this->payload(['proj-1', 'calls', 'symbol-a', 'symbol-b', 'evidence-x']);
        assertSame('edge_' . hash('sha256', $payload), $id);
    }

    public function testClassificationReturnsPrefixAndHash(): void
    {
        $id = StableId::classification('proj-1', 'symbol-a', 'laravel.controller', 'laravel.explicit.types.v1');

        $payload = $this->payload(['proj-1', 'symbol-a', 'laravel.controller', 'laravel.explicit.types.v1']);
        assertSame('classification_' . hash('sha256', $payload), $id);
    }

    public function testBoundaryReturnsPrefixAndHash(): void
    {
        $id = StableId::boundary('proj-1', 'payments', 'explicit');

        $payload = $this->payload(['proj-1', 'payments', 'explicit']);
        assertSame('boundary_' . hash('sha256', $payload), $id);
    }

    public function testIdenticalInputsProduceIdenticalHashesAcrossCalls(): void
    {
        // StableId is a pure function factory — same inputs always yield same output.
        $first = StableId::project('my-app');
        $second = StableId::project('my-app');
        $third = StableId::project('my-app');

        assertSame($first, $second);
        assertSame($second, $third);
    }

    public function testDifferentInputsProduceDifferentHashes(): void
    {
        $a = StableId::project('app-a');
        $b = StableId::project('app-b');

        assertNotSame($a, $b);
    }

    public function testHashIsAlwaysSha256LengthAndHexFormat(): void
    {
        // SHA-256 hex digest is 64 lowercase hex characters; prefix is variable length.
        $ids = [
            StableId::project('a'),
            StableId::file('b', 'c'),
            StableId::scan('d', 'e'),
            StableId::symbol('f', 'g', 'h', 'i'),
            StableId::route('j', ['GET'], 'k', 'l'),
            StableId::edge('m', 'n', 'o', 'p', 'q'),
            StableId::classification('r', 's', 't', 'u'),
            StableId::boundary('v', 'w', 'x'),
        ];

        foreach ($ids as $id) {
            $hashPart = substr($id, strpos($id, '_') + 1);
            assertSame(64, strlen($hashPart));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashPart);
        }
    }

    public function testEmptyProjectIdentityThrows(): void
    {
        $error = captureThrows(static fn () => StableId::project(''), InvalidArgumentException::class);

        assertSame('project ID parts must not be empty.', $error->getMessage());
    }

    public function testEmptyFileRelativePathThrows(): void
    {
        $error = captureThrows(static fn () => StableId::file('proj-1', ''), InvalidArgumentException::class);

        assertSame('file ID parts must not be empty.', $error->getMessage());
    }

    public function testEmptyFileProjectIdThrows(): void
    {
        $error = captureThrows(static fn () => StableId::file('', 'src/Foo.php'), InvalidArgumentException::class);

        assertSame('file ID parts must not be empty.', $error->getMessage());
    }

    public function testEmptyScanNonceThrows(): void
    {
        $error = captureThrows(static fn () => StableId::scan('proj-1', ''), InvalidArgumentException::class);

        assertSame('scan ID parts must not be empty.', $error->getMessage());
    }

    public function testEmptySymbolKindThrows(): void
    {
        $error = captureThrows(
            static fn () => StableId::symbol('proj-1', 'php', '', 'App\\Foo'),
            InvalidArgumentException::class,
        );

        assertSame('symbol ID parts must not be empty.', $error->getMessage());
    }

    public function testEmptyRouteMethodsArrayThrows(): void
    {
        // implode(',', []) === '' which becomes one of the parts that the make() guard catches.
        // (Routing a non-empty methods array that contains an empty-string method does NOT throw,
        // because implode merges it away — e.g. ['GET', ''] sorts to ['', 'GET'] implodes to ',GET'.)
        $error = captureThrows(
            static fn () => StableId::route('proj-1', [], '/users', 'list'),
            InvalidArgumentException::class,
        );

        assertSame('route ID parts must not be empty.', $error->getMessage());
    }

    public function testConstructorIsPrivateSoTheClassCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(StableId::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        assertSame(true, $constructor->isPrivate());
        assertSame(true, $reflection->isFinal());
    }

    public function testEmptyEdgeEvidenceIdentityThrows(): void
    {
        $error = captureThrows(
            static fn () => StableId::edge('proj-1', 'calls', 'a', 'b', ''),
            InvalidArgumentException::class,
        );

        assertSame('edge ID parts must not be empty.', $error->getMessage());
    }

    // ----- helpers -----

    /**
     * Replicates the source's exact json_encode call (flags must match):
     *   json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
     */
    private function payload(array $parts): string
    {
        return json_encode(
            $parts,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
