<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $feature_key
 * @property string $provider_key
 * @property string $model_id
 * @property string $mode
 * @property string|null $external_id
 * @property string $status
 * @property int $item_count
 * @property int $succeeded
 * @property int $failed_count
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $completed_at
 */
final class AiBatch extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $fillable = ['feature_key', 'provider_key', 'model_id', 'mode', 'external_id', 'status', 'item_count', 'succeeded', 'failed_count', 'submitted_at', 'completed_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<AiBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AiBatchItem::class, 'batch_id');
    }

    /**
     * The checked answer for one task, or null if it failed, was withheld or was purged.
     */
    public function resultFor(string $idempotencyKey): ?string
    {
        $item = $this->items()->where('idempotency_key', $idempotencyKey)->where('status', 'ok')->first();

        return $item?->result;
    }
}
