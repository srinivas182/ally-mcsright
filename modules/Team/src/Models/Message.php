<?php

declare(strict_types=1);

namespace Modules\Team\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One chat message (CH1). The text is encrypted at rest; a hidden message keeps its row for the audit trail.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $channel_id
 * @property int $user_id
 * @property string $body
 * @property list<int>|null $mentions
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $hidden_at
 * @property int|null $hidden_by
 * @property CarbonImmutable $created_at
 */
final class Message extends Model
{
    use BelongsToTenant;

    protected $table = 'team_messages';

    protected $fillable = ['channel_id', 'user_id', 'body', 'mentions', 'edited_at', 'hidden_at', 'hidden_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'mentions' => 'array', 'edited_at' => 'immutable_datetime', 'hidden_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
