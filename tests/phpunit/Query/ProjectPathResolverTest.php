<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ProjectPathResolver;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ProjectPathResolverTest extends KnossosTestCase
{
    #[Group('query')]
    public function testResolvesExactRootAndSubdirectoryAndRejectsUnrelatedPath(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $resolver = new ProjectPathResolver($pdo);

        $exact = $resolver->resolve($root);
        assertSame($ids['project'], $exact['id'] ?? null);

        // A subdirectory of a scanned repository still finds its project: this is
        // the case the hook actually hits, because a session can start anywhere.
        $nested = $resolver->resolve($root . '/src/Deeply/Nested');
        assertSame($ids['project'], $nested['id'] ?? null);

        assertSame(null, $resolver->resolve('/definitely/not/a/scanned/project'));
    }
}
