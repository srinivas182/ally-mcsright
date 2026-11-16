<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A managing broker partner: the licensed broker of record for Movewise in a state (specs/movewise/00 §2).
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $broker_name
 * @property string $licence_number
 * @property string $licence_state
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $iabs_url
 * @property CarbonImmutable|null $contract_signed_on
 * @property string $status
 * @property float $hq_share_pct
 */
final class Partner extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_partners';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    protected function casts(): array
    {
        return ['contract_signed_on' => 'immutable_date', 'hq_share_pct' => 'float'];
    }
}
