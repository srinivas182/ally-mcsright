<?php

declare(strict_types=1);

namespace Modules\ListIq\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A listing being made ready (LI1).
 *
 * @property int $id
 * @property string $ulid
 * @property int $owner_user_id
 * @property int|null $deal_id
 * @property string $street
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip
 * @property array<string, mixed>|null $facts
 * @property string|null $notes
 * @property string|null $showing
 * @property array<string, bool>|null $manual
 * @property list<array{style: string, text: string}>|null $descriptions
 * @property string|null $description
 * @property string|null $description_es
 * @property int $remarks_limit
 * @property string $status
 * @property string|null $floor_plan_path
 * @property bool $in_mls
 * @property int $page_views
 * @property CarbonImmutable $updated_at
 */
final class Prep extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'listiq_listings';

    protected $fillable = ['owner_user_id', 'deal_id', 'street', 'city', 'state', 'zip', 'facts', 'notes', 'showing', 'manual', 'descriptions', 'description', 'description_es',
        'remarks_limit', 'status', 'floor_plan_path', 'in_mls'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return HasMany<Photo, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'listing_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['facts' => 'array', 'manual' => 'array', 'descriptions' => 'array', 'updated_at' => 'immutable_datetime', 'remarks_limit' => 'integer', 'in_mls' => 'boolean', 'page_views' => 'integer'];
    }
}
