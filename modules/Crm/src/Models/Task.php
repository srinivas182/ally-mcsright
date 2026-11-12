<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Audit\Auditable;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A to-do, usually about a contact. Visible to its assignee and to whoever can see the contact.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $contact_id
 * @property int $assigned_to
 * @property string $type
 * @property string $title
 * @property string|null $notes
 * @property string $priority
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $remind_at
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property int|null $created_by
 * @property int|null $plan_enrollment_id
 * @property string|null $message
 * @property Contact|null $contact
 */
final class Task extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUlid;

    public const TYPES = ['call', 'text', 'email', 'follow_up', 'showing', 'other'];

    protected $table = 'crm_tasks';

    protected static string $auditAction = 'crm.task';

    /** @var list<string> */
    protected static array $audited = ['title', 'due_at', 'assigned_to', 'completed_at'];

    /** @var list<string> */
    protected static array $auditMasked = [];

    protected $fillable = ['contact_id', 'assigned_to', 'type', 'title', 'notes', 'priority', 'due_at', 'remind_at', 'reminded_at', 'completed_at', 'completed_by', 'created_by', 'plan_enrollment_id', 'message'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'remind_at' => 'immutable_datetime', 'reminded_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_at->isPast();
    }
}
