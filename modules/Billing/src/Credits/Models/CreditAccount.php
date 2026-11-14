<?php

declare(strict_types=1);

namespace Modules\Billing\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * A credit balance: the brokerage pool (user_id null) or one person's own credits (specs/billing/01).
 * Written only by CreditLedger, which changes balances together with a ledger entry.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property int $balance
 * @property int $included_balance
 * @property int $lock_version
 */
final class CreditAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'credit_accounts';

    protected $guarded = ['*'];

    public function getConnectionName(): ?string
    {
        return Connections::cell();
    }

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'balance' => 'integer', 'included_balance' => 'integer', 'lock_version' => 'integer'];
    }
}
