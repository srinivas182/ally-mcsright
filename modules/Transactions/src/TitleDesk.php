<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Wire-fraud safeguards (specs/transactions/01, addition 4): CDAs only go to title companies verified by
 * a call-back on a known number; the brokerage's receiving account (shown on CDAs by last four digits
 * only) changes only after a second person confirms the change by call-back.
 */
final class TitleDesk
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function addCompany(string $name, string $email, string $phone): string
    {
        if (trim($name) === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen(preg_replace('/\D/', '', $phone) ?? '') < 10) {
            throw ValidationException::withMessages(['title' => 'Give the title company\'s name, email and a 10-digit phone number.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_title_companies')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(),
            'name' => trim($name), 'email' => mb_strtolower(trim($email)), 'phone' => trim($phone), 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    public function verifyCompany(string $ulid, string $note, User $by): void
    {
        if (mb_strlen(trim($note)) < 15) {
            throw ValidationException::withMessages(['note' => 'Record the call-back: which number you called (from a source you trust) and who confirmed.']);
        }
        DB::connection(Connections::cell())->table('tx_title_companies')->where('ulid', $ulid)
            ->update(['verified_at' => now(), 'verified_by' => $by->id, 'verification_note' => mb_substr(trim($note), 0, 300), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.title.verified', metadata: ['title' => $ulid, 'note' => $note], severity: 'warning');
    }

    /** First person: proposes new receiving-account details (bank and last four digits only). */
    public function proposeWire(string $bankName, string $last4, User $by): void
    {
        $this->guard($by);
        if (trim($bankName) === '' || preg_match('/^\d{4}$/', $last4) !== 1) {
            throw ValidationException::withMessages(['wire' => 'Give the bank name and the last four digits of the account.']);
        }
        $this->settings(['wire_pending' => json_encode(['bank' => trim($bankName), 'last4' => $last4, 'by' => $by->id, 'at' => now()->toIso8601String()])]);
        app(AuditLogger::class)->record('transactions.wire.proposed', metadata: ['bank' => $bankName, 'last4' => $last4], severity: 'critical');
    }

    /** Second person: confirms by calling the bank or the owner on a known number. */
    public function confirmWire(string $callbackNote, User $by): void
    {
        $this->guard($by);
        $pending = json_decode((string) DB::connection(Connections::cell())->table('tx_settings')->value('wire_pending'), true);
        if (! is_array($pending)) {
            throw ValidationException::withMessages(['wire' => 'There is no change waiting for confirmation.']);
        }
        if ((int) $pending['by'] === $by->id) {
            throw ValidationException::withMessages(['wire' => 'A different person must confirm a change to the receiving account.']);
        }
        if (mb_strlen(trim($callbackNote)) < 15) {
            throw ValidationException::withMessages(['note' => 'Record the call-back: the number you called and who confirmed.']);
        }
        $this->settings(['wire_bank_name' => $pending['bank'], 'wire_last4' => $pending['last4'], 'wire_verified_at' => now(), 'wire_verified_by' => $by->id, 'wire_pending' => null]);
        app(AuditLogger::class)->record('transactions.wire.confirmed', metadata: ['bank' => $pending['bank'], 'last4' => $pending['last4'], 'note' => $callbackNote], severity: 'critical');
    }

    /**
     * @return array{bank: string|null, last4: string|null, verified_at: string|null, pending: array<string, mixed>|null}
     */
    public function wire(): array
    {
        $row = DB::connection(Connections::cell())->table('tx_settings')->first();
        $pending = $row !== null && $row->wire_pending !== null ? json_decode((string) $row->wire_pending, true) : null;

        return ['bank' => $row?->wire_bank_name, 'last4' => $row?->wire_last4, 'verified_at' => $row?->wire_verified_at !== null ? (string) $row->wire_verified_at : null,
            'pending' => is_array($pending) ? $pending : null];
    }

    private function guard(User $by): void
    {
        if (! $by->can('finance.bank_details.manage')) {
            throw ValidationException::withMessages(['wire' => 'Changing the receiving account needs the "Change payout and bank details" permission.']);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function settings(array $values): void
    {
        DB::connection(Connections::cell())->table('tx_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], $values + ['updated_at' => now(), 'created_at' => now()]);
    }
}
