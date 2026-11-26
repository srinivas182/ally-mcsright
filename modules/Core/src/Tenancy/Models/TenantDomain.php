<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Database\Factories\TenantDomainFactory;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\ControlPlaneModel;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Host;

/**
 * A host name that routes to a tenant: its platform subdomain or a custom domain.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $host
 * @property DomainKind $kind
 * @property string $purpose app (back office) or website
 * @property string|null $site_ulid the agent website this domain serves (null: the brokerage's)
 * @property bool $is_primary
 * @property VerificationStatus $verification_status
 * @property SslStatus $ssl_status
 * @property string|null $provider_hostname_id
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $checked_at DM2: when the connection was last checked
 * @property string|null $check_message DM2: what is still missing, in plain words (null when connected)
 */
#[ControlPlaneModel]
final class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    use HasPublicUlid;

    protected $fillable = [
        'tenant_id', 'host', 'kind', 'owner_user_id', 'is_primary', 'verification_status',
        'ssl_status', 'provider_hostname_id', 'verified_at', 'purpose', 'site_ulid', 'checked_at', 'check_message',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::saving(function (TenantDomain $domain): void {
            $domain->host = Host::normalise($domain->host);
        });

        // Routing must change immediately when a domain changes (specs/core/01, 05).
        $forget = function (TenantDomain $domain): void {
            app(DomainResolver::class)->forget($domain->host);
            if ($domain->isDirty('host') || $domain->wasChanged('host')) {
                $original = $domain->getOriginal('host');
                if (is_string($original)) {
                    app(DomainResolver::class)->forget($original);
                }
            }
        };

        self::saved($forget);
        self::deleted($forget);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DomainKind::class,
            'verification_status' => VerificationStatus::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Platform subdomains always route; custom domains route once verified.
     */
    public function routes(): bool
    {
        return $this->kind === DomainKind::Platform
            || $this->verification_status === VerificationStatus::Verified;
    }

    public function isWebsite(): bool
    {
        return $this->purpose === 'website';
    }

    protected static function newFactory(): TenantDomainFactory
    {
        return TenantDomainFactory::new();
    }
}
