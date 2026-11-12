<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property string|null $reason
 */
final class UserRole extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'user_roles';

    protected $fillable = ['user_id', 'role_id', 'granted_by', 'granted_at', 'expires_at', 'reason'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
