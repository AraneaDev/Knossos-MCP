<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Discovery\AllowedRoots;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\LedgeredScanner;
use Knossos\Query\StalenessProbe;
use Knossos\Runtime\ServerEnvironment;
use PDO;

/**
 * Assembles the object graph both transports need.
 *
 * The stdio command and the HTTP router previously built this twice, which is
 * how a capability lands on one transport and not the other. Keeping it in one
 * place also keeps the callers' own dependency fan-out honest.
 */
final readonly class McpServerAssembly
{
    public ArchitectureQueryService $queries;
    public ToolService $tools;

    /**
     * @param string $installationRoot where the packaged scanner workers live
     * @param string $databasePath the graph database, also used to site the roots file
     * @param DatabaseMaintenanceService|null $maintenance pass an existing service to share
     *        one instance with a CLI context; omitted, one is built for $databasePath
     */
    public function __construct(
        PDO $pdo,
        string $installationRoot,
        string $databasePath,
        AllowedRoots $allowedRoots,
        ?DatabaseMaintenanceService $maintenance = null,
    ) {
        $this->queries = new ArchitectureQueryService(
            $pdo,
            gitHistory: new ProcessGitHistoryProvider(),
            gitWorkingTree: new ProcessGitWorkingTreeProvider(),
        );
        $environment = new ServerEnvironment($allowedRoots, $databasePath, $installationRoot, $pdo);
        $this->tools = new ToolService(
            // Recorded in the scan ledger, so a turn whose edits the model scanned still reports them.
            LedgeredScanner::local($pdo, $installationRoot, $allowedRoots),
            $this->queries,
            $maintenance ?? new DatabaseMaintenanceService($pdo, $databasePath),
            // Confined: the probe reads files and runs git, so it stays inside the allowed roots.
            new ResultEnricher(new StalenessProbe($pdo, rootAdmitted: $environment->admitsRoot(...)), new NextStepPlanner()),
            $environment,
        );
    }

    /** Per-project orientation resources, reading through the same query facade as the tools. */
    public function resources(): ResourceService
    {
        return new ResourceService($this->queries);
    }

    /** The canned prompt catalogue. Pure data, so a fresh instance costs nothing. */
    public function prompts(): PromptService
    {
        return new PromptService();
    }

    /** A stdio server over this assembly's tools, resources, and prompts. */
    public function stdioServer(): StdioServer
    {
        return new StdioServer($this->tools, resources: $this->resources(), prompts: $this->prompts());
    }
}
