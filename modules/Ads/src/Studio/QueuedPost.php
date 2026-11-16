<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A post Ally Studio Autopilot planned (specs/grow/13, SA2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $source_key
 * @property string $moment
 * @property string|null $listing_ulid
 * @property string|null $batch
 * @property int|null $design_id
 * @property int|null $video_id
 * @property string|null $caption
 * @property string|null $caption_es
 * @property list<string>|null $hashtags
 * @property CarbonImmutable|null $post_at
 * @property string $status
 * @property int|null $social_post_id
 * @property string|null $reason
 * @property bool $needs_client_ok
 * @property int|null $reviewed_by
 * @property CarbonImmutable $created_at
 */
final class QueuedPost extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_queue';

    protected $fillable = ['user_id', 'source_key', 'moment', 'listing_ulid', 'batch', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['hashtags' => 'array', 'post_at' => 'immutable_datetime', 'needs_client_ok' => 'boolean'];
    }
}
