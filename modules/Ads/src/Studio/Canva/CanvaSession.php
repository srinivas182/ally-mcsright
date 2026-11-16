<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Canva;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One trip to Canva and back (specs/grow/13, C7d-3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property int $connection_id
 * @property string|null $listing_ulid
 * @property int|null $source_design_id
 * @property string|null $design_ref
 * @property string|null $edit_url
 * @property string $status
 * @property int|null $result_design_id
 * @property string|null $error
 */
final class CanvaSession extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'studio_canva';

    protected $guarded = ['id'];

    protected $hidden = ['edit_url'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['edit_url' => 'encrypted', 'user_id' => 'integer'];
    }
}
