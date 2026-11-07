<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Running balance per account, kept in the same transaction as each posting.
 * Stored as net debit (debits minus credits); LedgerService converts to the
 * account's natural sign. Reconciled nightly against the lines.
 *
 * @property int $account_id
 * @property int $tenant_id
 * @property string $currency
 * @property int $net_debit_cents
 * @property int|null $last_journal_id
 * @property int $lock_version
 */
final class LedgerBalance extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'account_id';

    protected $fillable = ['account_id', 'currency', 'net_debit_cents', 'last_journal_id', 'lock_version'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['net_debit_cents' => 'integer', 'lock_version' => 'integer'];
    }
}
