<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A sealed audit event (read-only for the application).
 *
 * @property int $id
 * @property string $ulid
 * @property int $chain_seq
 * @property CarbonImmutable $occurred_at
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $impersonator_name
 * @property int|null $on_behalf_of_user_id
 * @property string|null $automation
 * @property string|null $ip
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string $severity
 */
final class AuditEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'audit_events';

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::creating(fn () => throw new LogicException('Audit events are written by the audit worker only.'));
        self::updating(fn () => throw new LogicException('Audit events cannot be changed.'));
        self::deleting(fn () => throw new LogicException('Audit events cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'changes' => 'array', 'metadata' => 'array'];
    }
}
