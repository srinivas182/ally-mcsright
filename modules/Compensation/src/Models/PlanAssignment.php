<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Which plan an agent is on, from a date (to a date).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_user_id
 * @property int $plan_id
 * @property PlanType $plan_type
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int|null $assigned_by
 * @property Plan $plan
 */
final class PlanAssignment extends Model
{
    use BelongsToTenant;

    protected $table = 'comp_plan_assignments';

    protected $fillable = ['agent_user_id', 'plan_id', 'plan_type', 'effective_from', 'effective_to', 'assigned_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['plan_type' => PlanType::class, 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
