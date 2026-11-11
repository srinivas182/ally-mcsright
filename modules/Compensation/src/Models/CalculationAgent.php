<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Which agents a calculation involves (to find an agent's pending deals quickly).
 *
 * @property int $id
 * @property int $calculation_id
 * @property int $agent_user_id
 * @property string $status
 */
final class CalculationAgent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'comp_calculation_agents';

    protected $fillable = ['calculation_id', 'agent_user_id', 'status'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
