<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One AI-enhanced version of a listing photo (specs/grow/13, SA5a): "enhanced" (light, colour, sky, lawn) or
 * "twilight" (virtual dusk). The MLS original is never changed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $listing_ulid
 * @property int $media_id
 * @property string $kind
 * @property string $status
 * @property string|null $provider_ref
 * @property string|null $path
 * @property bool $upscaled
 * @property string|null $paid
 * @property string|null $charge_ref
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable $created_at
 */
final class StudioPhoto extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_photos';

    protected $fillable = ['user_id', 'listing_ulid', 'media_id', 'kind', 'status', 'paid'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['upscaled' => 'boolean', 'attempts' => 'integer', 'media_id' => 'integer', 'user_id' => 'integer'];
    }
}
