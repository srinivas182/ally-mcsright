<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

/**
 * A journal draft that cannot be posted (unbalanced, too few lines, zero or two-sided lines, inactive accounts).
 */
final class InvalidJournal extends RuntimeException {}
