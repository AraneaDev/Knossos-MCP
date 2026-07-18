<?php

declare(strict_types=1);

$coverage = dirname(__DIR__) . '/coverage';
if (!is_dir($coverage)) {
    exit(0);
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($coverage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
