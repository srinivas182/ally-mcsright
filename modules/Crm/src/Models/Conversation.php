<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One conversation per contact: every text and email both ways (specs/grow/05).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $contact_id
 * @property int|null $assigned_user_id
 * @property string $reply_token
 * @property CarbonImmutable|null $last_message_at
 * @property string|null $last_direction
 * @property string|null $preview
 * @property bool $unread
 * @property CarbonImmutable|null $needs_reply_since
 * @property CarbonImmutable|null $nudged_at
 * @property CarbonImmutable|null $escalated_at
 * @property Contact $contact
 */
final class Conversation extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_conversations';

    protected $fillable = ['contact_id', 'assigned_user_id', 'reply_token', 'last_message_at', 'last_direction', 'preview', 'unread', 'needs_reply_since', 'nudged_at', 'escalated_at'];

    protected $hidden = ['reply_token'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['preview' => 'encrypted', 'unread' => 'boolean', 'last_message_at' => 'immutable_datetime', 'needs_reply_since' => 'immutable_datetime',
            'nudged_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
