<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('project-configuration')]
final class ProjectConfigurationTest extends TestCase
{
    public function testDefaultsAreAllNullOrEmptyWhenOmitted(): void
    {
        $config = new ProjectConfiguration();

        assertSame(null, $config->path);
        assertSame([], $config->ignores);
        assertSame(null, $config->maxFiles);
        assertSame(null, $config->maxFileBytes);
        assertSame(null, $config->workerTimeoutMs);
        assertSame([], $config->boundaries);
        assertSame([], $config->frameworks);
        assertSame(null, $config->snapshotRetention);
        assertSame([], $config->policies);
        assertSame([], $config->qualityBudgets);
    }

    public function testAllFieldsAreStoredAndExposedAsReadonly(): void
    {
        $config = new ProjectConfiguration(
            path: 'knossos.json',
            ignores: ['vendor/', '*.bak'],
            maxFiles: 50_000,
            maxFileBytes: 5_000_000,
            workerTimeoutMs: 30_000,
            boundaries: [['name' => 'Core', 'path_prefix' => 'src/Domain']],
            frameworks: ['laravel', 'symfony'],
            snapshotRetention: 7,
            policies: [['id' => 'no-cross-domain', 'from_boundary' => 'Core']],
            qualityBudgets: ['new_cycles' => 0, 'warning_diagnostics' => 50],
        );

        assertSame('knossos.json', $config->path);
        assertSame(['vendor/', '*.bak'], $config->ignores);
        assertSame(50_000, $config->maxFiles);
        assertSame(5_000_000, $config->maxFileBytes);
        assertSame(30_000, $config->workerTimeoutMs);
        assertSame([['name' => 'Core', 'path_prefix' => 'src/Domain']], $config->boundaries);
        assertSame(['laravel', 'symfony'], $config->frameworks);
        assertSame(7, $config->snapshotRetention);
        assertSame([['id' => 'no-cross-domain', 'from_boundary' => 'Core']], $config->policies);
        assertSame(['new_cycles' => 0, 'warning_diagnostics' => 50], $config->qualityBudgets);
    }

    public function testExplicitEmptyArraysAreStoredAsGiven(): void
    {
        $config = new ProjectConfiguration(
            path: 'knossos.jsonc',
            ignores: [],
            maxFiles: 100_000,
            maxFileBytes: 2_000_000,
            workerTimeoutMs: 60_000,
            boundaries: [],
            frameworks: [],
            snapshotRetention: 0,
            policies: [],
            qualityBudgets: [],
        );

        assertSame([], $config->ignores);
        assertSame([], $config->boundaries);
        assertSame([], $config->frameworks);
        assertSame([], $config->policies);
        assertSame([], $config->qualityBudgets);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ProjectConfiguration::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}