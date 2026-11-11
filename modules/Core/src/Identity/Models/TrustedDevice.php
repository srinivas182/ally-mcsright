<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $token_hash
 * @property string $label
 * @property string|null $ip
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $expires_at
 */
final class TrustedDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'token_hash', 'label', 'ip', 'last_used_at', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
