<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A buyer or seller funnel on the brokerage's website (specs/grow/03).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $site_id
 * @property int|null $owner_user_id
 * @property string $kind
 * @property string $slug
 * @property string|null $area
 * @property int|null $min_price
 * @property int|null $max_price
 * @property int|null $beds
 * @property string $headline
 * @property string|null $intro
 * @property string $status
 * @property int $visits
 * @property int $leads
 * @property int $created_by
 */
final class SiteFunnel extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_funnels';

    protected $fillable = ['site_id', 'owner_user_id', 'kind', 'slug', 'area', 'min_price', 'max_price', 'beds', 'headline', 'intro', 'status', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** The lead source its leads are reported under. */
    public function sourceName(): string
    {
        return mb_substr('Funnel: '.$this->headline, 0, 120);
    }
}
