<?php

declare(strict_types=1);

namespace Modules\Team\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A team chat channel (CH1): everyone, announcements, a group, or a direct chat between two people.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property string $kind
 * @property string $name
 * @property string|null $direct_key
 * @property int|null $created_by
 * @property CarbonImmutable|null $last_message_at
 */
final class Channel extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const KINDS = ['everyone', 'announcements', 'group', 'direct'];

    protected $table = 'team_channels';

    protected $fillable = ['kind', 'name', 'direct_key', 'created_by', 'last_message_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_message_at' => 'immutable_datetime'];
    }
}
