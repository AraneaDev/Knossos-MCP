<?php

declare(strict_types=1);

namespace Knossos\Runtime;

/**
 * The version this installation reports, to the CLI and to MCP clients.
 *
 * A file of its own, depending on nothing, so the transport and the runtime
 * report can read it without depending on the CLI entry point. release-please
 * bumps the marked line together with version.txt.
 */
final class Version
{
    public const CURRENT = '0.22.0'; // x-release-please-version

    private function __construct() {}
}
