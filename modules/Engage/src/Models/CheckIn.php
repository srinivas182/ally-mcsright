<?php

declare(strict_types=1);

namespace Modules\Engage\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A personal check-in email drafted by the database assistant (EN1, D23).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $contact_id
 * @property string $status
 * @property string $subject
 * @property string $body
 * @property string|null $intent
 * @property string|null $brief
 * @property string|null $suggested_reply
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $replied_at
 * @property CarbonImmutable $created_at
 */
final class CheckIn extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'engage_checkins';

    protected $fillable = ['user_id', 'contact_id', 'status', 'subject', 'body', 'intent', 'brief', 'suggested_reply', 'sent_at', 'replied_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'brief' => 'encrypted', 'suggested_reply' => 'encrypted', 'sent_at' => 'immutable_datetime', 'replied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime'];
    }
}
