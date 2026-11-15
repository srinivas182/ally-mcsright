<?php

declare(strict_types=1);

namespace Modules\Connect\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A paid app's monthly add-on for a brokerage (payer null) or an agent (specs/connect/14).
 *
 * @property int $id
 * @property string $ulid
 * @property string $app_key
 * @property int|null $payer_user_id
 * @property string $status
 * @property int $price_cents
 * @property int $credits
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $cancel_at
 * @property int $created_by
 */
final class AppAddon extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'connect_app_addons';

    protected $fillable = ['app_key', 'payer_user_id', 'status', 'price_cents', 'credits', 'period_start', 'period_end', 'cancel_at', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    public function paidUp(): bool
    {
        return $this->status === 'active' && $this->period_end->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'cancel_at' => 'immutable_datetime'];
    }
}
