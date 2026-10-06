<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\ProjectReference;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** One CLI argument names a project either way: its id, or a path inside it. */
#[Group('cli')]
final class ProjectReferenceTest extends KnossosTestCase
{
    public function testAnIdAPathAndASubdirectoryNameTheSameProject(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $reference = new ProjectReference($pdo, '/data/knossos.sqlite');
            $expected = ['id' => $projectId, 'root' => realpath($root)];
            self::assertSame($expected, $reference->resolve($projectId));
            self::assertSame($expected, $reference->resolve($root));
            self::assertSame($expected, $reference->resolve($root . '/src/Core'));
            // A directory named like an id is still a path when no such row exists.
            mkdir($root . '/project_x');
            self::assertSame($expected, $reference->resolve($root . '/project_x'));
            self::assertNull($reference->find('/nowhere/at/all'));
            try {
                $reference->resolve('project_nope');
                self::fail('An unknown argument resolved.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Project not found: project_nope (database: /data/knossos.sqlite)', $error->getMessage());
            }
        } finally {
            $this->removeTempTree($root);
        }
    }
}
