<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $list_cents
 * @property int $floor_cents
 * @property list<string> $features
 * @property int $sort
 * @property bool $active
 */
final class AgentPlan extends Model
{
    use HasPublicUlid;

    protected $table = 'billing_agent_plans';

    protected $fillable = ['key', 'name', 'description', 'list_cents', 'floor_cents', 'features', 'sort', 'active'];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['features' => 'array', 'active' => 'boolean'];
    }
}
