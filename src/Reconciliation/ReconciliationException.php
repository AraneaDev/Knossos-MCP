<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use RuntimeException;

/** The graph could not be reconciled; the transaction is rolled back and the previous graph stands. */
final class ReconciliationException extends RuntimeException {}
