<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Ledger\Exceptions\ImmutableLedgerRecord;
use Modules\Ledger\Money\Money;

/**
 * One debit or credit line of a journal. Never updated or deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $journal_id
 * @property int $account_id
 * @property int $debit_cents
 * @property int $credit_cents
 * @property string $currency
 * @property string|null $memo
 */
final class LedgerLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'journal_id', 'account_id', 'debit_cents', 'credit_cents', 'currency', 'memo',
        'dimension_office_id', 'dimension_team_id',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new ImmutableLedgerRecord('Ledger lines cannot be changed; post a reversal instead.');
        });

        self::deleting(function (): void {
            throw new ImmutableLedgerRecord('Ledger lines cannot be deleted; post a reversal instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['debit_cents' => 'integer', 'credit_cents' => 'integer'];
    }

    public function debit(): Money
    {
        return Money::of($this->debit_cents, $this->currency);
    }

    public function credit(): Money
    {
        return Money::of($this->credit_cents, $this->currency);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }
}
