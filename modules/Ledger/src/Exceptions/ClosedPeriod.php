<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

/**
 * Posting into a closed accounting period is not allowed; post the correction in the current open period.
 */
final class ClosedPeriod extends RuntimeException {}
