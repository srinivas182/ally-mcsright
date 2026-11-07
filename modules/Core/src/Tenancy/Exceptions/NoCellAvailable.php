<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * No active cell has spare capacity for a new tenant. RightAlly HQ must add a cell.
 */
final class NoCellAvailable extends RuntimeException {}
