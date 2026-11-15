<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use RuntimeException;

/** A connector's sign-in stopped working (revoked, expired, wrong key): the person must connect again. */
final class NeedsReconnect extends RuntimeException {}
