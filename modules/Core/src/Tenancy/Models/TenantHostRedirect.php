<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\ControlPlaneModel;

/**
 * An old platform host that keeps redirecting to the tenant after a slug change (90 days).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $host
 * @property CarbonImmutable $redirect_until
 * @property Tenant $tenant
 */
#[ControlPlaneModel]
final class TenantHostRedirect extends Model
{
    protected $fillable = ['tenant_id', 'host', 'redirect_until'];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['redirect_until' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
