<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Enums\NormalSide;

/**
 * An account in a tenant's chart of accounts, optionally a sub-ledger (e.g. one agent's payable).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalSide $normal_side
 * @property int|null $parent_id
 * @property string|null $subledger_type
 * @property int|null $subledger_id
 * @property bool $is_system
 * @property string $status
 */
final class LedgerAccount extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $fillable = ['code', 'name', 'type', 'normal_side', 'parent_id', 'subledger_type', 'subledger_id', 'is_system', 'status'];

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
            'type' => AccountType::class,
            'normal_side' => NormalSide::class,
            'is_system' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasOne<LedgerBalance, $this>
     */
    public function balanceRecord(): HasOne
    {
        return $this->hasOne(LedgerBalance::class, 'account_id');
    }
}
