<?php

declare(strict_types=1);

namespace Modules\Recruiting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A line in a recruit's history: a note, call, email, meeting, stage change or alert.
 *
 * @property int $id
 * @property int $recruit_id
 * @property int|null $user_id
 * @property string $kind
 * @property string $body
 * @property CarbonImmutable $occurred_at
 */
final class RecruitNote extends Model
{
    use BelongsToTenant;

    public const KINDS = ['note', 'call', 'email', 'meeting'];

    protected $table = 'rec_notes';

    protected $fillable = ['recruit_id', 'user_id', 'kind', 'body', 'occurred_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
