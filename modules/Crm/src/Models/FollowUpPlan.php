<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $trigger
 * @property string|null $trigger_value
 * @property bool $active
 * @property bool $shared
 * @property int|null $owner_user_id
 */
final class FollowUpPlan extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_plans';

    protected $fillable = ['name', 'description', 'trigger', 'trigger_value', 'active', 'shared', 'owner_user_id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'shared' => 'boolean'];
    }

    /**
     * @return HasMany<PlanStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PlanStep::class, 'plan_id')->orderBy('sort');
    }
}
