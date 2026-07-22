<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('discovery-result')]
final class DiscoveryResultTest extends TestCase
{
    public function testAllFieldsAreStoredAndExposedAsReadonly(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'a.php',
            absolutePath: '/p/a.php',
            language: 'php',
            size: 1,
            mtime: 2,
            contentHash: 'h1',
        );
        $unit = new ProjectUnit(kind: 'tsconfig', configPath: 'tsconfig.json', contentHash: 'h2');
        $diagnostic = new DiscoveryDiagnostic(severity: 'info', code: 'ok', message: 'done');

        $result = new DiscoveryResult(
            rootRealpath: '/p',
            files: [$file],
            units: [$unit],
            diagnostics: [$diagnostic],
            inputHash: 'in',
            configurationHash: 'cfg',
        );

        assertSame('/p', $result->rootRealpath);
        assertSame([$file], $result->files);
        assertSame([$unit], $result->units);
        assertSame([$diagnostic], $result->diagnostics);
        assertSame('in', $result->inputHash);
        assertSame('cfg', $result->configurationHash);
    }

    public function testEmptyCollectionsAreStoredAsGiven(): void
    {
        $result = new DiscoveryResult(
            rootRealpath: '/q',
            files: [],
            units: [],
            diagnostics: [],
            inputHash: '',
            configurationHash: '',
        );

        assertSame([], $result->files);
        assertSame([], $result->units);
        assertSame([], $result->diagnostics);
        assertSame('', $result->inputHash);
        assertSame('', $result->configurationHash);
    }

    public function testCollectionsPreserveOrder(): void
    {
        $a = new DiscoveredFile(relativePath: 'a', absolutePath: '/a', language: 'php', size: 0, mtime: 0, contentHash: '0');
        $b = new DiscoveredFile(relativePath: 'b', absolutePath: '/b', language: 'php', size: 0, mtime: 0, contentHash: '0');
        $c = new DiscoveredFile(relativePath: 'c', absolutePath: '/c', language: 'php', size: 0, mtime: 0, contentHash: '0');

        $result = new DiscoveryResult(
            rootRealpath: '/r',
            files: [$a, $b, $c],
            units: [],
            diagnostics: [],
            inputHash: 'h',
            configurationHash: 'h',
        );

        assertSame(['a', 'b', 'c'], array_map(static fn (DiscoveredFile $f): string => $f->relativePath, $result->files));
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(DiscoveryResult::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}