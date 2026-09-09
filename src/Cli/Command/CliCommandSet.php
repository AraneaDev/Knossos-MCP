<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliHelpRenderer;

/**
 * Every command the CLI can dispatch, in the order the router tries them.
 *
 * It lives beside the commands rather than inside the router because the list
 * is the one thing that changes whenever a command is added, and the router's
 * job (validate the options, then hand off) does not change with it. Being in
 * the same namespace as the commands is also what keeps this from being a
 * second import list: nothing here needs a `use` line to name a sibling.
 *
 * Order is significant. The router takes the first command that claims the
 * name, so a broader `supports()` placed early would shadow a narrower one
 * behind it.
 */
final readonly class CliCommandSet
{
    /**
     * The commands to try, in order.
     *
     * @return list<CliCommand>
     */
    public static function all(CliHelpRenderer $help, string $version): array
    {
        return [
            new MetaCommand($help, $version),
            new ScanCommand(),
            new WatchCommand(),
            new BundleCommand(),
            new QueryCommand(),
            new SessionCommand(),
            new RootsCommand(),
            new PluginCommand(),
            new MaintenanceCommand(),
            new ServeCommand(),
        ];
    }
}
