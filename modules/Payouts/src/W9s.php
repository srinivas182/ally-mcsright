<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * W-9s (specs/finance/02, part 5): collected online with a typed signature, TIN encrypted and only the
 * last four digits shown. TIN matching uses the IRS bulk service (a file uploaded through IRS e-Services,
 * results back within a day): RightAlly writes the request file and imports the IRS result codes.
 */
final class W9s
{
    public const CLASSES = ['individual', 'llc_single', 'llc_partnership', 'llc_corporation', 'c_corp', 's_corp', 'partnership'];

    /** IRS bulk TIN matching result codes. */
    public const CODES = [0 => 'matched', 1 => 'invalid', 2 => 'mismatch', 3 => 'mismatch', 4 => 'invalid', 5 => 'invalid', 6 => 'matched', 7 => 'matched', 8 => 'matched'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{legal_name: string, business_name?: string|null, tax_class: string, tin_type: string, tin: string, address: string, signature_name: string, certify: bool}  $data
     */
    public function submit(array $data, ?User $agent = null, ?int $referralId = null, ?User $by = null): string
    {
        $tin = preg_replace('/\D/', '', $data['tin']) ?? '';
        if (strlen($tin) !== 9 || ! in_array($data['tin_type'], ['ssn', 'ein'], true) || ! in_array($data['tax_class'], self::CLASSES, true)) {
            throw ValidationException::withMessages(['tin' => 'Give a 9-digit SSN or EIN and the federal tax classification.']);
        }
        if (! $data['certify'] || mb_strtolower(trim($data['signature_name'])) !== mb_strtolower(trim($data['legal_name']))) {
            throw ValidationException::withMessages(['signature_name' => 'Certify the statements and sign by typing your legal name exactly.']);
        }
        if (trim($data['address']) === '') {
            throw ValidationException::withMessages(['address' => 'Give the mailing address for the 1099.']);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $data, $tin, $agent, $referralId, $ulid): void {
            $db->table('pay_w9s')->where($agent !== null ? 'user_id' : 'referral_id', $agent !== null ? $agent->id : $referralId)->where('status', 'current')
                ->update(['status' => 'replaced', 'updated_at' => now()]);
            $db->table('pay_w9s')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent?->id, 'referral_id' => $referralId,
                'legal_name' => mb_substr(trim($data['legal_name']), 0, 120), 'business_name' => isset($data['business_name']) ? mb_substr(trim((string) $data['business_name']), 0, 120) : null,
                'tax_class' => $data['tax_class'], 'tin_type' => $data['tin_type'], 'tin_sealed' => Crypt::encryptString($tin), 'tin_last4' => substr($tin, -4),
                'address' => mb_substr(trim($data['address']), 0, 200), 'signature_name' => mb_substr(trim($data['signature_name']), 0, 120), 'signed_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            if ($referralId !== null) {
                $db->table('tx_referrals')->where('id', $referralId)->update(['w9_received' => true]);
            }
        });
        app(AuditLogger::class)->record('payouts.w9.submitted', $agent, metadata: ['tin_last4' => substr($tin, -4), 'tin_type' => $data['tin_type'], 'referral_id' => $referralId, 'by' => $by?->id],
            severity: 'notice', label: $agent !== null ? $agent->name : $data['legal_name']);

        return $ulid;
    }

    /**
     * @return array{legal_name: string, tin_last4: string, tin_type: string, match_status: string, signed_at: string}|null
     */
    public function current(?int $userId = null, ?int $referralId = null): ?array
    {
        $row = DB::connection(Connections::cell())->table('pay_w9s')->where($userId !== null ? 'user_id' : 'referral_id', $userId ?? $referralId)->where('status', 'current')->first();

        return $row === null ? null : ['legal_name' => (string) $row->legal_name, 'tin_last4' => (string) $row->tin_last4, 'tin_type' => (string) $row->tin_type,
            'match_status' => (string) $row->match_status, 'signed_at' => (string) $row->signed_at];
    }

    /**
     * The IRS bulk TIN matching request file: "TIN type;TIN;Name;Account number" per line
     * (TIN type 1 = EIN, 2 = SSN), the account number being RightAlly's reference to read results back.
     */
    public function bulkFile(User $by): string
    {
        $this->guard($by);
        $lines = [];
        foreach (DB::connection(Connections::cell())->table('pay_w9s')->where('status', 'current')->whereIn('match_status', ['pending', 'mismatch', 'invalid'])->get() as $w) {
            $name = mb_strtoupper(preg_replace('/[^A-Za-z0-9 &-]/', '', (string) $w->legal_name) ?? '');
            $lines[] = ($w->tin_type === 'ein' ? '1' : '2').';'.Crypt::decryptString((string) $w->tin_sealed).';'.mb_substr($name, 0, 40).';'.$w->ulid;
        }
        app(AuditLogger::class)->record('payouts.tin.bulk_file', metadata: ['records' => count($lines)], severity: 'warning');

        return implode("\r\n", $lines).($lines === [] ? '' : "\r\n");
    }

    /** Imports the IRS result file (the request lines with the result code appended). */
    public function importResults(string $contents, User $by): int
    {
        $this->guard($by);
        $updated = 0;
        foreach (preg_split('/\r\n|\n/', trim($contents)) ?: [] as $line) {
            $parts = explode(';', trim($line));
            if (count($parts) < 5 || ! ctype_digit($parts[4])) {
                continue;
            }
            $code = (int) $parts[4];
            $updated += DB::connection(Connections::cell())->table('pay_w9s')->where('ulid', $parts[3])->where('status', 'current')
                ->update(['match_status' => self::CODES[$code] ?? 'invalid', 'match_code' => $code, 'matched_at' => now(), 'updated_at' => now()]);
        }
        app(AuditLogger::class)->record('payouts.tin.results_imported', metadata: ['updated' => $updated], severity: 'warning');

        return $updated;
    }

    public function tin(int $w9Id): string
    {
        return Crypt::decryptString((string) DB::connection(Connections::cell())->table('pay_w9s')->where('id', $w9Id)->value('tin_sealed'));
    }

    private function guard(User $by): void
    {
        if (! $by->can('finance.billing.manage')) {
            throw ValidationException::withMessages(['tin' => 'Tax records are for finance.']);
        }
    }
}
