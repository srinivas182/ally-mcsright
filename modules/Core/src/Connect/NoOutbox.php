<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/** The default: no apps connected, nothing to send. */
final class NoOutbox implements Outbox
{
    public function emit(string $event, array $data): void {}
}
