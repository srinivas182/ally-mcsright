<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The output referenced identifiers that were not in the permitted context, so it was blocked.
 */
final class AiOutputBlocked extends RuntimeException {}
