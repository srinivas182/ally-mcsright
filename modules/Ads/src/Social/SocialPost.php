<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A social post (specs/connect/19): written once, sent to each chosen place now or at post_at.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string|null $listing_ulid
 * @property string $text
 * @property string|null $link
 * @property string|null $image_url
 * @property list<string>|null $images
 * @property string|null $video_url
 * @property int|null $studio_design_id
 * @property int|null $studio_video_id
 * @property string|null $format
 * @property string $status
 * @property CarbonImmutable $post_at
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable $created_at
 */
final class SocialPost extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'ads_social_posts';

    protected $fillable = ['user_id', 'listing_ulid', 'text', 'link', 'image_url', 'images', 'video_url', 'studio_design_id', 'studio_video_id', 'format', 'status', 'post_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return HasMany<SocialTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialTarget::class, 'post_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['post_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'images' => 'array'];
    }
}
