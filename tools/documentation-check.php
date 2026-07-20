<?php

declare(strict_types=1);

/**
 * External URLs the gate deliberately does not fetch, each with the reason it
 * cannot resolve yet. Delete an entry as soon as its URL works so the link is
 * covered again — this list is a waiver, not a permanent exemption.
 */
const EXTERNAL_LINK_WAIVERS = [
    // MCP Observatory only serves a badge once it has indexed a public
    // repository, and Knossos is still pre-release.
    'https://mcpobservatory.com/servers/github:AraneaDev/Knossos-MCP/badge.svg' => 'repository not indexed yet',
];

$root = dirname(__DIR__);
$checkExternal = in_array('--external', $argv, true);
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
$waived = 0;
if ($checkExternal) {
    foreach (array_keys($external) as $url) {
        $process = proc_open(['curl', '--silent', '--show-error', '--location', '--fail', '--head', '--max-time', '20', $url], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $failures[] = 'unable to start external link checker';
            break;
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $reachable = proc_close($process) === 0;
        $waiver = EXTERNAL_LINK_WAIVERS[$url] ?? null;
        if ($waiver !== null) {
            // A waiver that has started resolving is stale: fail so the entry
            // gets deleted rather than quietly outliving its reason.
            if ($reachable) {
                $failures[] = 'external link waiver is stale, remove it from EXTERNAL_LINK_WAIVERS: ' . $url;
            } else {
                ++$waived;
            }
            continue;
        }
        if (!$reachable) {
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
    $waived > 0 ? sprintf(' (%d waived)', $waived) : '',
);

/**
 * Every committed Markdown file under docs/, excluding local-only working notes.
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
        if (str_contains($path, '/docs/superpowers/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files, SORT_STRING);

    return $files;
}

function relative(string $root, string $path): string
{
    return str_replace($root . '/', '', $path);
}
