<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The audit's PHP fixtures through the whole scan: each call reaches the
 * class its receiver holds in the scope it is made in.
 */
#[Group('php-scanner')]
final class PhpAuditFixturesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-php-audit-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * A closure or arrow function has its own variables: a typed parameter
     * types its calls, an assignment inside does not retype the variable
     * outside, and a captured variable keeps the type it had where captured.
     */
    public function testM26ClosureAndArrowFunctionScopes(): void
    {
        $this->write('src/Svc.php', <<<'PHP'
            <?php
            namespace App;
            class Foo { public function run(): void {} }
            class Bar { public function run(): void {} }
            class Svc {
                public function m(Bar $x, array $list): void {
                    array_map(fn (Foo $x) => $x->run(), $list);
                    array_map(function (Foo $y) { $x = new Foo(); $x->run(); }, $list);
                    $x->run();
                    $z = new Bar();
                    $f = function () use ($z) { $z->run(); };
                    $g = fn () => $z->run();
                    $h = function () { $z->run(); };
                    $k = function () use (&$z) { $z = new Foo(); };
                    $z->run();
                }
            }
            PHP);
        $pdo = $this->scan();

        $calls = [];
        foreach ($this->edges($pdo) as $edge) {
            if ($edge['kind'] === 'calls' && $edge['source'] === 'App\\Svc::m' && str_ends_with($edge['target'], '::run')) {
                $calls[] = $edge['start_line'] . ' ' . $edge['target'] . ' ' . $edge['confidence'];
            }
        }
        sort($calls);
        self::assertSame([
            // Captured, by `use` and implicitly.
            '11 App\\Bar::run probable',
            '12 App\\Bar::run probable',
            // The arrow function's parameter, not the method's `Bar $x`.
            '7 App\\Foo::run certain',
            '8 App\\Foo::run probable',
            // The method's `$x` is still the `Bar` it was declared as.
            '9 App\\Bar::run certain',
        ], $calls, 'line 13 types nothing, and line 15 may hold either class after a by-reference capture');
        $declared = $this->nodes($pdo);
        foreach ($declared as $node) {
            if (str_starts_with($node['canonical_name'], 'App\\')) {
                self::assertStringNotContainsString('external', $node['kind'], $node['canonical_name']);
            }
        }
        $references = array_values(array_filter(
            $this->edges($pdo),
            static fn(array $e): bool => $e['kind'] === 'references' && $e['source'] === 'App\\Svc::m' && $e['target'] === 'App\\Foo',
        ));
        self::assertNotSame([], $references, 'a closure parameter type is a class the method names');
    }

    private function scan(): PDO
    {
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        return $pdo;
    }

    /** @return list<array<string, mixed>> */
    private function nodes(PDO $pdo): array
    {
        return $pdo->query("SELECT n.kind, n.canonical_name, n.attributes_json FROM nodes n WHERE n.language = 'php' ORDER BY n.canonical_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function edges(PDO $pdo): array
    {
        return $pdo->query('SELECT e.kind, s.canonical_name source, t.canonical_name target, t.kind target_kind, e.confidence, e.start_line FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id ORDER BY e.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }
}
