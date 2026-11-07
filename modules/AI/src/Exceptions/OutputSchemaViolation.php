<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The model output did not match the required schema after one corrective retry.
 */
final class OutputSchemaViolation extends RuntimeException {}
