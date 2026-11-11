<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $owner_user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property bool $shared
 * @property int $sort
 */
final class SmartList extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_smart_lists';

    protected $fillable = ['owner_user_id', 'name', 'filters', 'shared', 'sort'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'shared' => 'boolean'];
    }
}
