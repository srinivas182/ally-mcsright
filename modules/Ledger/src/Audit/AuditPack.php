<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Models\LedgerPeriod;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One-click audit pack (FH2, specs/finance/23): everything a CPA or state regulator asks for, in one
 * password-protected (AES-256) ZIP with a manifest of SHA-256 fingerprints. Packs are kept for 7 days.
 */
final class AuditPack
{
    public const KEEP_DAYS = 7;

    /** @var array<string, class-string<AuditPackPart>> */
    private array $parts = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  class-string<AuditPackPart>  $part */
    public function register(string $part): void
    {
        $this->parts[$part] = $part;
    }

    public function request(CarbonImmutable $from, CarbonImmutable $to, User $by): string
    {
        if ($to->lessThan($from) || $from->diffInMonths($to) > 24) {
            throw ValidationException::withMessages(['from' => 'Choose up to two years, with the start before the end.']);
        }
        $db = DB::connection(Connections::cell())->table('ledger_audit_packs');
        if ($db->clone()->where('tenant_id', $this->tenants->id())->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['from' => 'A pack is already being prepared. It appears here when it is ready.']);
        }
        $ulid = (string) Str::ulid();
        $db->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'status' => 'queued', 'requested_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.audit_pack.requested', metadata: ['pack' => $ulid, 'from' => $from->toDateString(), 'to' => $to->toDateString()], severity: 'notice');
        Jobs\BuildAuditPack::dispatch($this->tenants->id(), $ulid);

        return $ulid;
    }

    /** Builds the ZIP (run by the BuildAuditPack job, inside the brokerage's tenant context). */
    public function build(string $ulid): void
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('ledger_audit_packs')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if ($row === null || $row->status !== 'queued') {
            return;
        }
        $db->table('ledger_audit_packs')->where('id', $row->id)->update(['status' => 'running', 'updated_at' => now()]);
        $tmp = null;
        try {
            $from = CarbonImmutable::parse((string) $row->from_date);
            $to = CarbonImmutable::parse((string) $row->to_date);
            $files = [];
            foreach ($this->parts as $part) {
                $files += app($part)->files($from, $to);
            }
            ksort($files);
            $files = ['README.txt' => $this->readme($this->tenants->current(), $from, $to, array_keys($files))] + $files;
            $manifest = [];
            foreach ($files as $name => $content) {
                $manifest[$name] = hash('sha256', $content);
            }
            $files['manifest.json'] = (string) json_encode(['brokerage' => $this->tenants->current()->name, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'made_at' => now()->toIso8601String(), 'sha256' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $password = Str::password(24, symbols: false);
            $tmp = tempnam(sys_get_temp_dir(), 'ap').'.zip';
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the audit pack.');
            }
            $zip->setPassword($password);
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
            }
            $zip->close();
            $path = 'audit-packs/'.$this->tenants->id().'/'.$ulid.'.zip';
            $bytes = (string) file_get_contents($tmp);
            Storage::disk('local')->put($path, $bytes);
            $db->table('ledger_audit_packs')->where('id', $row->id)->update(['status' => 'ready', 'path' => $path, 'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'password' => Crypt::encryptString($password), 'files' => count($files), 'ready_at' => now(),
                'expires_at' => now()->addDays(self::KEEP_DAYS), 'updated_at' => now()]);
            app(AuditLogger::class)->record('finance.audit_pack.ready', metadata: ['pack' => $ulid, 'files' => count($files)], severity: 'notice');
        } catch (Throwable $e) {
            report($e);
            $db->table('ledger_audit_packs')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return list<array{ulid: string, from: string, to: string, status: string, files: int|null, bytes: int|null, ready_at: string|null, expires_at: string|null, requested_by: string}>
     */
    public function list(): array
    {
        $rows = DB::connection(Connections::cell())->table('ledger_audit_packs')->where('tenant_id', $this->tenants->id())->orderByDesc('id')->limit(20)->get();
        $people = User::query()->whereIn('id', $rows->pluck('requested_by')->unique()->all())->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);

        return array_values($rows->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'from' => (string) $r->from_date, 'to' => (string) $r->to_date,
            'status' => $r->status === 'ready' && $r->expires_at !== null && CarbonImmutable::parse((string) $r->expires_at)->isPast() ? 'expired' : (string) $r->status,
            'files' => $r->files !== null ? (int) $r->files : null, 'bytes' => $r->bytes !== null ? (int) $r->bytes : null,
            'ready_at' => $r->ready_at !== null ? (string) $r->ready_at : null, 'expires_at' => $r->expires_at !== null ? (string) $r->expires_at : null,
            'requested_by' => (string) ($people[$r->requested_by] ?? 'Someone')])->all());
    }

    public function ready(string $ulid): \stdClass
    {
        $r = DB::connection(Connections::cell())->table('ledger_audit_packs')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->where('status', 'ready')
            ->where('expires_at', '>', now())->first();
        abort_if($r === null || $r->path === null || $r->password === null, 404);

        return $r;
    }

    /** Deletes the current brokerage's expired packs (daily). */
    public function expire(): int
    {
        $n = 0;
        foreach (DB::connection(Connections::cell())->table('ledger_audit_packs')->where('tenant_id', $this->tenants->id())->where('status', 'ready')->where('expires_at', '<=', now())->get() as $r) {
            if ($r->path !== null) {
                Storage::disk('local')->delete((string) $r->path);
            }
            DB::connection(Connections::cell())->table('ledger_audit_packs')->where('id', $r->id)->update(['status' => 'expired', 'path' => null, 'password' => null, 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** @param  list<string>  $names */
    private function readme(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, array $names): string
    {
        $open = LedgerPeriod::query()->where('status', 'open')->whereDate('starts_on', '>=', $from->startOfMonth()->toDateString())
            ->whereDate('starts_on', '<=', $to->toDateString())->orderBy('starts_on')->get()->map(fn (LedgerPeriod $p): string => $p->starts_on->format('F Y'))->all();
        $lines = [
            "Audit pack for {$tenant->name}",
            "Covers {$from->toFormattedDateString()} to {$to->toFormattedDateString()}. Made by RightAlly on ".now()->toDayDateTimeString().'.',
            '',
            $open === [] ? 'Every month in this range is closed.' : 'Months still open (their figures can still change): '.implode(', ', $open).'.',
            '',
            'What is inside:',
            '- statements/: profit and loss, balance sheet and trial balance exactly as they were when each month (and year) closed, each with its SHA-256 fingerprint.',
            '- month_end.csv: when each month closed, who closed it, and any note written when closing with items still to check.',
            '- general_ledger.csv: every entry in the books, line by line, with who posted it and when. Manual adjustments are marked.',
            '- adjustments.csv: every manual adjustment, who entered it, who approved or declined it, and whether the only approver approved their own entry.',
            '- bank_reconciliations.csv and trust_reconciliations.csv: monthly reconciliations with who prepared and who approved them.',
            '- payout_runs.csv and 1099_tieout.csv: what was paid to agents and referral payees; 1099 totals come from the same payout lines as the books.',
            '- activity_log.csv and activity_log_check.txt: the finance part of the tamper-evident activity log, and the result of re-checking its hash chain.',
            '- manifest.json: the SHA-256 fingerprint of every file, so you can confirm nothing was changed after the pack was made.',
            '',
            'Tax ID numbers and bank account numbers are never included.',
            '',
            'Files: '.implode(', ', $names),
        ];

        return implode("\n", $lines)."\n";
    }
}
