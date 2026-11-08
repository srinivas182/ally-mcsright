<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerLine;

/**
 * Custom accounts alongside the system chart. System accounts can never be archived; custom
 * accounts can be archived only when their balance is zero.
 */
final class ManageAccounts
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function create(string $code, string $name, AccountType $type): LedgerAccount
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^\d{4}(-[A-Z0-9]{1,20})?$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Use a 4-digit code, optionally followed by a hyphen and letters or digits (e.g. 6100 or 6100-MKTG).']);
        }

        if (LedgerAccount::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'An account with this code already exists.']);
        }

        return LedgerAccount::query()->create([
            'code' => $code,
            'name' => trim($name),
            'type' => $type,
            'normal_side' => $type->normalSide(),
            'is_system' => false,
            'status' => 'active',
        ]);
    }

    public function archive(LedgerAccount $account): LedgerAccount
    {
        if ($account->is_system) {
            throw ValidationException::withMessages(['account' => 'System accounts cannot be archived.']);
        }

        $hasActivity = LedgerLine::query()->where('account_id', $account->id)->exists();
        if ($hasActivity && ! $this->ledger->balance($account)->isZero()) {
            throw ValidationException::withMessages(['account' => 'Only accounts with a zero balance can be archived.']);
        }

        $account->status = 'archived';
        $account->save();

        return $account;
    }
}
