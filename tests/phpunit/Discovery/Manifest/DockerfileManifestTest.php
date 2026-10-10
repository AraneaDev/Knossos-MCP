<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\DockerfileManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('discovery')]
final class DockerfileManifestTest extends TestCase
{
    /**
     * A file copied into a directory (`COPY tools/report.mjs /opt/bin/`) keeps
     * its name there, so `/opt/bin/report.mjs` is that file; a directory
     * copied to a directory maps its contents.
     */
    public function testACopiedFileIsReadUnderItsNameInADirectoryDestination(): void
    {
        $rewrite = new \ReflectionMethod(DockerfileManifest::class, 'withCopySources');

        assertSame(
            "COPY tools/report.mjs /opt/bin/\nCOPY scripts/probe /tmp/probe/\nRUN node tools/report.mjs && python3 scripts/probe/check.py",
            $rewrite->invoke(null, "COPY tools/report.mjs /opt/bin/\nCOPY scripts/probe /tmp/probe/\nRUN node /opt/bin/report.mjs && python3 /tmp/probe/check.py"),
        );
    }
}
