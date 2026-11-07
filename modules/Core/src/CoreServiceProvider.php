<?php

declare(strict_types=1);

namespace Modules\Core;

use Illuminate\Support\ServiceProvider;

/**
 * Platform Core module.
 *
 * Every RightAlly module is a self-contained package under modules/<Name>/
 * with a module.json manifest and a service provider registered here in
 * bootstrap/providers.php. Modules talk to each other only through public
 * interfaces and domain events, never through each other's tables.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
