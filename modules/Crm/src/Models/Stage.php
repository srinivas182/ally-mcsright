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
 * @property int $pipeline_id
 * @property string $name
 * @property string|null $outcome
 * @property int $sort
 */
final class Stage extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_stages';

    protected $fillable = ['pipeline_id', 'name', 'outcome', 'sort'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
