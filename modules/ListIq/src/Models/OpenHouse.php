<?php

declare(strict_types=1);

namespace Modules\ListIq\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An open house (LI2), with the visitors who signed in.
 *
 * @property int $id
 * @property string $ulid
 * @property int $listing_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $ended_at
 * @property list<array{name: string, contact_id: int|null, agent: string, at: string}>|null $visitors
 */
final class OpenHouse extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'listiq_open_houses';

    protected $fillable = ['listing_id', 'starts_at', 'ends_at', 'ended_at', 'visitors'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'visitors' => 'array'];
    }
}
