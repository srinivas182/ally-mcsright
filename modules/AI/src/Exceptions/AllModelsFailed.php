<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * Every model in the feature's chain failed or was unavailable.
 */
final class AllModelsFailed extends RuntimeException {}
