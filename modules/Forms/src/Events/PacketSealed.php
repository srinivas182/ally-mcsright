<?php

declare(strict_types=1);

namespace Modules\Forms\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Everyone has signed and the document is sealed (IDX6 listens for MLS agreements). */
final class PacketSealed
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly string $packetUlid, public readonly string $sealedPath) {}
}
