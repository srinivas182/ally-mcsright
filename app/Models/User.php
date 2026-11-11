<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A person signed in to one tenant (specs/core/02). Email is unique per tenant; the same person in
 * two tenants has two accounts.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property UserKind $kind
 * @property string $first_name
 * @property string $last_name
 * @property-read string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $phone
 * @property string|null $password
 * @property UserStatus $status
 * @property string|null $locale
 * @property string|null $timezone
 * @property int|null $assisted_user_id
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $passkey_prompt_dismissed_at
 * @property int|null $created_by
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicUlid;
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'kind', 'first_name', 'last_name', 'email', 'phone', 'password', 'status', 'locale', 'timezone',
        'assisted_user_id', 'created_by',
    ];

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'];

    protected $appends = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UserKind::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'passkey_prompt_dismissed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Full name, so existing screens that show "name" keep working.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assistedAgent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'assisted_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isKind(UserKind ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }
}
