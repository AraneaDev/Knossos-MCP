<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;

/**
 * Raised when a tools/call request fails input validation *before* the tool
 * runs: an unknown tool name, an unknown/missing/malformed argument key, or a
 * malformed common option. Distinct from an InvalidArgumentException thrown by
 * a query while executing (e.g. an unknown project), so the transport can map
 * pre-dispatch validation to JSON-RPC -32602 (Invalid params) while still
 * reporting genuine tool-runtime failures as isError tool results.
 */
final class ToolInputException extends InvalidArgumentException {}
