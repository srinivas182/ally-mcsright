<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property int $contact_id
 * @property int $assigned_to
 * @property string $status
 * @property string|null $stop_reason
 * @property int $next_step
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $next_due_at
 * @property FollowUpPlan $plan
 * @property Contact $contact
 */
final class PlanEnrollment extends Model
{
    use BelongsToTenant;

    protected $table = 'crm_plan_enrollments';

    protected $fillable = ['plan_id', 'contact_id', 'assigned_to', 'status', 'stop_reason', 'next_step', 'started_at', 'next_due_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'next_due_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<FollowUpPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FollowUpPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
