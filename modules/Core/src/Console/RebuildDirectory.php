<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Identity\Directory;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Fills the email directory for the shared AI assistant address (run once at go-live; it stays current after). */
final class RebuildDirectory extends Command
{
    protected $signature = 'identity:directory';

    protected $description = 'Rebuild the sign-in email directory (hashed) used to find a person\'s brokerage';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->get() as $tenant) {
            $n += $tenants->runAs($tenant, function (): int {
                $count = 0;
                User::query()->each(function (User $u) use (&$count): void {
                    Directory::sync($u);
                    $count++;
                });

                return $count;
            });
        }
        $this->info("{$n} people listed.");

        return self::SUCCESS;
    }
}
