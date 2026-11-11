<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property string $name
 */
final class Tag extends Model
{
    use BelongsToTenant;

    protected $table = 'crm_tags';

    protected $fillable = ['name'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }
}
