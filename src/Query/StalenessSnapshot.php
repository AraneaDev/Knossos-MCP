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
 * Deliberately per call and passed by hand rather than cached in the probe. A
 * rescan between the two reads changes the answer, and staleness that survives
 * a refresh is the defect this whole feature exists to avoid.
 */
final readonly class StalenessSnapshot
{
    /** @param array<string, mixed>|null $staleness the probe's own result, null for a scope that has no graph */
    public function __construct(public string $projectId, public ?array $staleness) {}
}
