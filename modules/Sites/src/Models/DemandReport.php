<?php

declare(strict_types=1);

namespace Modules\Sites\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A buyer demand report for a home before it is listed (specs/grow/10).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $address
 * @property string $zip
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $result
 * @property CarbonImmutable|null $agents_told_at
 * @property CarbonImmutable $created_at
 */
final class DemandReport extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'site_demand_reports';

    protected $fillable = ['user_id', 'address', 'zip', 'inputs', 'result'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['inputs' => 'array', 'result' => 'array', 'agents_told_at' => 'immutable_datetime'];
    }
}
