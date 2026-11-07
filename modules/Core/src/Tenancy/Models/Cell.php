<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Database\Factories\CellFactory;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Core\Tenancy\Enums\CellStatus;

/**
 * A cell: a self-contained group of app servers and databases serving a set of tenants.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $region
 * @property CellStatus $status
 * @property string $db_connection
 * @property int $capacity_tenants
 * @property int $capacity_agents
 */
final class Cell extends Model
{
    /** @use HasFactory<CellFactory> */
    use HasFactory;

    use HasPublicUlid;

    protected $fillable = ['name', 'region', 'status', 'db_connection', 'capacity_tenants', 'capacity_agents'];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CellStatus::class];
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    protected static function newFactory(): CellFactory
    {
        return CellFactory::new();
    }
}
