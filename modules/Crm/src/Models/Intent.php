<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Something the contact said they plan to do, read from the agent's notes, with a date if known.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $contact_id
 * @property string $kind
 * @property string $summary
 * @property CarbonImmutable|null $due_on
 * @property string $confidence
 * @property string $status
 * @property int|null $source_activity_id
 * @property int|null $alert_task_id
 * @property Contact $contact
 */
final class Intent extends Model
{
    use BelongsToTenant;

    public const KINDS = ['buy', 'sell', 'relocate', 'lease_end', 'retire', 'family_change', 'other'];

    protected $table = 'crm_intents';

    protected $fillable = ['contact_id', 'kind', 'summary', 'due_on', 'confidence', 'status', 'source_activity_id', 'alert_task_id'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['summary' => 'encrypted', 'due_on' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
