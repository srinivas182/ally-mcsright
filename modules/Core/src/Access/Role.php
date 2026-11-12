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
 * @property int $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $template_key
 * @property bool $is_locked
 */
final class Role extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;
    use SoftDeletes;

    protected $table = 'roles';

    protected $fillable = ['key', 'name', 'description', 'template_key', 'is_locked', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }
}
