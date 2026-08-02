<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use RuntimeException;

/**
 * A minimum major version for one of the runtimes Knossos shells out to.
 *
 * The bound is a floor, never a range. Knossos drives Node, Python, and PHP
 * through stable interfaces — a version probe, a worker over NDJSON on stdio —
 * so a newer major is supported by default, and pinning an upper bound only made
 * `doctor` report a healthy installation as broken: it failed `node.version` for
 * being too new in the same report where that runtime's worker answered on the
 * expected protocol. Newer runtimes are therefore accepted silently; only a
 * version below the floor, or one that cannot be parsed at all, is an error.
 */
final readonly class RuntimeVersionRequirement
{
    /**
     * @param non-empty-string $pattern  regular expression whose first capture group is the comparable version prefix
     * @param non-empty-string $minimum  lowest version this release supports, compared with {@see version_compare}
     */
    public function __construct(private string $runtime, private string $pattern, private string $minimum) {}

    /**
     * Return the reported version, or throw when it is below the floor or unreadable.
     *
     * @throws RuntimeException
     */
    public function verify(string $version): string
    {
        $version = trim($version);
        if (preg_match($this->pattern, $version, $matches) !== 1) {
            throw new RuntimeException(sprintf('The %s version could not be determined from %s.', $this->runtime, var_export($version, true)));
        }
        if (version_compare($matches[1], $this->minimum, '<')) {
            throw new RuntimeException(sprintf('%s is unsupported; %s %s or newer is required.', $version, $this->runtime, $this->minimum));
        }

        return $version;
    }
}
