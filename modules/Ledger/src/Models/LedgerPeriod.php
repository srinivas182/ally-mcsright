<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Ledger\Enums\PeriodStatus;

/**
 * A monthly accounting period. Closed periods accept no postings.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property PeriodStatus $status
 * @property CarbonImmutable|null $checked_at
 * @property array{note: string, items: list<array{title: string, detail: string}>}|null $close_notes
 */
final class LedgerPeriod extends Model
{
    use BelongsToTenant;

    protected $fillable = ['starts_on', 'ends_on', 'status', 'closed_by', 'closed_at', 'close_notes', 'checked_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => PeriodStatus::class,
            'closed_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'close_notes' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed;
    }
}
