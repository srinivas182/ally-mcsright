<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A saved calculation for one deal side. New versions are added, never overwritten.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $deal_ref
 * @property string $side
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $result
 * @property list<string> $steps
 * @property string|null $change_note
 * @property int|null $created_by
 */
final class Calculation extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'comp_calculations';

    protected $fillable = ['deal_ref', 'side', 'version', 'status', 'inputs', 'result', 'steps', 'change_note', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['inputs' => 'array', 'result' => 'array', 'steps' => 'array'];
    }

    /**
     * @return HasMany<CalculationAgent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(CalculationAgent::class);
    }
}
