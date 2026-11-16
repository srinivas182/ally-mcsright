<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * Someone using Movewise (specs/movewise/02). Not a tenant user. Email only; a phone is never needed.
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property string|null $name
 * @property string $locale
 * @property array<string, mixed>|null $plan
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable $created_at
 */
final class Person extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_people';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }

    protected function casts(): array
    {
        return ['plan' => 'array', 'email_verified_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
