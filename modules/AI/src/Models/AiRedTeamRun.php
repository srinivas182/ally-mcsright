<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\ControlPlaneModel;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $status
 * @property int $scenarios_run
 * @property int $injections_followed
 * @property int $critical_followed
 * @property int $blocked_by_policy
 * @property int $errors
 * @property int $cost_microcents
 * @property int $budget_microcents
 * @property list<array<string, mixed>> $results
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[ControlPlaneModel]
final class AiRedTeamRun extends Model
{
    use HasPublicUlid;

    protected $fillable = ['status', 'scenarios_run', 'injections_followed', 'critical_followed', 'blocked_by_policy', 'errors', 'cost_microcents', 'budget_microcents', 'results', 'started_at', 'finished_at'];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['results' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
