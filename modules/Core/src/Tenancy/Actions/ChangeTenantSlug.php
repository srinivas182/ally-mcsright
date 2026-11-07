<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Events\TenantSlugChanged;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;
use Modules\Core\Tenancy\Slug;

/**
 * Changes a tenant's address. The old platform host 301-redirects to the tenant's primary domain
 * for 90 days, and cannot be claimed by another tenant during that time.
 */
final class ChangeTenantSlug
{
    public const REDIRECT_DAYS = 90;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DomainResolver $resolver,
    ) {}

    public function handle(Tenant $tenant, string $newSlug, ?int $byUserId = null): Tenant
    {
        $oldSlug = $tenant->slug;
        if ($newSlug === $oldSlug) {
            return $tenant;
        }

        $problem = Slug::problem($newSlug);
        $newHost = Host::platformHostFor($newSlug);
        $oldHost = Host::platformHostFor($oldSlug);

        if ($problem === null) {
            $takenByOther = Tenant::withTrashed()->where('slug', $newSlug)->whereKeyNot($tenant->id)->exists()
                || TenantDomain::query()->where('host', $newHost)->where('tenant_id', '!=', $tenant->id)->exists()
                || TenantHostRedirect::query()->where('host', $newHost)->where('tenant_id', '!=', $tenant->id)
                    ->where('redirect_until', '>', now())->exists();
            $problem = $takenByOther ? 'This address is already in use. Please choose another.' : null;
        }

        if ($problem !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        $this->db->connection(Connections::control())->transaction(function () use ($tenant, $newSlug, $oldHost, $newHost): void {
            $tenant->forceFill(['slug' => $newSlug])->save();

            TenantDomain::query()
                ->where('tenant_id', $tenant->id)
                ->where('kind', DomainKind::Platform)
                ->where('host', $oldHost)
                ->get()
                ->each(fn (TenantDomain $domain) => $domain->update(['host' => $newHost]));

            // The tenant may be moving back to an address it used before.
            TenantHostRedirect::query()->where('host', $newHost)->delete();

            TenantHostRedirect::query()->updateOrCreate(
                ['host' => $oldHost],
                ['tenant_id' => $tenant->id, 'redirect_until' => CarbonImmutable::now()->addDays(self::REDIRECT_DAYS)],
            );
        });

        $this->resolver->forget($oldHost);
        $this->resolver->forget($newHost);

        TenantSlugChanged::dispatch($tenant->id, $oldSlug, $newSlug, $byUserId);

        return $tenant;
    }
}
