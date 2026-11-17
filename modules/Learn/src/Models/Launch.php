<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A new agent's 30-day launch (LN2).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $mentor_id
 * @property CarbonImmutable $started_on
 * @property list<string>|null $done
 * @property CarbonImmutable|null $finished_at
 * @property int|null $started_by
 */
final class Launch extends Model
{
    use BelongsToTenant;

    protected $table = 'learn_launches';

    protected $fillable = ['user_id', 'mentor_id', 'started_on', 'done', 'finished_at', 'started_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_on' => 'immutable_date', 'done' => 'array', 'finished_at' => 'immutable_datetime'];
    }
}
