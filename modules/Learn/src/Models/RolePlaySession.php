<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One practice session (LN3): a role-play, a daily drill, a rehearsal, or a coaching card for a call or a deal.
 * Everything the agent typed is encrypted at rest and only they can open it; the broker sees scores only.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $kind
 * @property string|null $scenario
 * @property string|null $skill
 * @property string|null $brief
 * @property list<array{who: string, text: string}>|null $transcript
 * @property int $turns
 * @property int|null $score
 * @property array<string, mixed>|null $feedback
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
final class RolePlaySession extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'learn_roleplays';

    protected $fillable = ['user_id', 'kind', 'scenario', 'skill', 'brief', 'transcript', 'turns', 'score', 'feedback', 'finished_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['brief' => 'encrypted', 'transcript' => 'encrypted:array', 'feedback' => 'encrypted:array', 'turns' => 'integer', 'score' => 'integer',
            'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
