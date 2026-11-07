<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The provider rejected the request (4xx other than rate limits); not retried on other models.
 */
final class ProviderRejected extends RuntimeException {}
