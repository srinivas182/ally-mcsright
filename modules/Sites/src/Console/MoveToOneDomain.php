<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;

/**
 * DM1: moves every brokerage to one domain: its website to acme.rightally.app and its back office to
 * acme-office.rightally.app. Runs on every deploy once CORE_OFFICE_SUFFIX is set and SITES_PLATFORM_DOMAIN is the same
 * domain as CORE_PLATFORM_DOMAIN; does nothing otherwise, and nothing twice. A brokerage without a website yet keeps
 * its old address forwarding to the back office for a year. Brokerages' own domains are not touched.
 */
final class MoveToOneDomain extends Command
{
    protected $signature = 'sites:one-domain';

    protected $description = 'Move brokerages to one domain: website at name.<domain>, back office at name-office.<domain>';

    public function handle(DomainResolver $resolver): int
    {
        $core = (string) config('core.platform_domain');
        if (Host::officeSuffix() === '' || (string) config('sites.platform_domain') !== $core) {
            $this->info('One domain is off (CORE_OFFICE_SUFFIX is empty or SITES_PLATFORM_DOMAIN is a different domain); nothing to move.');

            return self::SUCCESS;
        }
        $moved = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->orderBy('id')->get() as $tenant) {
            $old = Host::normalise($tenant->slug.'.'.$core);
            $office = Host::platformHostFor($tenant->slug);
            DB::connection(Connections::control())->transaction(function () use ($tenant, $old, $office, $resolver, &$moved): void {
                // 1. The back office moves to name-office.
                $platform = TenantDomain::query()->where('tenant_id', $tenant->id)->where('host', $old)->where('purpose', '!=', 'website')->first();
                if ($platform !== null && ! TenantDomain::query()->where('host', $office)->exists()) {
                    $platform->forceFill(['host' => $office])->save();
                    $resolver->forget($old);
                    $resolver->forget($office);
                    $moved++;
                }
                // 2. The website moves to the brokerage's own name (from the old website domain).
                $website = TenantDomain::query()->where('tenant_id', $tenant->id)->where('purpose', 'website')->where('kind', DomainKind::Platform)
                    ->whereNull('site_ulid')->where('host', '!=', $old)->first();
                if ($website !== null && ! TenantDomain::query()->where('host', $old)->exists()) {
                    $resolver->forget($website->host);
                    $website->forceFill(['host' => $old])->save();
                    $resolver->forget($old);
                }
                // 3. No website yet: old back-office links to name.<domain> keep working for a year.
                if (! TenantDomain::query()->where('host', $old)->exists()) {
                    TenantHostRedirect::query()->updateOrCreate(['host' => $old], ['tenant_id' => $tenant->id, 'redirect_until' => now()->addYear()]);
                }
            });
        }
        $this->info("One domain: {$moved} back offices moved to name".Host::officeSuffix().'.'.$core.'; websites at name.'.$core.'.');

        return self::SUCCESS;
    }
}
