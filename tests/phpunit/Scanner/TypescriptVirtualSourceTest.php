<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component is read through virtual TypeScript that ends in declarations its
 * framework implies: SvelteKit's `$props()` for a route, a generic
 * component's type parameters, Astro's global. They are typed like the
 * component's own code, but written nowhere in it, so none of them is a node,
 * nothing refers to them, and no fact stands on a line past the file's end.
 */
#[Group('typescript-scanner')]
final class TypescriptVirtualSourceTest extends KnossosTestCase
{
    private const FILES = [
        'tsconfig.json' => '{"compilerOptions": {"strict": true}, "include": ["src"]}',
        'src/routes/+page.svelte' => "<script lang=\"ts\">\n  let { data } = \$props();\n</script>\n<h1>{data.title}</h1>\n<p>x</p>\n",
        'src/List.svelte' => "<script lang=\"ts\" generics=\"T extends { id: number }\">\n  let { items }: { items: T[] } = \$props();\n</script>\n{#each items as item}{item.id}{/each}\n",
        'src/Gen.vue' => "<script setup lang=\"ts\" generic=\"T extends string, U = number\">\ndefineProps<{ a: T; b: U }>();\n</script>\n<template><div /></template>\n",
        'src/Tag.astro' => "---\ntype Props = { name: string };\n---\n<span />\n",
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-virtual-tail-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        foreach (self::FILES as $relative => $contents) {
            $this->write($relative, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testDeclarationsAComponentsFrameworkImpliesAreNoFacts(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $phantoms = $pdo->query(
            "SELECT canonical_name FROM nodes WHERE canonical_name LIKE '%\$props%' OR canonical_name LIKE '%#T' OR canonical_name LIKE '%#U' ORDER BY 1",
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $phantoms);
        // The component's own declarations stay, and Astro still reads Props by name.
        self::assertSame(
            [['references', 'src/Tag.astro', 'src/Tag.astro#Props', 5]],
            $pdo->query(
                "SELECT e.kind, s.canonical_name, t.canonical_name, e.start_line FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'references' AND s.canonical_name = 'src/Tag.astro'",
            )->fetchAll(PDO::FETCH_NUM),
        );
    }

    public function testNoFactOfAComponentStandsPastItsLastLine(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $checked = 0;
        foreach (self::FILES as $relative => $contents) {
            if ($relative === 'tsconfig.json') {
                continue;
            }
            $lastLine = substr_count($contents, "\n") + 1;
            foreach (['nodes', 'edges'] as $table) {
                $facts = $pdo->prepare("SELECT x.start_line, x.end_line FROM {$table} x JOIN files f ON f.id = x.file_id WHERE f.relative_path = ?");
                $facts->execute([$relative]);
                foreach ($facts->fetchAll(PDO::FETCH_NUM) as [$start, $end]) {
                    self::assertLessThanOrEqual($lastLine, (int) $start, $relative);
                    self::assertLessThanOrEqual($lastLine, (int) $end, $relative);
                    ++$checked;
                }
            }
        }
        self::assertGreaterThanOrEqual(5, $checked);
    }

    public function testAnEditedComponentMatchesAFullScan(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->write('src/List.svelte', "<script lang=\"ts\" generics=\"T extends { id: number }, K = string\">\n  let { items, key }: { items: T[]; key: K } = \$props();\n</script>\n{#each items as item}{item.id}{key}{/each}\n");

        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        self::assertSame('incremental', $incremental->data['mode']);
        self::assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    private function scan(PDO $pdo): \Knossos\Result\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
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
