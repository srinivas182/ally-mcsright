<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The tenant or user does not have enough credits for this AI request; no model is called.
 */
final class InsufficientCredits extends RuntimeException {}
