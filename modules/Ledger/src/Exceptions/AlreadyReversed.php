<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

/**
 * A journal can be reversed only once, and a reversal cannot itself be reversed.
 */
final class AlreadyReversed extends RuntimeException {}
