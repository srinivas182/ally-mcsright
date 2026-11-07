<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * Data offered to the AI did not come from the current tenant's permitted records.
 */
final class ContextNotPermitted extends RuntimeException {}
