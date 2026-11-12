<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property int $agent_plan_id
 * @property string $status
 * @property int $price_cents
 * @property int $subsidy_cents
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $grace_until
 * @property string|null $gateway_ref
 * @property string|null $customer_ref
 * @property bool $sponsored
 */
final class AgentSubscription extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'billing_agent_subscriptions';

    protected $fillable = ['user_id', 'agent_plan_id', 'status', 'price_cents', 'subsidy_cents', 'current_period_end', 'grace_until', 'gateway_ref', 'customer_ref', 'sponsored'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['current_period_end' => 'immutable_datetime', 'grace_until' => 'immutable_datetime', 'sponsored' => 'boolean'];
    }
}
