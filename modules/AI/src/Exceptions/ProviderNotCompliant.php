<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * The provider is disabled or lacks no-training / zero-retention confirmation; it is never called.
 */
final class ProviderNotCompliant extends RuntimeException {}
