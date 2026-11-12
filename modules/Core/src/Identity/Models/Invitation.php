<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An invitation to join a tenant. Only a SHA-256 hash of the token is stored; the raw token exists
 * only in the email.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $email
 * @property string $first_name
 * @property string $last_name
 * @property UserKind $kind
 * @property list<int> $role_ids
 * @property int|null $assisted_user_id
 * @property int|null $invited_by
 * @property int|null $sponsor_user_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
final class Invitation extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $fillable = [
        'email', 'first_name', 'last_name', 'kind', 'role_ids', 'assisted_user_id', 'invited_by', 'sponsor_user_id',
        'token_hash', 'expires_at', 'accepted_at', 'revoked_at',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UserKind::class,
            'role_ids' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
