<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scan\CachedReads;
use Knossos\Scan\GlobalDeclarationEdits;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Which rescanned files changed what every file of their scanner sees: an
 * edited file that declares globally now, or did before.
 */
#[Group('scan')]
final class GlobalDeclarationEditsTest extends TestCase
{
    public function testAnEditedFileThatDeclaresGloballyNowOrBeforeIsNamed(): void
    {
        $cached = new CachedReads(
            [
                'o:became' => self::row('became.ts', 'G-old'),
                'o:was' => self::row('was.ts', 'G-old'),
                'o:plain' => self::row('plain.ts', 'G-old'),
                'o:same' => self::row('same.ts', 'G-old'),
                'o:reused' => self::row('reused.ts', 'G-old'),
            ],
            [],
            ['G-old' => ['was.ts' => self::hash('was.ts'), 'same.ts' => self::hash('same.ts'), 'reused.ts' => self::hash('reused.ts')]],
        );
        $groups = ['G-new' => ['became.ts' => self::hash('edited'), 'same.ts' => self::hash('same.ts')]];
        $entries = [
            self::entry('became.ts', self::hash('edited'), 'G-new'),
            self::entry('was.ts', self::hash('edited'), 'G-new'),
            self::entry('plain.ts', self::hash('edited'), 'G-new'),
            // Global, but rescanned only because something it read changed.
            self::entry('same.ts', self::hash('same.ts'), 'G-new'),
            self::entry('reused.ts', self::hash('edited'), 'G-old', [], true),
            // Added: the planner already rebuilt the scanner for it.
            self::entry('added.ts', self::hash('added'), 'G-new'),
        ];

        assertSame(['became.ts', 'was.ts'], GlobalDeclarationEdits::paths($entries, $groups, $cached));
        assertSame([], GlobalDeclarationEdits::paths($entries, $groups, null));
    }

    /**
     * An edit that brings a file into the shared reads the edited file's
     * earlier request never held, or drops a file it read that nothing reads
     * now, changes what the program declares globally. An edit that does
     * neither, and a scan that edits nothing, name no file.
     */
    public function testAnEditThatChangesTheProgramsFilesIsNamed(): void
    {
        $cached = new CachedReads(
            [
                'o:setup' => self::row('setup.ts', 'G-old'),
                'o:drops' => self::row('drops.ts', 'G-old'),
                'o:quiet' => self::row('quiet.ts', 'G-old'),
                'o:reader' => self::row('reader.ts', 'G-old'),
            ],
            [
                'o:drops' => ['node_modules/zone/index.d.ts' => self::hash('zone'), 'drops.tsx' => null],
                'o:quiet' => ['reader.ts' => self::hash('reader.ts')],
            ],
            ['G-old' => ['tsconfig.json' => self::hash('t'), 'node_modules/zone/index.d.ts' => self::hash('zone')]],
        );
        $quietOnly = [self::entry('quiet.ts', self::hash('edited'), 'G-new', ['reader.ts' => self::hash('reader.ts')])];
        $sameGroups = ['G-new' => ['tsconfig.json' => self::hash('t')]];

        assertSame([], GlobalDeclarationEdits::paths($quietOnly, $sameGroups, $cached));
        assertSame([], GlobalDeclarationEdits::paths([self::entry('reader.ts', self::hash('reader.ts'), 'G-new')], $sameGroups, $cached));
        assertSame(
            ['setup.ts'],
            GlobalDeclarationEdits::paths(
                [self::entry('setup.ts', self::hash('edited'), 'G-new', ['node_modules/aug/index.d.ts' => self::hash('aug')])],
                ['G-new' => ['tsconfig.json' => self::hash('t'), 'node_modules/aug/index.d.ts' => self::hash('aug')]],
                $cached,
            ),
        );
        assertSame(['drops.ts'], GlobalDeclarationEdits::paths([self::entry('drops.ts', self::hash('edited'), 'G-new')], $sameGroups, $cached));
    }

    /** @return array{scanner_id: string, file_path: string, content_hash: string, scanner_version: string, configuration_hash: string, read_attribution: bool, read_group: ?string} */
    private static function row(string $path, ?string $group): array
    {
        return [
            'scanner_id' => 'knossos.typescript',
            'file_path' => $path,
            'content_hash' => self::hash($path),
            'scanner_version' => '1',
            'configuration_hash' => '',
            'read_attribution' => true,
            'read_group' => $group,
        ];
    }

    /** @param array<string, ?string> $reads */
    private static function entry(string $path, string $hash, ?string $group, array $reads = [], bool $fromCache = false): ContributionCacheEntry
    {
        $owner = 'o:' . substr($path, 0, -3);

        return new ContributionCacheEntry($path, $hash, 'knossos.typescript', '1', 'cfg', new ScanContribution($owner), $reads, $group, true, $fromCache);
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
