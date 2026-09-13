<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * A staleness probe result together with the project it describes.
 *
 * Exists so one tool call probes once. The MCP layer has to know whether a
 * graph is stale before it dispatches, to decide whether to repair it, and the
 * enricher attaches the same verdict to the answer afterwards — two full oracle
 * runs for one question, which on a git project is six subprocesses and on a
 * gitless one two complete hash walks. Carrying the project id alongside the
 * verdict is what makes reuse safe: a tool whose envelope names a different
 * scope than the argument did gets its own probe rather than someone else's
 * answer.
 *
 * The project id alone was not enough to make reuse safe. A scan completing
 * between the probe and the dispatch leaves the two describing different
 * graphs while still agreeing about the project, so the answer went out
 * carrying the new graph's snapshot id beside the old graph's verdict. The
 * scan the verdict was measured against therefore travels with it, and the
 * enricher reuses it only for an answer that came out of that same scan.
 *
 * Deliberately per call and passed by hand rather than cached in the probe. A
 * rescan between the two reads changes the answer, and staleness that survives
 * a refresh is the defect this whole feature exists to avoid.
 */
final readonly class StalenessSnapshot
{
    /**
     * @param array<string, mixed>|null $staleness the probe's own result, null for a scope that has no graph
     * @param ?string $activeScanId the scan the verdict describes, or null when
     *        there was none to describe: a scope with no project, a project
     *        with no graph. Null matches no envelope's snapshot id, so those
     *        verdicts are re-probed rather than reused, which is the right way
     *        round for a case that has nothing to compare.
     */
    public function __construct(public string $projectId, public ?array $staleness, public ?string $activeScanId = null) {}
}
