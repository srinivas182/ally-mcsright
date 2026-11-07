<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * No AI feature route is registered for this feature key.
 */
final class UnknownFeature extends RuntimeException {}
