<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One Reel or Short made from a listing design (specs/grow/13, SA3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $kind
 * @property int|null $design_id
 * @property list<array{at: int, title: string}>|null $chapters
 * @property string|null $listing_ulid
 * @property string|null $music_ulid
 * @property string $status
 * @property string|null $path
 * @property string|null $poster_path
 * @property int $seconds
 * @property string|null $error
 * @property CarbonImmutable|null $used_at
 * @property string|null $narration the voice-over script (SA5b)
 * @property string|null $voice stock:{id} or own
 * @property string|null $narration_error
 * @property CarbonImmutable $created_at
 */
final class StudioVideo extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_videos';

    protected $fillable = ['user_id', 'design_id', 'listing_ulid', 'music_ulid', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime', 'seconds' => 'integer', 'user_id' => 'integer', 'chapters' => 'array'];
    }
}
