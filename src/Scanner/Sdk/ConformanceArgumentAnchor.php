<?php

declare(strict_types=1);

namespace Knossos\Scanner\Sdk;

/**
 * Decides which arguments of a conformance-tool worker command are anchored
 * to the caller's directory.
 *
 * `tools/scanner-conformance` starts the worker in the system temporary
 * directory (see {@see \Knossos\Scanner\Worker\WorkerProcessSupervisor}), not
 * wherever the conformance tool itself was invoked from, so a worker path
 * written relative to the caller's directory would otherwise name nothing
 * there. Only an argument that plainly names a file is worth rewriting: one
 * containing a path separator, or one ending in a recognised script
 * extension. An empty string is never anchored, even though
 * `file_exists($directory . '/')` is true for any existing directory and
 * would otherwise rewrite it to the directory itself. A flag is never
 * anchored, but the value of a `--option=value` flag is, under the same rule
 * as a whole argument, since that is where a worker's own path options go.
 * Neither is a bare token with no slash and no recognised
 * extension, even when it happens to name a real entry in the caller's
 * directory: a bare name such as a scan mode argument or a `python3`
 * interpreter is not a path the caller wrote, and rewriting it on a
 * filesystem coincidence would be a surprise no rule announces.
 */
final class ConformanceArgumentAnchor
{
    private const SCRIPT_EXTENSIONS = ['php', 'py', 'js', 'mjs'];

    private function __construct() {}

    /**
     * Rewrites each argument that names a file relative to the caller's
     * directory into an absolute path under it, leaving every other argument
     * unchanged.
     *
     * @param list<string> $command
     * @return list<string>
     */
    public static function anchor(array $command, string $callerDirectory): array
    {
        foreach ($command as $position => $argument) {
            if (str_starts_with($argument, '-') && str_contains($argument, '=')) {
                [$flag, $value] = explode('=', $argument, 2);
                $command[$position] = $flag . '=' . self::anchored($value, $callerDirectory);
                continue;
            }
            $command[$position] = self::anchored($argument, $callerDirectory);
        }

        return $command;
    }

    /** One argument, or one flag's value, anchored when it names a file relative to the caller's directory. */
    private static function anchored(string $argument, string $callerDirectory): string
    {
        if (self::looksLikeCallerRelativePath($argument) && file_exists($callerDirectory . '/' . $argument)) {
            return $callerDirectory . '/' . $argument;
        }

        return $argument;
    }

    /**
     * Whether an argument plainly names a relative file: not empty, not a flag,
     * not absolute, and either containing a separator or ending in a
     * recognised script extension.
     */
    private static function looksLikeCallerRelativePath(string $argument): bool
    {
        if ($argument === '' || str_starts_with($argument, '-') || str_starts_with($argument, '/')) {
            return false;
        }
        if (str_contains($argument, '/')) {
            return true;
        }

        return in_array(pathinfo($argument, PATHINFO_EXTENSION), self::SCRIPT_EXTENSIONS, true);
    }
}
