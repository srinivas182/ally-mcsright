<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Agents' bank details for payouts (specs/finance/02, addition 2): routing and account numbers encrypted,
 * only the last four digits shown; every change is emailed to the agent and the new account can only be
 * paid after 72 hours; the previous account is kept on record.
 */
final class BankAccounts
{
    public const HOLD_HOURS = 72;

    public function set(User $owner, string $holder, string $routing, string $account, string $type, User $by): void
    {
        $routing = preg_replace('/\D/', '', $routing) ?? '';
        $account = preg_replace('/\D/', '', $account) ?? '';
        if (! self::validRouting($routing)) {
            throw ValidationException::withMessages(['routing' => 'That routing number is not valid. Check the 9 digits on a check or your bank statement.']);
        }
        if (strlen($account) < 4 || strlen($account) > 17 || ! in_array($type, ['checking', 'savings'], true) || trim($holder) === '') {
            throw ValidationException::withMessages(['account' => 'Give the account holder, an account number of 4–17 digits and checking or savings.']);
        }
        if ($by->id !== $owner->id && ! $by->can('finance.bank_details.manage')) {
            throw ValidationException::withMessages(['account' => 'Only the agent, or someone with the bank-details permission, can change this.']);
        }

        $db = DB::connection(Connections::cell());
        $previous = $db->table('pay_bank_accounts')->where('user_id', $owner->id)->where('status', 'active')->value('last4');
        $usable = CarbonImmutable::now()->addHours(self::HOLD_HOURS);
        $db->transaction(function () use ($db, $owner, $holder, $routing, $account, $type, $by, $usable): void {
            $db->table('pay_bank_accounts')->where('user_id', $owner->id)->where('status', 'active')->update(['status' => 'replaced', 'updated_at' => now()]);
            $db->table('pay_bank_accounts')->insert([
                'ulid' => (string) Str::ulid(), 'tenant_id' => (int) $owner->getAttribute('tenant_id'), 'user_id' => $owner->id, 'holder_name' => mb_substr(trim($holder), 0, 120),
                'routing_sealed' => Crypt::encryptString($routing), 'account_sealed' => Crypt::encryptString($account), 'last4' => substr($account, -4),
                'type' => $type, 'status' => 'active', 'usable_from' => $usable, 'changed_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $owner->notify(new BankDetailsChanged(substr($account, -4), $previous !== null ? (string) $previous : null, $usable, $by->id === $owner->id ? null : $by->name));
        app(AuditLogger::class)->record('payouts.bank.changed', $owner, metadata: ['last4' => substr($account, -4), 'previous_last4' => $previous, 'usable_from' => $usable->toIso8601String()],
            severity: 'critical', label: $owner->name);
    }

    /**
     * @return array{last4: string, type: string, holder: string, usable_from: string, usable: bool}|null
     */
    public function current(User $user): ?array
    {
        $row = DB::connection(Connections::cell())->table('pay_bank_accounts')->where('user_id', $user->id)->where('status', 'active')->first();
        if ($row === null) {
            return null;
        }

        return ['last4' => (string) $row->last4, 'type' => (string) $row->type, 'holder' => (string) $row->holder_name,
            'usable_from' => (string) $row->usable_from, 'usable' => CarbonImmutable::parse((string) $row->usable_from)->isPast()];
    }

    /**
     * Full details for a payout file — only when the 72-hour hold has passed.
     *
     * @return array{routing: string, account: string, type: string, holder: string}|null
     */
    public function forPayout(int $userId): ?array
    {
        $row = DB::connection(Connections::cell())->table('pay_bank_accounts')->where('user_id', $userId)->where('status', 'active')
            ->where('usable_from', '<=', now())->first();

        return $row === null ? null : ['routing' => Crypt::decryptString((string) $row->routing_sealed), 'account' => Crypt::decryptString((string) $row->account_sealed),
            'type' => (string) $row->type, 'holder' => (string) $row->holder_name];
    }

    /** ABA routing number checksum (3-7-1 weights). */
    public static function validRouting(string $routing): bool
    {
        if (preg_match('/^\d{9}$/', $routing) !== 1) {
            return false;
        }
        $d = array_map('intval', str_split($routing));
        $sum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

        return $sum % 10 === 0;
    }
}
