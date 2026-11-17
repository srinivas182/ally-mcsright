<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One agent in an MLS the brokerage holds a back-office licence for, with production worked out from closed listings
 * (RC1, specs/grow/17). Rebuilt daily by `recruiting:refresh`.
 *
 * @property int $id
 * @property int $mls_source_id
 * @property string $agent_mls_id
 * @property string|null $name
 * @property string|null $office_mls_id
 * @property string|null $office_name
 * @property string|null $previous_office_mls_id
 * @property string|null $previous_office_name
 * @property CarbonImmutable|null $office_changed_on
 * @property int $sides
 * @property int $list_sides
 * @property int $buyer_sides
 * @property int $volume_cents
 * @property int $sides_before
 * @property int $volume_before_cents
 * @property int $active_listings
 * @property CarbonImmutable|null $last_closed_on
 * @property bool $ours
 * @property int $move_score
 * @property list<string>|null $move_reasons
 * @property CarbonImmutable $refreshed_at
 */
final class MlsAgent extends Model
{
    use BelongsToTenant;

    protected $table = 'rec_mls_agents';

    protected $guarded = ['id', 'tenant_id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** "Agent 12345" when the MLS gave no name (buyer's agents who never listed). */
    public function displayName(): string
    {
        return $this->name ?? "Agent {$this->agent_mls_id}";
    }

    /** Change in closed sides against the 12 months before, in percent; null when there were none before. */
    public function trend(): ?int
    {
        return $this->sides_before > 0 ? (int) round(100 * ($this->sides - $this->sides_before) / $this->sides_before) : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['office_changed_on' => 'immutable_date', 'last_closed_on' => 'immutable_date', 'refreshed_at' => 'immutable_datetime', 'ours' => 'boolean',
            'sides' => 'integer', 'list_sides' => 'integer', 'buyer_sides' => 'integer', 'volume_cents' => 'integer', 'sides_before' => 'integer',
            'volume_before_cents' => 'integer', 'active_listings' => 'integer', 'move_score' => 'integer', 'move_reasons' => 'array'];
    }
}
