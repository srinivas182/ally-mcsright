<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * A lifecycle change that is not allowed from the tenant's current status (e.g. closing a
 * tenant that is already closed, or suspending RightAlly HQ).
 */
final class InvalidTenantTransition extends RuntimeException {}
