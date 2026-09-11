<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;

/**
 * Whether a worker failure says the scan batch was too big, rather than that
 * the worker is broken.
 *
 * A batch that was too big is split and retried; anything else keeps the
 * per-language behaviour and degrades or propagates. Separate from
 * {@see LanguageScanRunner} because each clause here is a decision about one
 * exception, and exercising it through a live worker left the clauses that no
 * fixture could provoke (a TypeScript failure that is not an exit, a request
 * with no config list at all) untested.
 */
final class OversizedBatch
{
    /** The codes that say the output or the request outgrew a cap sized for a batch. */
    private const SIZE_CODES = ['WORKER_OUTPUT_LIMIT', 'WORKER_FRAME_TOO_LARGE', 'WORKER_REQUEST_TOO_LARGE'];

    /**
     * Whether splitting the batch that raised $error could let it succeed.
     *
     * @param array<string, mixed> $request the scan request the batch was sent with
     */
    public static function signalledBy(LanguageDescriptor $descriptor, WorkerException $error, array $request): bool
    {
        return in_array($error->diagnosticCode, self::SIZE_CODES, true)
            || self::isTypeScriptHeapExhaustion($descriptor, $error, $request);
    }

    /**
     * Whether a TypeScript worker explicitly died from V8 heap exhaustion.
     *
     * An ordinary worker exit is a real failure and is not retried. Node's
     * stable stderr signature lets a large batch be treated like an output
     * overflow: split only that batch and give the language a fresh worker.
     *
     * Only for a request that names no tsconfig: a project built from its
     * config files compiles the program those files describe, whatever subset
     * of paths the batch lists, so a smaller batch would exhaust the heap the
     * same way.
     *
     * @param array<string, mixed> $request
     */
    private static function isTypeScriptHeapExhaustion(LanguageDescriptor $descriptor, WorkerException $error, array $request): bool
    {
        if ($descriptor->key !== 'typescript'
            || $error->diagnosticCode !== 'WORKER_EXITED'
            || ($request['config_files'] ?? []) !== []) {
            return false;
        }
        $message = strtolower($error->getMessage());

        return str_contains($message, 'javascript heap out of memory')
            || str_contains($message, 'ineffective mark-compacts near heap limit');
    }
}
