<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A timeline entry on a contact. Visible to whoever can see the contact.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $contact_id
 * @property string $type
 * @property string|null $direction
 * @property string|null $outcome
 * @property int|null $duration_seconds
 * @property string|null $subject
 * @property string|null $body
 * @property bool $pinned
 * @property int|null $user_id
 * @property CarbonImmutable $occurred_at
 * @property Contact $contact
 */
final class Activity extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public const TYPES = ['note', 'call', 'meeting', 'email', 'text', 'system'];

    public const CALL_OUTCOMES = ['connected', 'left_voicemail', 'no_answer', 'wrong_number'];

    protected $table = 'crm_activities';

    protected $fillable = ['contact_id', 'type', 'direction', 'outcome', 'duration_seconds', 'subject', 'body', 'pinned', 'user_id', 'occurred_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'occurred_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
