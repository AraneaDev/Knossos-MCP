<?php

declare(strict_types=1);

require __DIR__ . '/lib/git-ignore.php';

$root = dirname(__DIR__);
$checkExternal = in_array('--external', $argv, true);

/**
 * Hosts whose URLs are never fetched by --external.
 *
 * These serve status badges and the pages behind them. A badge is decoration
 * whose liveness is the badge service's problem, not documentation a reader
 * navigates to, so its availability must not decide whether this repository's
 * quality gate passes. mcpobservatory.com reset the connection to a GitHub
 * runner once and failed the whole gate on a green tree; img.shields.io serves
 * eight more badges in README.md and is one outage away from doing the same.
 *
 * This skips the fetch only. Every URL is still collected and counted, and the
 * link syntax around it is still checked.
 */
const UNFETCHED_HOSTS = ['img.shields.io', 'mcpobservatory.com'];
$paths = array_merge([$root . '/README.md'], documentationFiles($root . '/docs'));
$failures = [];
$external = [];
foreach ($paths as $path) {
    $contents = (string) file_get_contents($path);
    preg_match_all('/```(?:sh|shell|bash)\n(.*?)```/s', $contents, $shellBlocks);
    foreach ($shellBlocks[1] as $shellBlock) {
        preg_match_all('#^\s*(?:php\s+)?((?:tools|bin)/[A-Za-z0-9._/-]+)#m', $shellBlock, $commands);
        foreach ($commands[1] as $commandPath) {
            $commandPath = rtrim($commandPath, '.,;:');
            if (!file_exists($root . '/' . $commandPath)) {
                $failures[] = relative($root, $path) . ': missing local command ' . $commandPath;
            }
        }
    }
    preg_match_all('/(?<!!)\[[^]]*]\(([^) ]+)(?:\s+"[^"]*")?\)/', $contents, $matches);
    foreach ($matches[1] as $target) {
        $target = trim($target, '<>');
        if (str_starts_with($target, 'https://')) {
            $external[$target] = true;
            continue;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            $failures[] = relative($root, $path) . ': unsupported or insecure link ' . $target;
            continue;
        }
        $file = rawurldecode(explode('#', $target, 2)[0]);
        if ($file === '') {
            continue;
        }
        $resolved = dirname($path) . '/' . $file;
        if (!file_exists($resolved)) {
            $failures[] = relative($root, $path) . ': missing link target ' . $target;
        }
    }
}
$fetched = array_values(array_filter(array_keys($external), static function (string $url): bool {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return !in_array($host, UNFETCHED_HOSTS, true);
}));
if ($checkExternal) {
    foreach ($fetched as $url) {
        $process = proc_open(['curl', '--silent', '--show-error', '--location', '--fail', '--head', '--max-time', '20', $url], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $failures[] = 'unable to start external link checker';
            break;
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $failures[] = 'external link failed: ' . $url . ' (' . trim((string) $error) . ')';
        }
    }
}
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
printf(
    "Documentation links passed: %d files, %d external%s%s.\n",
    count($paths),
    count($external),
    $checkExternal ? ' checked' : ' syntax-checked',
    $checkExternal && count($fetched) !== count($external)
        ? sprintf(' (%d badge-host URLs not fetched)', count($external) - count($fetched))
        : '',
);

/**
 * Every Markdown file under docs/ that this repository actually carries.
 *
 * Git-ignored files are skipped. `/docs/superpowers/` is ignored on purpose —
 * ".gitignore" calls it "Local-only superpowers specs and plans" — so a
 * developer's private planning notes used to be link-checked as though they
 * were published documentation, and one stale link in a scratch file failed the
 * whole suite for everyone who happened to have that file on disk. The check
 * exists to keep SHIPPED docs honest; it has no claim on a working copy.
 *
 * Untracked-but-not-ignored files are still checked: a doc added in the working
 * tree and not yet committed is on its way into the repository, and skipping it
 * would let a broken link land.
 *
 * @return list<string>
 */
function documentationFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $files[] = $path;
    }
    sort($files, SORT_STRING);
    $ignored = gitIgnoredPaths(dirname($directory), $files);

    return array_values(array_filter($files, static fn(string $path): bool => !isset($ignored[$path])));
}

/** A path shown relative to the repository root, so failures name what a reader can find. */
function relative(string $root, string $path): string
{
    return str_replace($root . '/', '', $path);
}
