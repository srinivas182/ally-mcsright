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
 * The AI first responder's work on one lead (specs/grow/06).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lead_id
 * @property int $contact_id
 * @property string $status
 * @property string|null $origin
 * @property string|null $assistant_name
 * @property string|null $channel
 * @property int $messages_sent
 * @property array<string, string>|null $qualification
 * @property string|null $handoff_reason
 * @property CarbonImmutable|null $handed_off_at
 * @property string|null $summary
 * @property string|null $next_message
 * @property CarbonImmutable|null $last_reply_at
 * @property Lead $lead
 * @property Contact $contact
 */
final class AiThread extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_ai_threads';

    protected $fillable = ['lead_id', 'contact_id', 'status', 'origin', 'assistant_name', 'channel', 'messages_sent', 'qualification', 'handoff_reason', 'handed_off_at', 'summary', 'next_message', 'last_reply_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qualification' => 'encrypted:array', 'summary' => 'encrypted', 'next_message' => 'encrypted', 'handed_off_at' => 'immutable_datetime', 'last_reply_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
