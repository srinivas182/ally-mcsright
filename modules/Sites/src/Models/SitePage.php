<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A page made of sections (published, plus a draft being edited).
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string $path
 * @property string $title
 * @property string|null $meta_description
 * @property list<array<string, mixed>> $sections
 * @property list<array<string, mixed>>|null $draft_sections
 * @property bool $noindex
 * @property bool $in_menu
 * @property int $position
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 * @property Site $site
 */
final class SitePage extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_pages';

    protected $fillable = ['site_id', 'path', 'title', 'meta_description', 'sections', 'draft_sections', 'noindex', 'in_menu', 'position', 'status', 'published_at', 'updated_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sections' => 'array', 'draft_sections' => 'array', 'noindex' => 'boolean', 'in_menu' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
