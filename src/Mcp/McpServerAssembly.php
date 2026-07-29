<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Discovery\AllowedRoots;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
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
        $this->tools = new ToolService(
            new ProjectScanService($pdo, $installationRoot, $allowedRoots),
            $this->queries,
            $maintenance ?? new DatabaseMaintenanceService($pdo, $databasePath),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            new ServerEnvironment($allowedRoots, $databasePath, $installationRoot, $pdo),
        );
    }

    public function resources(): ResourceService
    {
        return new ResourceService($this->queries);
    }

    public function prompts(): PromptService
    {
        return new PromptService();
    }

    public function stdioServer(): StdioServer
    {
        return new StdioServer($this->tools, resources: $this->resources(), prompts: $this->prompts());
    }
}
