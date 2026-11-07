<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

/**
 * Ledger journals and lines are append-only; corrections are reversals.
 */
final class ImmutableLedgerRecord extends RuntimeException {}
