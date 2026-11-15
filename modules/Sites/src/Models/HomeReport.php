<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A homeowner's monthly home-value report (specs/grow/03, LG1b).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $contact_id
 * @property int|null $site_funnel_id
 * @property int $user_id
 * @property string $address
 * @property string $zip
 * @property int|null $beds
 * @property int|null $sqft
 * @property int|null $last_mid
 * @property CarbonImmutable $next_on
 * @property CarbonImmutable|null $last_sent_at
 * @property int $sent_count
 * @property CarbonImmutable|null $unsubscribed_at
 */
final class HomeReport extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_home_reports';

    protected $fillable = ['contact_id', 'site_funnel_id', 'user_id', 'address', 'zip', 'beds', 'sqft', 'last_mid', 'next_on'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['next_on' => 'immutable_date', 'last_sent_at' => 'immutable_datetime', 'unsubscribed_at' => 'immutable_datetime', 'beds' => 'integer', 'sqft' => 'integer',
            'last_mid' => 'integer', 'sent_count' => 'integer'];
    }
}
