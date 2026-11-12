<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_plan_id
 * @property int $price_cents
 * @property int|null $pending_price_cents
 * @property CarbonImmutable|null $pending_from
 * @property bool $offered
 * @property bool $is_default
 */
final class PlanPrice extends Model
{
    use BelongsToTenant;

    protected $table = 'billing_plan_prices';

    protected $fillable = ['agent_plan_id', 'price_cents', 'pending_price_cents', 'pending_from', 'offered', 'is_default'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['offered' => 'boolean', 'is_default' => 'boolean', 'pending_from' => 'immutable_datetime'];
    }
}
