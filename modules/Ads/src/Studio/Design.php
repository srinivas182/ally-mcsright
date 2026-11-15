<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One Ally Studio design (specs/grow/13).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $batch
 * @property string $moment
 * @property string|null $listing_ulid
 * @property string $collection
 * @property string $variant
 * @property array<string, mixed> $content
 * @property array<string, string>|null $files
 * @property list<string>|null $checks
 * @property string $status
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class Design extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_designs';

    protected $fillable = ['user_id', 'batch', 'moment', 'listing_ulid', 'collection', 'variant', 'content', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['content' => 'array', 'files' => 'array', 'checks' => 'array', 'used_at' => 'immutable_datetime'];
    }
}
