<?php

declare(strict_types=1);

namespace Modules\Transactions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $type
 * @property string $status
 * @property int $agent_user_id
 * @property int|null $contact_id
 * @property string $street
 * @property string|null $city
 * @property string $state_code
 * @property string|null $postal_code
 * @property string|null $mls_number
 * @property int $price_cents
 * @property int $gci_cents
 * @property CarbonImmutable|null $contract_date
 * @property CarbonImmutable|null $closing_date
 * @property CarbonImmutable|null $closed_on
 * @property string|null $cancel_reason
 * @property int|null $calculation_id
 * @property CarbonImmutable|null $locked_at
 */
final class Deal extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'tx_deals';

    protected $fillable = ['type', 'status', 'agent_user_id', 'contact_id', 'street', 'city', 'state_code', 'postal_code', 'mls_number', 'price_cents', 'gci_cents', 'contract_date', 'closing_date', 'closed_on', 'cancel_reason', 'calculation_id', 'locked_at', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contract_date' => 'immutable_date', 'closing_date' => 'immutable_date', 'closed_on' => 'immutable_date', 'locked_at' => 'immutable_datetime'];
    }
}
