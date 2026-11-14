<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A blog post (Markdown; specs/websites/08). Published when status is "published" and published_at has passed, so
 * posts can be scheduled.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $site_id
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $category
 * @property string|null $cover_media
 * @property int|null $author_user_id
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property string|null $seo_title
 * @property string|null $meta_description
 * @property bool $noindex
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class SitePost extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_posts';

    protected $fillable = ['site_id', 'slug', 'title', 'excerpt', 'body', 'category', 'cover_media', 'author_user_id', 'status', 'published_at', 'seo_title',
        'meta_description', 'noindex'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'noindex' => 'boolean'];
    }

    /**
     * @param  Builder<SitePost>  $query
     * @return Builder<SitePost>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('published_at', '<=', CarbonImmutable::now());
    }

    public function isLive(): bool
    {
        return $this->status === 'published' && $this->published_at !== null && $this->published_at->lte(CarbonImmutable::now());
    }
}
