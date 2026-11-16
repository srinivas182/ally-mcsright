<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * What an agent or a brokerage buys on Movewise (specs/movewise/03): where, what price range, which lead types,
 * how many a month, when they work, and how they pay.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property string $payer
 * @property int|null $payer_user_id
 * @property string $name
 * @property string $state
 * @property array{zips: list<string>, cities: list<string>} $areas
 * @property int $price_min
 * @property int $price_max
 * @property list<string> $lead_types
 * @property list<string> $languages
 * @property int $monthly_cap
 * @property int|null $daily_cap
 * @property array{tz: string, days: array<int, array{0: string, 1: string}>} $hours
 * @property string $model
 * @property string $status
 * @property string|null $pause_reason
 * @property int $created_by
 */
final class Package extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_packages';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    /** @return list<int> the agents this package may show (an agent package: just that agent) */
    public function agentIds(): array
    {
        if ($this->agent_id !== null) {
            return [$this->agent_id];
        }

        return array_values(array_map('intval', $this->getConnection()->table('movewise_package_agents')->where('package_id', $this->id)->pluck('agent_id')->all()));
    }

    protected function casts(): array
    {
        return ['areas' => 'array', 'lead_types' => 'array', 'languages' => 'array', 'hours' => 'array', 'price_min' => 'integer', 'price_max' => 'integer',
            'monthly_cap' => 'integer', 'daily_cap' => 'integer'];
    }
}
