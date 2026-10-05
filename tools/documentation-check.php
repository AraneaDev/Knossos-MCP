<?php

declare(strict_types=1);

require __DIR__ . '/lib/git-ignore.php';

$root = dirname(__DIR__);
foreach ($argv as $argument) {
    // A throwaway tree, so the checker itself can be tested against fixtures.
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/');
    }
}
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
/**
 * The directories whose Markdown is checked. Each must exist: a root missing
 * from the tree fails the check by name, so an image that leaves one out (the
 * quality image once left out plugins/) cannot pass without checking it.
 */
const DOCUMENTATION_ROOTS = ['docs', 'skills', 'plugins'];
$failures = [];
$paths = array_values(array_filter([$root . '/README.md', $root . '/CONTRIBUTING.md'], 'is_file'));
foreach (DOCUMENTATION_ROOTS as $documentationRoot) {
    if (!is_dir($root . '/' . $documentationRoot)) {
        $failures[] = 'missing documentation root ' . $documentationRoot . '/';
        continue;
    }
    $paths = array_merge($paths, documentationFiles($root, $documentationRoot));
}
$headingSlugs = [];
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
    // `](target)` matches links and images alike, and both halves of the nested
    // `[![alt](image)](href)` badge form. Fenced code is not prose.
    $prose = implode("\n", linesOutsideFences($contents));
    preg_match_all('/]\(([^) ]+)(?:\s+"[^"]*")?\)/', $prose, $matches);
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
        $parts = explode('#', $target, 2);
        $file = rawurldecode($parts[0]);
        $fragment = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        $resolved = $file === '' ? $path : dirname($path) . '/' . $file;
        if ($file !== '' && !file_exists($resolved)) {
            $failures[] = relative($root, $path) . ': missing link target ' . $target;
            continue;
        }
        if ($fragment === '' || !is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'md') {
            continue;
        }
        $headingSlugs[$resolved] ??= headingSlugs((string) file_get_contents($resolved));
        // `#L12` and `#L12-L20` point at a line, which GitHub resolves itself.
        if (preg_match('/^L\d+(?:-L\d+)?$/', $fragment) !== 1 && !isset($headingSlugs[$resolved][strtolower($fragment)])) {
            $failures[] = relative($root, $path) . ': missing anchor ' . $target;
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
 * Run once per documentation root (`docs`, `skills`, `plugins`), so a link in a
 * skill or plugin copy is held to the same standard as one in the manual.
 *
 * @return list<string>
 */
function documentationFiles(string $root, string $directory): array
{
    $directory = $root . '/' . $directory;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/node_modules/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files, SORT_STRING);
    $ignored = gitIgnoredPaths($root, $files);

    return array_values(array_filter($files, static fn(string $path): bool => !isset($ignored[$path])));
}

/**
 * The anchors a Markdown page offers, by GitHub's rules, as a set keyed by slug.
 *
 * Only ATX headings outside fenced code count. The slug is the heading text
 * lower-cased, with everything but letters, digits, underscores, spaces and
 * hyphens removed and spaces turned into hyphens. A repeated slug gets `-1`,
 * `-2` and so on.
 *
 * @return array<string, true>
 */
function headingSlugs(string $contents): array
{
    $slugs = [];
    $seen = [];
    foreach (linesOutsideFences($contents) as $line) {
        if (preg_match('/^ {0,3}#{1,6}[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/', $line, $heading) !== 1) {
            continue;
        }
        $text = (string) preg_replace('/!?\[([^]]*)]\([^)]*\)/', '$1', $heading[1]);
        $text = strtolower(str_replace('`', '', $text));
        $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N}_ -]/u', '', $text));
        $count = $seen[$slug] ?? 0;
        $seen[$slug] = $count + 1;
        $slugs[$count === 0 ? $slug : $slug . '-' . $count] = true;
    }

    return $slugs;
}

/** A path shown relative to the repository root, so failures name what a reader can find. */
function relative(string $root, string $path): string
{
    return str_replace($root . '/', '', $path);
}

/**
 * The lines of a Markdown page that are not fenced code, by the CommonMark rules.
 *
 * An opener is up to three spaces of indent and a run of three or more backticks
 * or tildes; a backtick opener's info string may not hold a backtick, which is
 * what keeps an inline "```code``` is a thing" in prose. The closer uses the same
 * character, is at least as long as the opener and has no info string. A fence
 * that never closes runs to the end of the file. The fence lines are dropped too.
 *
 * @return list<string>
 */
function linesOutsideFences(string $contents): array
{
    $kept = [];
    $char = null;
    $length = 0;
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if ($char === null) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $open) === 1 && !($open[1][0] === '`' && str_contains($open[2], '`'))) {
                $char = $open[1][0];
                $length = strlen($open[1]);
                continue;
            }
            $kept[] = $line;
            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $line, $close) === 1 && $close[1][0] === $char && strlen($close[1]) >= $length) {
            $char = null;
        }
    }

    return $kept;
}
