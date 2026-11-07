<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;

/**
 * @extends Factory<TenantDomain>
 */
final class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'host' => 'www.'.fake()->unique()->domainWord().'-homes.com',
            'kind' => DomainKind::Custom,
            'is_primary' => false,
            'verification_status' => VerificationStatus::Pending,
            'ssl_status' => SslStatus::Pending,
        ];
    }

    public function verified(): self
    {
        return $this->state([
            'verification_status' => VerificationStatus::Verified,
            'ssl_status' => SslStatus::Active,
            'verified_at' => now(),
        ]);
    }
}
