<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * Pauses retention deletion for a brokerage, a date range or one record, until released.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $reason
 * @property CarbonImmutable|null $from_date
 * @property CarbonImmutable|null $to_date
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property int $created_by
 * @property CarbonImmutable|null $released_at
 * @property int|null $released_by
 * @property CarbonImmutable|null $created_at
 */
final class LegalHold extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'audit_legal_holds';

    protected $fillable = ['reason', 'from_date', 'to_date', 'subject_type', 'subject_id', 'subject_label', 'created_by', 'released_at', 'released_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'released_at' => 'immutable_datetime'];
    }

    public function describe(): string
    {
        return match (true) {
            $this->subject_id !== null => 'Records about '.($this->subject_label ?? $this->subject_type),
            $this->from_date !== null || $this->to_date !== null => 'Events '.($this->from_date?->toFormattedDateString() ?? 'from the start').' to '.($this->to_date?->toFormattedDateString() ?? 'now'),
            default => 'Everything',
        };
    }
}
