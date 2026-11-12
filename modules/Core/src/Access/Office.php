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
 * @property string $name
 * @property bool $is_primary
 */
final class Office extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;
    use SoftDeletes;

    protected $table = 'offices';

    protected $fillable = ['name', 'city', 'state_code', 'licence_number', 'is_primary'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
