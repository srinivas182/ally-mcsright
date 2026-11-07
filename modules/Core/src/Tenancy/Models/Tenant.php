<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Database\Factories\TenantFactory;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;

/**
 * A tenant: RightAlly HQ, a brokerage, or a solo agent workspace.
 *
 * @property int $id
 * @property string $ulid
 * @property TenantType $type
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property int $cell_id
 * @property string $timezone
 * @property string $locale
 * @property string|null $state_code
 * @property string|null $suspended_reason
 * @property string|null $provisioning_key
 * @property CarbonImmutable|null $closing_at
 * @property string|null $closing_reason
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $purge_after
 * @property CarbonImmutable|null $created_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasPublicUlid;
    use SoftDeletes;

    protected $fillable = [
        'type', 'name', 'slug', 'provisioning_key', 'status', 'cell_id', 'timezone', 'locale', 'state_code',
        'suspended_reason', 'closing_at', 'closing_reason', 'closed_at', 'purge_after', 'purge_due_notified_at',
        'created_by_user_id',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'closing_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
            'purge_due_notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Cell, $this>
     */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(Cell::class);
    }

    /**
     * @return HasMany<TenantDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function primaryDomain(): ?TenantDomain
    {
        return $this->domains()->where('is_primary', true)->first();
    }

    public function isHq(): bool
    {
        return $this->type === TenantType::Hq;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
