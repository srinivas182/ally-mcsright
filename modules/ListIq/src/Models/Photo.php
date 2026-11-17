<?php

declare(strict_types=1);

namespace Modules\ListIq\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A listing photo (LI1): the agent's original, or an enhanced or virtually staged version of one.
 *
 * @property int $id
 * @property string $ulid
 * @property int $listing_id
 * @property int|null $parent_id
 * @property string $kind
 * @property string $room
 * @property string|null $style
 * @property string|null $path
 * @property string|null $caption
 * @property int $position
 * @property bool $use
 * @property string $status
 * @property string|null $provider_ref
 * @property string|null $error
 * @property string|null $charge_ref
 */
final class Photo extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'listiq_photos';

    protected $fillable = ['listing_id', 'parent_id', 'kind', 'room', 'style', 'path', 'caption', 'position', 'use', 'status', 'provider_ref', 'error', 'charge_ref'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['use' => 'boolean', 'position' => 'integer'];
    }
}
