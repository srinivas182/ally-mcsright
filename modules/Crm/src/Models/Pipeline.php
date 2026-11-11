<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $kind
 * @property int $sort
 */
final class Pipeline extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_pipelines';

    protected $fillable = ['name', 'kind', 'sort'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return HasMany<Stage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort');
    }
}
