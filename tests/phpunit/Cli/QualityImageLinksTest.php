<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * CI runs the link check inside the quality image, which holds only what the
 * Dockerfile copies. A top-level page linked from README.md or CONTRIBUTING.md
 * that the image lacks fails the check there and nowhere else, as CHANGELOG.md
 * once did.
 */
final class QualityImageLinksTest extends KnossosTestCase
{
    /** Every top-level file or directory the two root pages link to is copied into the image. */
    #[Group('documentation')]
    public function testEveryTopLevelTargetTheRootPagesLinkToIsCopiedIntoTheImage(): void
    {
        $root = self::repositoryRoot();
        $copied = [];
        foreach (file($root . '/Dockerfile', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^COPY\s+(?!--from)(.+)$/', trim($line), $match) !== 1) {
                continue;
            }
            $sources = preg_split('/\s+/', trim($match[1])) ?: [];
            array_pop($sources);
            foreach ($sources as $source) {
                $copied[explode('/', $source)[0]] = true;
            }
        }
        assertNotSame([], $copied);
        $missing = [];
        foreach (['README.md', 'CONTRIBUTING.md'] as $page) {
            preg_match_all('/]\(([^) #]+)[^)]*\)/', (string) file_get_contents($root . '/' . $page), $links);
            foreach ($links[1] as $target) {
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                    continue;
                }
                $top = explode('/', ltrim($target, './'))[0];
                if ($top !== '' && file_exists($root . '/' . $top) && !isset($copied[$top])) {
                    $missing[$page . ' -> ' . $top] = true;
                }
            }
        }
        assertSame([], array_keys($missing), 'linked from a root page but not copied into the image');
    }
}
