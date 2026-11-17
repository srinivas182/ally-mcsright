<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Safeguards\BrokerageKeys;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * 1099-NEC e-filing (AC2, specs/finance/21). The year's reportable payees (Tax1099) go to the IRS through an
 * authorised e-file partner (TaxBandits, keys set by HQ in Apps), filed through the IRS's IRIS system; the partner
 * also mails or posts recipient copies when the brokerage asks. Before anything is sent we check every payee has a
 * W-9 with a matched TIN and a full address, and the brokerage's payer details are complete. TINs are read only at
 * the moment of filing and never logged. Without the partner switched on, the IRS-ready CSV stays available.
 */
final class EFile1099
{
    public const APP = 'efile_1099';

    public function __construct(private readonly TenantContext $tenants, private readonly Tax1099 $tax, private readonly W9s $w9s, private readonly Http $http) {}

    /** @return array{legal_name: string, ein_last4: string, street: string, city: string, state: string, zip: string, phone: string}|null */
    public function payer(): ?array
    {
        $p = DB::connection(Connections::cell())->table('pay_1099_payer')->where('tenant_id', $this->tenants->id())->first();

        return $p === null ? null : ['legal_name' => (string) $p->legal_name, 'ein_last4' => (string) $p->ein_last4, 'street' => (string) $p->street, 'city' => (string) $p->city,
            'state' => (string) $p->state, 'zip' => (string) $p->zip, 'phone' => (string) ($p->phone ?? '')];
    }

    /**
     * @param  array{legal_name: string, ein?: string|null, street: string, city: string, state: string, zip: string, phone?: string|null}  $in
     */
    public function savePayer(array $in, User $by): void
    {
        $this->guard($by);
        $ein = preg_replace('/\D/', '', (string) ($in['ein'] ?? '')) ?? '';
        $current = DB::connection(Connections::cell())->table('pay_1099_payer')->where('tenant_id', $this->tenants->id())->first();
        if ($ein === '' && $current === null) {
            throw ValidationException::withMessages(['ein' => 'The brokerage\'s 9-digit EIN.']);
        }
        if ($ein !== '' && strlen($ein) !== 9) {
            throw ValidationException::withMessages(['ein' => 'The EIN has 9 digits.']);
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper($in['state'])) !== 1 || preg_match('/^\d{5}(-\d{4})?$/', $in['zip']) !== 1) {
            throw ValidationException::withMessages(['state' => 'The two-letter state and the ZIP code.']);
        }
        DB::connection(Connections::cell())->table('pay_1099_payer')->updateOrInsert(['tenant_id' => $this->tenants->id()], array_filter([
            'legal_name' => mb_substr(trim($in['legal_name']), 0, 120), 'street' => mb_substr(trim($in['street']), 0, 120), 'city' => mb_substr(trim($in['city']), 0, 60),
            'state' => strtoupper($in['state']), 'zip' => $in['zip'], 'phone' => ($in['phone'] ?? '') !== '' ? mb_substr((string) $in['phone'], 0, 20) : null,
            'ein_sealed' => $ein !== '' ? app(BrokerageKeys::class)->seal($ein) : null, 'ein_last4' => $ein !== '' ? substr($ein, -4) : null, 'updated_at' => now(), 'created_at' => $current === null ? now() : null,
        ], fn ($v): bool => $v !== null));
        app(AuditLogger::class)->record('payouts.1099.payer_saved', metadata: ['ein_changed' => $ein !== ''], severity: 'warning');
    }

    public function ready(): bool
    {
        $app = app(AppCatalog::class)->find(self::APP);

        return $app !== null && app(AppRegistry::class)->ready($app);
    }

    /**
     * What stops the year being filed, in plain words (empty when it can go).
     *
     * @return list<string>
     */
    public function problems(int $year): array
    {
        $out = [];
        if (! $this->ready()) {
            $out[] = 'E-filing is not switched on yet (RightAlly HQ sets up the e-file partner). You can still download the IRS-ready file.';
        }
        if ($this->payer() === null) {
            $out[] = 'Add the brokerage\'s payer details: legal name, EIN and address.';
        }
        foreach ($this->tax->forYear($year) as $r) {
            if (! $r['reportable']) {
                continue;
            }
            if ($r['w9'] === null) {
                $out[] = $r['payee'].' has no W-9.';
            } elseif (($r['w9']['match_status'] ?? 'pending') === 'mismatch' || ($r['w9']['match_status'] ?? '') === 'invalid') {
                $out[] = $r['payee'].'\'s name and TIN don\'t match IRS records. Ask for a new W-9.';
            } elseif (self::address((string) DB::connection(Connections::cell())->table('pay_w9s')->where($r['user_id'] !== null ? 'user_id' : 'referral_id', $r['user_id'] ?? $r['referral_id'])
                ->where('status', 'current')->value('address')) === null) {
                $out[] = $r['payee'].'\'s W-9 address needs a street, city, state and ZIP.';
            }
        }
        if (DB::connection(Connections::cell())->table('pay_1099_filings')->where('tenant_id', $this->tenants->id())->where('year', $year)->whereIn('status', ['transmitted', 'accepted'])->exists()) {
            $out[] = $year.' has already been filed. Corrections are made with the partner.';
        }

        return $out;
    }

    /** Files the year's 1099-NECs through the partner and records the submission. */
    public function file(int $year, User $by, bool $mailCopies): string
    {
        $this->guard($by);
        $problems = $this->problems($year);
        if ($problems !== []) {
            throw ValidationException::withMessages(['efile' => $problems[0]]);
        }
        $payerRow = DB::connection(Connections::cell())->table('pay_1099_payer')->where('tenant_id', $this->tenants->id())->first() ?? abort(422);
        $records = [];
        $total = 0;
        $seq = 1;
        foreach ($this->tax->forYear($year) as $r) {
            if (! $r['reportable']) {
                continue;
            }
            $w = DB::connection(Connections::cell())->table('pay_w9s')->where($r['user_id'] !== null ? 'user_id' : 'referral_id', $r['user_id'] ?? $r['referral_id'])->where('status', 'current')->first();
            $addr = $w !== null ? self::address((string) $w->address) : null;
            if ($w === null || $addr === null) {
                continue;
            }
            $name = trim((string) $w->legal_name);
            $isPerson = $w->tin_type === 'ssn';
            $records[] = ['SequenceId' => (string) $seq++, 'Recipient' => array_filter(['TINType' => $isPerson ? 'SSN' : 'EIN', 'TIN' => $this->w9s->tin((int) $w->id),
                'FirstNm' => $isPerson ? Str::beforeLast($name, ' ') : null, 'LastNm' => $isPerson ? Str::afterLast($name, ' ') : null, 'BusinessNm' => $isPerson ? null : $name,
                'IsForeign' => false, 'USAddress' => $addr]), 'NECFormData' => ['B1NEC' => round($r['paid_cents'] / 100, 2), 'B4FedTaxWH' => 0]];
            $total += $r['paid_cents'];
        }
        $payload = ['SubmissionManifest' => ['TaxYear' => (string) $year, 'IRSFilingType' => 'IRIS', 'IsFederalFiling' => true, 'IsStateFiling' => false, 'IsPostal' => $mailCopies,
            'IsOnlineAccess' => false], 'ReturnHeader' => ['Business' => ['BusinessNm' => (string) $payerRow->legal_name, 'IsEIN' => true,
                'EINorSSN' => app(BrokerageKeys::class)->open((string) $payerRow->ein_sealed), 'IsForeign' => false, 'Phone' => $payerRow->phone,
                'USAddress' => ['Address1' => (string) $payerRow->street, 'City' => (string) $payerRow->city, 'State' => (string) $payerRow->state, 'ZipCd' => (string) $payerRow->zip]]],
            'ReturnData' => $records];
        $ulid = (string) Str::ulid();
        try {
            $created = $this->call('post', 'Form1099NEC/Create', $payload);
            $submission = (string) ($created['SubmissionId'] ?? '');
            if ($submission === '') {
                throw new \RuntimeException('The partner did not accept the returns: '.mb_substr((string) json_encode($created['Errors'] ?? $created), 0, 300));
            }
            $this->call('post', 'Form1099NEC/Transmit', ['SubmissionId' => $submission]);
            $status = 'transmitted';
            $message = 'Sent to the IRS through the e-file partner. The IRS usually answers within 2 business days.';
        } catch (Throwable $e) {
            report($e);
            $submission = $submission ?? null;
            $status = 'failed';
            $message = mb_substr('Not filed: '.$e->getMessage(), 0, 500);
        }
        DB::connection(Connections::cell())->table('pay_1099_filings')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'year' => $year, 'status' => $status,
            'submission_id' => $submission !== '' ? $submission : null, 'records' => count($records), 'total_cents' => $total, 'message' => $message, 'filed_by' => $by->id,
            'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('payouts.1099.efiled', metadata: ['year' => $year, 'records' => count($records), 'status' => $status], severity: 'critical');
        if ($status === 'failed') {
            throw ValidationException::withMessages(['efile' => $message]);
        }

        return $ulid;
    }

    /** Asks the partner what the IRS said, and records it. */
    public function refresh(string $ulid, User $by): string
    {
        $this->guard($by);
        $f = DB::connection(Connections::cell())->table('pay_1099_filings')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first() ?? abort(404);
        if ($f->submission_id === null) {
            return (string) $f->status;
        }
        $res = $this->call('get', 'Form1099NEC/Status?SubmissionId='.urlencode((string) $f->submission_id));
        $statuses = array_map(fn ($r): string => strtoupper((string) (is_array($r) ? ($r['FederalReturn']['Status'] ?? $r['Status'] ?? '') : '')), (array) ($res['Form1099Records'] ?? []));
        $status = in_array('REJECTED', $statuses, true) ? 'rejected' : ($statuses !== [] && count(array_filter($statuses, fn ($s) => $s === 'ACCEPTED')) === count($statuses) ? 'accepted' : 'transmitted');
        DB::connection(Connections::cell())->table('pay_1099_filings')->where('id', $f->id)->update(['status' => $status, 'updated_at' => now(),
            'message' => match ($status) {
                'accepted' => 'Accepted by the IRS.', 'rejected' => 'The IRS rejected one or more returns. Fix them with the partner and file a correction.', default => $f->message
            }]);

        return $status;
    }

    /**
     * @return list<array{ulid: string, year: int, status: string, records: int, total_cents: int, message: string|null, created_at: string}>
     */
    public function filings(): array
    {
        return array_values(DB::connection(Connections::cell())->table('pay_1099_filings')->where('tenant_id', $this->tenants->id())->orderByDesc('id')->limit(20)->get()
            ->map(fn ($f): array => ['ulid' => (string) $f->ulid, 'year' => (int) $f->year, 'status' => (string) $f->status, 'records' => (int) $f->records,
                'total_cents' => (int) $f->total_cents, 'message' => $f->message, 'created_at' => substr((string) $f->created_at, 0, 10)])->all());
    }

    /**
     * "12 Oak St, Austin, TX 78704" → the partner's address shape; null when it isn't a full US address.
     *
     * @return array{Address1: string, City: string, State: string, ZipCd: string}|null
     */
    public static function address(string $one): ?array
    {
        if (preg_match('/^\s*(.+?),\s*([^,]+?),?\s+([A-Za-z]{2})\s+(\d{5})(-\d{4})?\s*$/', $one, $m) !== 1) {
            return null;
        }

        return ['Address1' => trim($m[1]), 'City' => trim($m[2]), 'State' => strtoupper($m[3]), 'ZipCd' => $m[4]];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        $s = app(AppRegistry::class)->secrets(app(AppCatalog::class)->find(self::APP) ?? throw new \RuntimeException('E-file is not set up.'));
        $sandbox = in_array(strtolower((string) ($s['environment'] ?? 'sandbox')), ['sandbox', 'test'], true);
        $api = $sandbox ? 'https://testapi.taxbandits.com/v1.7.3/' : 'https://api.taxbandits.com/v1.7.3/';
        $token = Cache::remember('efile1099:token:'.hash('sha256', (string) ($s['client_id'] ?? '')), 3000, function () use ($s, $sandbox): string {
            $now = time();
            $b64 = fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $head = $b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
            $claims = $b64((string) json_encode(['iss' => $s['client_id'] ?? '', 'sub' => $s['client_id'] ?? '', 'aud' => $s['user_token'] ?? '', 'iat' => $now]));
            $jws = $head.'.'.$claims.'.'.$b64(hash_hmac('sha256', $head.'.'.$claims, (string) ($s['client_secret'] ?? ''), true));
            $r = $this->http->timeout(30)->withHeaders(['Authentication' => $jws])
                ->get($sandbox ? 'https://testoauth.expressauth.net/v2/tbsauth' : 'https://oauth.expressauth.net/v2/tbsauth');
            $token = $r->json('AccessToken');
            if ($r->failed() || ! is_string($token) || $token === '') {
                throw new \RuntimeException('The e-file partner refused the keys ('.$r->status().').');
            }

            return $token;
        });
        $req = $this->http->timeout(60)->acceptJson()->withToken($token);
        $r = $method === 'get' ? $req->get($api.$path) : $req->post($api.$path, $body);
        if ($r->serverError()) {
            throw new \RuntimeException('The e-file partner is not answering ('.$r->status().'). Please try again later.');
        }

        return (array) $r->json();
    }

    private function guard(User $by): void
    {
        if (! $by->can('finance.billing.manage')) {
            throw ValidationException::withMessages(['efile' => 'Tax filings are for finance.']);
        }
    }
}
