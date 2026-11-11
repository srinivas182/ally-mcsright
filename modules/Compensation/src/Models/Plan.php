<?php

declare(strict_types=1);

namespace Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Compensation\Enums\PlanStatus;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property PlanType $type
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property PlanStatus $status
 * @property int|null $created_by
 */
final class Plan extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'comp_plans';

    protected $fillable = ['type', 'name', 'description', 'is_default', 'status', 'created_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => PlanType::class, 'status' => PlanStatus::class, 'is_default' => 'boolean'];
    }

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    public function latestPublished(): ?PlanVersion
    {
        return $this->versions()->where('status', VersionStatus::Published)->orderByDesc('version')->first();
    }
}
