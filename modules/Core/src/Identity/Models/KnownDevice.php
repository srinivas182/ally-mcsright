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
 * @property string $fingerprint
 * @property string $label
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
final class KnownDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'fingerprint', 'label', 'first_seen_at', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
