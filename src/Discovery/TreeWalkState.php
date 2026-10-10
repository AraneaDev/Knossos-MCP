<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * What one walk of a tree has gathered so far, and what it has still to visit.
 *
 * Held in one object so the steps of the tree walk that inspect
 * and record an entry share it, rather than each taking the lists it adds to
 * as separate by-reference arguments. It lives for one walk only.
 */
final class TreeWalkState
{
    /** @var list<DiscoveredFile> source files kept, in walk order */
    public array $files = [];

    /** @var list<ProjectUnit> units read, in walk order */
    public array $units = [];

    /** @var array<string, string> relative path => hash of a manifest read but not parsed into a unit */
    public array $unparsedManifestHashes = [];

    /** @var list<DiscoveryDiagnostic> */
    public array $diagnostics = [];

    /** @var array<string, FileContent> each `.gitignore` read, kept to hash as its unit */
    public array $gitIgnoreReads = [];

    /** Files counted against the discovery file limit. */
    public int $inputCount = 0;

    public readonly GitIgnoreRules $gitIgnore;

    /** @var list<string> directories still to walk, popped from the end */
    public array $stack;

    /** Starts at the resolved root, the first directory to walk. */
    public function __construct(public readonly string $root)
    {
        $this->gitIgnore = new GitIgnoreRules();
        $this->stack = [$root];
    }
}
