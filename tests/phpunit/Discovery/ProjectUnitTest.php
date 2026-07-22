<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('project-unit')]
final class ProjectUnitTest extends TestCase
{
    public function testAllFieldsAreStoredAndExposedAsReadonly(): void
    {
        $unit = new ProjectUnit(
            kind: 'laravel-route',
            configPath: 'routes/web.php',
            contentHash: 'cafebabe',
            metadata: ['method' => 'GET', 'path' => '/'],
        );

        assertSame('laravel-route', $unit->kind);
        assertSame('routes/web.php', $unit->configPath);
        assertSame('cafebabe', $unit->contentHash);
        assertSame(['method' => 'GET', 'path' => '/'], $unit->metadata);
    }

    public function testMetadataDefaultsToEmptyArrayWhenOmitted(): void
    {
        $unit = new ProjectUnit(
            kind: 'tsconfig',
            configPath: 'tsconfig.json',
            contentHash: 'beef0001',
        );

        assertSame([], $unit->metadata);
    }

    public function testEmptyMetadataArrayCanBeExplicit(): void
    {
        $unit = new ProjectUnit(
            kind: 'eslint',
            configPath: '.eslintrc.json',
            contentHash: 'beef0002',
            metadata: [],
        );

        assertSame([], $unit->metadata);
    }

    public function testNestedMetadataArrayIsStoredAsGiven(): void
    {
        $metadata = [
            'composer' => ['name' => 'vendor/pkg', 'version' => '1.0.0'],
            'tags' => ['cli', 'lib'],
            'count' => 3,
        ];

        $unit = new ProjectUnit(
            kind: 'composer-package',
            configPath: 'composer.json',
            contentHash: 'beef0003',
            metadata: $metadata,
        );

        assertSame($metadata, $unit->metadata);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ProjectUnit::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}