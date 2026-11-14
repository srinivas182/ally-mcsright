<?php

declare(strict_types=1);

namespace Modules\Billing\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * One append-only line in a brokerage's credit ledger (specs/billing/01). Written only by CreditLedger.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $account_id
 * @property string $entry_type
 * @property int $amount
 * @property int $included_amount
 * @property string $period
 * @property string|null $action_key
 * @property int|null $user_id
 * @property bool $automation
 * @property string $idempotency_key
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
final class CreditLedgerEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $guarded = ['*'];

    public function getConnectionName(): ?string
    {
        return Connections::cell();
    }

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'amount' => 'integer', 'included_amount' => 'integer', 'user_id' => 'integer', 'automation' => 'boolean',
            'created_by' => 'integer', 'created_at' => 'datetime'];
    }
}
