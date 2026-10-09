<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The manifest names the configuration guide lists are the ones discovery
 * treats as manifest roots, and they cover every kind it does.
 */
#[Group('discovery')]
final class ManifestRootDocumentationTest extends KnossosTestCase
{
    public function testTheGuideListsExactlyTheManifestKindsDiscoveryAnchorsOn(): void
    {
        $guide = (string) file_get_contents(self::repositoryRoot() . '/docs/get-started/project-configuration.md');
        $start = strpos($guide, 'A manifest root is a');
        self::assertNotFalse($start);
        $section = substr($guide, $start, (int) strpos($guide, 'A directory of that name anywhere else', $start) - $start);
        preg_match_all('/`([A-Za-z][A-Za-z0-9_.-]*\.(?:json|toml|txt))`/', $section, $matches);
        $names = $matches[1];

        self::assertNotSame([], $names);
        $kinds = [];
        foreach ($names as $name) {
            assertSame(true, ProjectDiscoverer::isManifest($name), $name);
            $kinds[] = (string) ProjectDiscoverer::unitKindFor($name);
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds);
        $expected = ProjectDiscoverer::MANIFEST_UNIT_KINDS;
        sort($expected);
        assertSame($expected, $kinds);
    }
}
