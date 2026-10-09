<?php

declare(strict_types=1);

/*
 * Combines the raw coverage data of CI's coverage shards into one coverage/
 * directory, so `tools/coverage report` enforces the floors over the whole
 * suite exactly as it does after an unsharded run.
 *
 *   php tools/coverage-merge.php RAW_DIR [--into=DIR] [--files-from=PATH]
 *
 * RAW_DIR holds one directory per shard, each the coverage/ directory a
 * `tools/coverage run --shard=I/N` left behind. --into defaults to coverage/;
 * --files-from is handed to tools/phpunit-shard in place of asking PHPUnit for
 * the suite, which is how the tests drive this without a real suite.
 *
 * It refuses, naming everything that is wrong, unless every shard of the same
 * N finished (its shard.json marker exists), left PHP coverage data, and every
 * test file of the suite shows up in exactly one shard's JUnit log, the shard
 * the selector assigned it to. A shard that lost a test file reports lower
 * coverage without failing, and a floor with headroom would let that through,
 * so the check is on the file set, not on the figures.
 *
 * The PHP data is combined here into one pcov-merged.json rather than copied,
 * because which lines pcov reports as executable depends on load order. PHP
 * folds a class constant (Other::NAME) into the code at compile time when the
 * class holding it is already loaded, and then that line has no opcode and is
 * not executable. Which classes are loaded first depends on which tests ran
 * before, which sharding changes, so a plain union of the shards' data counted
 * up to a few more lines than an unsharded run, and an extra uncovered line
 * can tip a component with little headroom below its floor. So a line counts
 * as executable only when every shard that loaded its file reports it, and as
 * covered when any of them hit it. Within a shard the per-process files are
 * combined as tools/pcov-report.php does, by the highest hit count per line.
 *
 * The V8 files carry a process id, and container process ids repeat across
 * shards, so they are prefixed with the shard. coverage.py's files already
 * carry the hostname, the pid and a random suffix, and are copied as they are.
 */

$root = dirname(__DIR__);

/**
 * Print why the shards cannot be merged and stop with a failing status.
 *
 * @param list<string> $messages
 */
function mergeRefuse(array $messages): never
{
    fwrite(STDERR, "coverage merge: refusing to merge the shards:\n");
    foreach ($messages as $message) {
        fwrite(STDERR, "  - {$message}\n");
    }
    exit(1);
}

$raw = null;
$into = $root . '/coverage';
$filesFrom = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--into=') && strlen($argument) > 7) {
        $into = substr($argument, 7);
    } elseif (str_starts_with($argument, '--files-from=') && strlen($argument) > 13) {
        $filesFrom = substr($argument, 13);
    } elseif (!str_starts_with($argument, '--') && $raw === null) {
        $raw = rtrim($argument, '/');
    } else {
        fwrite(STDERR, "usage: php tools/coverage-merge.php RAW_DIR [--into=DIR] [--files-from=PATH]\n");
        exit(64);
    }
}
if ($raw === null || !is_dir($raw)) {
    mergeRefuse([($raw ?? '(none)') . ' is not a directory of shard results']);
}

// The shards, keyed by their own marker's index rather than by directory name,
// so the layout the artifact download produces does not matter.
$errors = [];
$shards = [];
$counts = [];
$directories = glob($raw . '/*', GLOB_ONLYDIR) ?: [];
if ($directories === []) {
    mergeRefuse(["{$raw} holds no shard directories"]);
}
foreach ($directories as $directory) {
    $marker = $directory . '/shard.json';
    $decoded = is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;
    if (!is_array($decoded) || !is_int($decoded['shard'] ?? null) || !is_int($decoded['of'] ?? null)) {
        $errors[] = "{$directory} has no valid shard.json: that shard did not finish its PHPUnit run";
        continue;
    }
    [$index, $of] = [$decoded['shard'], $decoded['of']];
    $counts[$of] = true;
    if (isset($shards[$index])) {
        $errors[] = "shard {$index} appears twice: {$shards[$index]} and {$directory}";
        continue;
    }
    $shards[$index] = $directory;
}
if (count($counts) > 1) {
    $errors[] = 'the shards disagree on the shard count: ' . implode(', ', array_keys($counts));
}
$of = (int) (array_key_first($counts) ?? 0);
if (count($counts) === 1) {
    foreach (range(1, $of) as $index) {
        if (!isset($shards[$index])) {
            $errors[] = "shard {$index} of {$of} is missing";
        }
    }
    foreach (array_keys($shards) as $index) {
        if ($index < 1 || $index > $of) {
            $errors[] = "shard {$index} is outside 1..{$of}";
        }
    }
}
if ($errors !== []) {
    mergeRefuse($errors);
}
ksort($shards);

// Which shard ran each test file, according to its JUnit log.
$ranIn = [];
foreach ($shards as $index => $directory) {
    if ((glob($directory . '/php/pcov-*.json') ?: []) === []) {
        $errors[] = "shard {$index} left no PHP coverage data in {$directory}/php";
    }
    $junit = $directory . '/junit.xml';
    $document = new DOMDocument();
    if (!is_file($junit) || !@$document->load($junit)) {
        $errors[] = "shard {$index} has no readable junit.xml";
        continue;
    }
    $files = [];
    foreach ((new DOMXPath($document))->query('//testsuite[@file]') ?: [] as $suite) {
        if ($suite instanceof DOMElement) {
            $file = $suite->getAttribute('file');
            $files[str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file] = true;
        }
    }
    foreach (array_keys($files) as $file) {
        $ranIn[$file][] = $index;
    }
}

// Which shard each test file was assigned to.
$command = [PHP_BINARY, $root . '/tools/phpunit-shard', '--list=' . $of];
if ($filesFrom !== null) {
    $command[] = '--files-from=' . $filesFrom;
}
$process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    mergeRefuse(['cannot start tools/phpunit-shard']);
}
$listing = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
if (proc_close($process) !== 0) {
    mergeRefuse(['tools/phpunit-shard --list failed']);
}
$assigned = [];
foreach (explode("\n", trim($listing)) as $line) {
    [$file, $index] = explode("\t", $line) + [1 => '0'];
    $assigned[$file] = (int) $index;
}

foreach ($assigned as $file => $index) {
    $ran = $ranIn[$file] ?? [];
    if ($ran === []) {
        $errors[] = "{$file} ran in no shard (assigned to shard {$index})";
    } elseif (count($ran) > 1) {
        $errors[] = "{$file} ran in shards " . implode(' and ', $ran) . ', not once';
    } elseif ($ran[0] !== $index) {
        $errors[] = "{$file} ran in shard {$ran[0]}, but the selector assigned it to shard {$index}";
    }
}
foreach (array_keys($ranIn) as $file) {
    if (!isset($assigned[$file])) {
        $errors[] = "{$file} ran in shard " . implode(' and ', $ranIn[$file]) . ' but is not a test file of this suite';
    }
}
if ($errors !== []) {
    mergeRefuse($errors);
}

// Every check passed: gather the data where `tools/coverage report` reads it.
$copy = static function (string $from, string $to): void {
    if (file_exists($to)) {
        mergeRefuse(["{$to} already exists; two shards wrote the same coverage file"]);
    }
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0o777, true) && !is_dir(dirname($to))) {
        mergeRefuse(['cannot create ' . dirname($to)]);
    }
    if (!copy($from, $to)) {
        mergeRefuse(["cannot copy {$from} to {$to}"]);
    }
};
$counted = ['php' => 0, 'js' => 0, 'python' => 0];
/** @var array<string, array<int, int>> $php the lines every loading shard reports, with their highest hit count */
$php = [];
foreach ($shards as $index => $directory) {
    $shardLines = [];
    foreach (glob($directory . '/php/pcov-*.json') ?: [] as $file) {
        $process = json_decode((string) file_get_contents($file), true);
        if (!is_array($process)) {
            mergeRefuse(["{$file} is not pcov data"]);
        }
        foreach ($process as $source => $lines) {
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line => $hits) {
                $shardLines[$source][(int) $line] = max($shardLines[$source][(int) $line] ?? -1, (int) $hits);
            }
        }
        ++$counted['php'];
    }
    foreach ($shardLines as $source => $lines) {
        if (!isset($php[$source])) {
            $php[$source] = $lines;
        } else {
            $kept = array_intersect_key($php[$source], $lines);
            foreach ($kept as $line => $hits) {
                $kept[$line] = max($hits, $lines[$line]);
            }
            $php[$source] = $kept;
        }
    }
}
$target = $into . '/php/pcov-merged.json';
if (file_exists($target)) {
    mergeRefuse(["{$target} already exists"]);
}
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o777, true) && !is_dir(dirname($target))) {
    mergeRefuse(['cannot create ' . dirname($target)]);
}
file_put_contents($target, json_encode($php, JSON_THROW_ON_ERROR));
foreach ($shards as $index => $directory) {
    foreach (glob($directory . '/js/tmp/*.json') ?: [] as $file) {
        $copy($file, $into . '/js/tmp/s' . $index . '-' . basename($file));
        ++$counted['js'];
    }
    foreach (glob($directory . '/python/.coverage.*') ?: [] as $file) {
        $copy($file, $into . '/python/' . basename($file));
        ++$counted['python'];
    }
    $copy($directory . '/junit.xml', $into . '/junit/shard-' . $index . '.xml');
}
printf(
    "coverage merge: %d shards, %d test files each run once; %d PHP, %d JavaScript and %d Python data files\n",
    $of,
    count($assigned),
    $counted['php'],
    $counted['js'],
    $counted['python'],
);
