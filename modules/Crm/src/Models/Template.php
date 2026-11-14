<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A message template with merge fields; shared with the brokerage (user_id null) or personal.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $user_id
 * @property string $name
 * @property string $channel
 * @property string|null $subject
 * @property string $body
 */
final class Template extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_templates';

    protected $fillable = ['user_id', 'name', 'channel', 'subject', 'body'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
