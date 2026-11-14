<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

/** Default when no allowance module is installed: maps always open. */
final class UnlimitedWebsiteMaps implements WebsiteMaps
{
    public function open(): bool
    {
        return true;
    }
}
