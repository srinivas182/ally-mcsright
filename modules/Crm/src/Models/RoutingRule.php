<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A lead routing rule; rules are tried in order and the first match wins (specs/grow/04).
 *
 * @property int $id
 * @property string $ulid
 * @property int $position
 * @property string $name
 * @property list<string>|null $sources
 * @property list<string>|null $zips
 * @property int|null $price_min
 * @property int|null $price_max
 * @property string $intent
 * @property string $method
 * @property list<int> $agent_ids
 * @property int $cursor
 * @property bool $active
 */
final class RoutingRule extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const METHODS = ['agent', 'round_robin', 'first_claim', 'pond'];

    public const INTENTS = ['any', 'buy', 'sell', 'rent'];

    protected $table = 'crm_routing_rules';

    protected $fillable = ['position', 'name', 'sources', 'zips', 'price_min', 'price_max', 'intent', 'method', 'agent_ids', 'cursor', 'active'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sources' => 'array', 'zips' => 'array', 'agent_ids' => 'array', 'active' => 'boolean', 'price_min' => 'integer', 'price_max' => 'integer'];
    }
}
