<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The provider timed out, rate-limited or failed (5xx); the gateway tries the next model in the chain.
 */
final class ProviderUnavailable extends RuntimeException {}
