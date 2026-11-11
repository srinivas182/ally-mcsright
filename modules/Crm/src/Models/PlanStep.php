<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $plan_id
 * @property int $sort
 * @property int $day
 * @property string $type
 * @property string $title
 * @property string|null $message
 */
final class PlanStep extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'crm_plan_steps';

    protected $fillable = ['plan_id', 'sort', 'day', 'type', 'title', 'message'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
