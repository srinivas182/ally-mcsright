<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        '1150' => ['Accounts receivable', AccountType::Asset],
        '1200' => ['Agent fees receivable', AccountType::Asset],
        '1300' => ['Payment processor clearing', AccountType::Asset],
        '2000' => ['Agent commissions payable', AccountType::Liability],
        '2100' => ['Revenue share payable', AccountType::Liability],
        '2200' => ['Referral fees payable', AccountType::Liability],
        '2300' => ['Escrow deposits held', AccountType::Liability],
        '2400' => ['Accounts payable', AccountType::Liability],
        '3000' => ['Brokerage equity', AccountType::Equity],
        '4000' => ['Company dollar', AccountType::Revenue],
        '4100' => ['Agent fee revenue', AccountType::Revenue],
        '4200' => ['Other income', AccountType::Revenue],
        '5000' => ['Revenue share expense', AccountType::Expense],
        '5100' => ['Referral fee expense', AccountType::Expense],
        '5200' => ['Processor fees', AccountType::Expense],
        // AC1: everyday operating expenses for bills and the bank feed.
        '6000' => ['Rent and office', AccountType::Expense],
        '6100' => ['Marketing and advertising', AccountType::Expense],
        '6200' => ['Software and technology', AccountType::Expense],
        '6300' => ['MLS and association dues', AccountType::Expense],
        '6400' => ['Insurance (E&O)', AccountType::Expense],
        '6500' => ['Staff payroll', AccountType::Expense],
        '6600' => ['Professional fees (legal, accounting)', AccountType::Expense],
        '6700' => ['Bank fees', AccountType::Expense],
        '6900' => ['Other expenses', AccountType::Expense],
    ];

    /**
     * Create the system accounts for the current tenant. Safe to run more than once.
     */
    public function seed(): void
    {
        // PS2: one query when the chart is already complete (the usual case) instead of one per standard account.
        $have = LedgerAccount::query()->whereIn('code', array_map('strval', array_keys(self::SYSTEM)))->pluck('code')->map(fn ($c): string => (string) $c)->all();
        foreach (self::SYSTEM as $code => [$name, $type]) {
            if (in_array((string) $code, $have, true)) {
                continue;
            }
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
        $account = LedgerAccount::query()->where('code', $code)->first();
        if ($account === null && array_key_exists($code, self::SYSTEM)) {
            // A new brokerage's chart is created on first use, so a standard account is never "not found".
            $this->seed();
            $account = LedgerAccount::query()->where('code', $code)->first();
        }

        return $account ?? throw (new ModelNotFoundException)->setModel(LedgerAccount::class, [$code]);
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
