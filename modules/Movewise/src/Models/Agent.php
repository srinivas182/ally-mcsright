<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An agent in the Movewise program, in one state (specs/movewise/03). They live in a tenant: their brokerage, or a
 * free Movewise Agent seat.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $state
 * @property string $licence_number
 * @property string $licence_status
 * @property CarbonImmutable|null $licence_expires_on
 * @property CarbonImmutable|null $licence_checked_at
 * @property string $brokerage_name
 * @property CarbonImmutable|null $brokerage_confirmed_at
 * @property int|null $brokerage_confirmed_by
 * @property CarbonImmutable|null $discipline_checked_at
 * @property array{display_name?: string, bio?: string, languages?: list<string>, years?: int, specialties?: list<string>, photo?: string|null, phone?: string|null} $profile
 * @property string $profile_status
 * @property string|null $review_note
 * @property string|null $terms_version
 * @property CarbonImmutable|null $terms_signed_at
 * @property string|null $terms_signed_ip
 * @property string $status
 * @property string|null $pause_reason
 * @property string $tier
 * @property int $strikes
 * @property bool $seat
 * @property CarbonImmutable $created_at
 */
final class Agent extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_agents';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    protected function casts(): array
    {
        return ['profile' => 'array', 'licence_expires_on' => 'immutable_date', 'licence_checked_at' => 'immutable_datetime', 'brokerage_confirmed_at' => 'immutable_datetime',
            'discipline_checked_at' => 'immutable_datetime', 'terms_signed_at' => 'immutable_datetime', 'seat' => 'boolean', 'strikes' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
