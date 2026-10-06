<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * The `file_context` tool: {@see FileContextService} addressed by project id
 * and a path inside that project, with the declared policies whose source is
 * the file's boundary.
 *
 * A path is taken relative to the project root, or absolute under it; one that
 * leaves the root is refused rather than resolved to whichever project holds
 * it, so a project id never answers for a file outside its own tree.
 */
final readonly class FileContextQueryService extends AbstractArchitectureQueryService
{
    /** The context of `$path` in the project, or `status` not-found / unscanned with no file and no rules. */
    public function fileContext(string $projectId, string $path): ResultEnvelope
    {
        $project = $this->project($projectId);
        $root = rtrim((string) $project['root_realpath'], '/');
        $relative = self::relative($root, $path);
        $context = (new FileContextService($this->pdo))->context($root . '/' . $relative);
        $file = $context['file'];
        if ($context['status'] !== 'ok' || !is_array($file)) {
            return new ResultEnvelope($projectId, $project['active_scan_id'], sprintf('%s is not in the graph: not a source file Knossos scans, or not scanned yet.', $relative), ['status' => $context['status'], 'path' => $relative, 'file' => null, 'policies' => []]);
        }
        $boundary = $file['boundary'];
        $policies = $boundary === null ? [] : array_values(array_filter(
            FileViolationQuery::policies($root, null),
            static fn(array $policy): bool => ($policy['from_boundary'] ?? null) === $boundary,
        ));

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%s%s: %d dependent file%s, %d declared rule%s.', $relative, $boundary === null ? '' : ' (' . $boundary . ')', $file['dependents']['count'], $file['dependents']['count'] === 1 ? '' : 's', count($policies), count($policies) === 1 ? '' : 's'),
            ['status' => 'ok', 'path' => $relative, 'file' => $file, 'policies' => $policies],
            [],
            [],
            $file['tests']['more'],
        );
    }

    /** `$path` relative to `$root`; refused when it is empty or leaves the root. */
    private static function relative(string $root, string $path): string
    {
        $trimmed = trim($path);
        $relative = str_starts_with($trimmed, '/')
            ? (str_starts_with($trimmed, $root . '/') ? substr($trimmed, strlen($root) + 1) : null)
            : preg_replace('#^(\./)+#', '', $trimmed);
        if ($relative === null || $relative === '' || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException(sprintf('%s is outside the project (%s).', $path, $root));
        }

        return $relative;
    }
}
