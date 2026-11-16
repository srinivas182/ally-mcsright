<?php

declare(strict_types=1);

namespace Modules\Movewise\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * An agent not on RightAlly asking for the free Movewise Agent seat (specs/movewise/03).
 *
 * @property int $id
 * @property string $ulid
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $state
 * @property string $licence_number
 * @property string $brokerage_name
 * @property string $licence_status
 * @property string $status
 * @property string|null $note
 * @property int|null $tenant_id
 */
final class SeatRequest extends Model
{
    use HasPublicUlid;

    protected $table = 'movewise_seat_requests';

    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return Connections::control();
    }
}
