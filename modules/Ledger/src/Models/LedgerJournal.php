<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Ledger\Exceptions\ImmutableLedgerRecord;

/**
 * A posted, balanced journal. Append-only: the only change ever allowed is setting
 * reversed_by_id once, when the journal is reversed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $journal_type
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string $posting_key
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable $posted_at
 * @property string|null $description
 * @property int|null $reversal_of_id
 * @property int|null $reversed_by_id
 * @property int $period_id
 * @property int|null $created_by
 */
final class LedgerJournal extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    public $timestamps = false;

    protected $fillable = [
        'journal_type', 'source_type', 'source_id', 'posting_key', 'effective_date', 'posted_at',
        'description', 'reversal_of_id', 'period_id', 'created_by',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::updating(function (LedgerJournal $journal): void {
            $dirty = array_keys($journal->getDirty());
            $onlyReversal = $dirty === ['reversed_by_id'] && $journal->getOriginal('reversed_by_id') === null;

            if (! $onlyReversal) {
                throw new ImmutableLedgerRecord('Ledger journals cannot be changed; post a reversal instead.');
            }
        });

        self::deleting(function (): void {
            throw new ImmutableLedgerRecord('Ledger journals cannot be deleted; post a reversal instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<LedgerLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'journal_id');
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }
}
