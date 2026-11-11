<?php

declare(strict_types=1);

namespace Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * No commission plan is in force for the agent on that date (no assignment, no default, or no
 * published version yet).
 */
final class NoPlanForAgent extends RuntimeException {}
