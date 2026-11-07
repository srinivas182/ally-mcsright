<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'type' => TenantType::Brokerage,
            'name' => fake()->company().' Realty',
            'slug' => fake()->unique()->lexify('brokerage-??????'),
            'status' => TenantStatus::Active,
            'cell_id' => Cell::factory(),
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'state_code' => 'MS',
        ];
    }

    public function hq(): self
    {
        return $this->state(['type' => TenantType::Hq, 'name' => 'RightAlly HQ', 'slug' => 'rightally-hq']);
    }

    public function solo(): self
    {
        return $this->state(['type' => TenantType::Solo]);
    }

    public function status(TenantStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    /**
     * Also create the tenant's primary platform subdomain.
     */
    public function withPlatformDomain(): self
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $tenant->domains()->create([
                'host' => Host::platformHostFor($tenant->slug),
                'kind' => DomainKind::Platform,
                'is_primary' => true,
                'verification_status' => VerificationStatus::Verified,
                'ssl_status' => SslStatus::Active,
            ]);
        });
    }
}
