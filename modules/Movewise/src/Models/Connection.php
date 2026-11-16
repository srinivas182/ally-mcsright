<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A person's choice of one agent and one way to be contacted (specs/movewise/04). The consent is kept word for word.
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property int $show_id
 * @property int $agent_id
 * @property int $package_id
 * @property int $tenant_id
 * @property string $lead_type
 * @property string $method
 * @property CarbonImmutable|null $slot_at
 * @property string $consent_text
 * @property CarbonImmutable $consent_at
 * @property string|null $consent_ip
 * @property string $status
 * @property CarbonImmutable $offered_at
 * @property CarbonImmutable $accept_by
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $closed_at
 * @property string $model
 * @property int $price_credits
 * @property string|null $refund_status
 * @property string|null $refund_reason
 * @property int|null $lead_id
 * @property int|null $contact_id
 * @property string|null $relay_number
 * @property CarbonImmutable|null $paused_until
 * @property CarbonImmutable|null $handover_asked_at
 * @property CarbonImmutable|null $handed_over_at
 * @property string|null $end_reason
 */
final class Connection extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_connections';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    /** The key the credit charge (and its refund) is filed under: one charge per connection, ever. */
    public function chargeKey(): string
    {
        return 'movewise-connection:'.$this->ulid;
    }

    protected function casts(): array
    {
        return ['slot_at' => 'immutable_datetime', 'consent_at' => 'immutable_datetime', 'offered_at' => 'immutable_datetime', 'accept_by' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'paused_until' => 'immutable_datetime', 'handover_asked_at' => 'immutable_datetime', 'handed_over_at' => 'immutable_datetime', 'price_credits' => 'integer'];
    }
}
