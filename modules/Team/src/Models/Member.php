<?php

declare(strict_types=1);

namespace Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Someone in a channel, with how far they have read (CH1).
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property int $last_read_id
 * @property bool $muted
 */
final class Member extends Model
{
    use BelongsToTenant;

    protected $table = 'team_channel_members';

    protected $fillable = ['channel_id', 'user_id', 'last_read_id', 'muted'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['muted' => 'boolean', 'last_read_id' => 'integer'];
    }
}
