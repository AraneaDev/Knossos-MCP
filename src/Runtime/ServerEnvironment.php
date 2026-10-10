<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\RootGuard;
use PDO;

/**
 * What the server knows about itself: where it may read, where its graph lives,
 * and which runtime it is.
 *
 * Exists so an agent can answer "why was my path rejected, and what may I ask
 * for instead?" without shell access. That question is otherwise unanswerable
 * over MCP — the allow-list is invisible, and in a container the paths the agent
 * knows are not the paths the server has.
 */
final readonly class ServerEnvironment
{
    public function __construct(
        public AllowedRoots $roots,
        public string $databasePath,
        public string $installationRoot,
        private PDO $pdo,
    ) {}

    /**
     * Whether this server may read a project root: true unless the root exists
     * and lies outside the allowed roots, which are re-read on every call.
     */
    public function admitsRoot(string $root): bool
    {
        return !(new RootGuard($this->roots))->refuses($root);
    }

    /**
     * Everything an agent needs to know about this server's reach and identity.
     *
     * The transport owns the protocol revisions, so the caller passes in the
     * ones it supports right now.
     *
     * @param list<string> $protocolVersions the protocol revisions the transport accepts
     * @param bool $legacyProtocolEnabled whether the handshake-era revision is still accepted
     * @return array<string, mixed>
     */
    public function describe(array $protocolVersions, bool $legacyProtocolEnabled): array
    {
        $roots = $this->roots->describe();

        return [
            'name' => 'knossos',
            'version' => Version::CURRENT,
            'protocol_versions' => $protocolVersions,
            'legacy_protocol_enabled' => $legacyProtocolEnabled,
            'allowed_roots' => $roots,
            // Named even when no file exists yet: this is the path to create in
            // order to grant another project, and it is re-read per request.
            'roots_file' => $this->roots->configPath(),
            'database_path' => $this->databasePath,
            'data_directory' => dirname($this->databasePath),
            'containerised' => RootGuard::containerised(),
            'unreachable_roots' => array_values(array_map(
                static fn(array $root): string => $root['path'],
                array_filter($roots, static fn(array $root): bool => !$root['exists']),
            )),
        ];
    }
    /** The runtime health checker, built against this environment's database. */

    public function doctor(): DoctorService
    {
        return new DoctorService($this->pdo, $this->installationRoot, $this->databasePath);
    }
}
