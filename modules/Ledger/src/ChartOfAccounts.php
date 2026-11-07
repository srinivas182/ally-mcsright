<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Models\LedgerAccount;

/**
 * System chart of accounts for a brokerage (specs/finance/01) and sub-ledger accounts.
 */
final class ChartOfAccounts
{
    /** @var array<array-key, array{0: string, 1: AccountType}> codes => [name, type] */
    public const SYSTEM = [
        '1000' => ['Operating cash', AccountType::Asset],
        '1010' => ['Trust / escrow cash', AccountType::Asset],
        '1100' => ['Commissions receivable', AccountType::Asset],
        '1200' => ['Agent fees receivable', AccountType::Asset],
        '1300' => ['Payment processor clearing', AccountType::Asset],
        '2000' => ['Agent commissions payable', AccountType::Liability],
        '2100' => ['Revenue share payable', AccountType::Liability],
        '2200' => ['Referral fees payable', AccountType::Liability],
        '2300' => ['Escrow deposits held', AccountType::Liability],
        '3000' => ['Brokerage equity', AccountType::Equity],
        '4000' => ['Company dollar', AccountType::Revenue],
        '4100' => ['Agent fee revenue', AccountType::Revenue],
        '5000' => ['Revenue share expense', AccountType::Expense],
        '5100' => ['Referral fee expense', AccountType::Expense],
        '5200' => ['Processor fees', AccountType::Expense],
    ];

    /**
     * Create the system accounts for the current tenant. Safe to run more than once.
     */
    public function seed(): void
    {
        foreach (self::SYSTEM as $code => [$name, $type]) {
            LedgerAccount::query()->firstOrCreate(['code' => (string) $code], [
                'name' => $name,
                'type' => $type,
                'normal_side' => $type->normalSide(),
                'is_system' => true,
                'status' => 'active',
            ]);
        }
    }

    public function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }

    /**
     * The sub-ledger account under a parent for one entity, e.g. one agent's commissions payable.
     */
    public function subAccount(string $parentCode, string $subledgerType, int $subledgerId, ?string $name = null): LedgerAccount
    {
        $parent = $this->account($parentCode);

        return LedgerAccount::query()->firstOrCreate(
            ['code' => $parentCode.'-'.strtoupper($subledgerType).'-'.$subledgerId],
            [
                'name' => $name ?? $parent->name.' #'.$subledgerId,
                'type' => $parent->type,
                'normal_side' => $parent->normal_side,
                'parent_id' => $parent->id,
                'subledger_type' => $subledgerType,
                'subledger_id' => $subledgerId,
                'is_system' => false,
                'status' => 'active',
            ],
        );
    }
}
