<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An image in the website's media library, stored as WebP in three widths (specs/websites/08).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $site_id
 * @property string $path
 * @property string|null $original_name
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $alt
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 */
final class SiteMedia extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_media';

    protected $fillable = ['site_id', 'path', 'original_name', 'width', 'height', 'bytes', 'alt', 'uploaded_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
