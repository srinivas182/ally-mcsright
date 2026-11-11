<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A Google or Microsoft account linked to a user, by the provider's permanent subject ID.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 * @property string|null $email
 * @property CarbonImmutable|null $last_used_at
 */
final class UserIdentity extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'provider', 'subject', 'email', 'last_used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'immutable_datetime'];
    }
}
