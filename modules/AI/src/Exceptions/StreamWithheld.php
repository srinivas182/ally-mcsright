<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * Internal: thrown from inside a stream callback to stop reading the provider's stream as soon
 * as the output policy is violated.
 */
final class StreamWithheld extends RuntimeException {}
