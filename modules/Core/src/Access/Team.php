<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int|null $office_id
 * @property string $name
 * @property int|null $leader_user_id
 */
final class Team extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;
    use SoftDeletes;

    protected $table = 'teams';

    protected $fillable = ['office_id', 'name', 'leader_user_id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
