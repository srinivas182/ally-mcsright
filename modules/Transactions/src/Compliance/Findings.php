<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Working with findings: the deal's list and broker-ready score, dismissing a finding with a reason, submitting
 * a clean file to the broker, and how often each check turns out to be right (accuracy).
 */
final class Findings
{
    /** A check is flagged for review once dismissed this many times with an accuracy under half. */
    public const REVIEW_AFTER = 5;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{score: ?int, scanned_at: ?string, submitted_at: ?string, can_submit: bool, open: list<array<string, mixed>>, dismissed: list<array<string, mixed>>}
     */
    public static function forDeal(Deal $deal): array
    {
        $db = DB::connection(Connections::cell());
        $rows = $db->table('tx_findings')->where('deal_id', $deal->id)->whereIn('status', ['open', 'dismissed'])->orderByRaw("CASE severity WHEN 'blocker' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('id')->get()->map(fn ($f): array => ['id' => (int) $f->id, 'code' => (string) $f->code, 'severity' => (string) $f->severity, 'message' => (string) $f->message,
                'evidence' => json_decode((string) ($f->evidence ?? 'null'), true), 'fix' => json_decode((string) ($f->fix ?? 'null'), true), 'status' => (string) $f->status,
                'dismiss_reason' => $f->dismiss_reason]);
        /** @var list<array<string, mixed>> $open */
        $open = $rows->where('status', 'open')->values()->all();
        /** @var list<array<string, mixed>> $dismissed */
        $dismissed = $rows->where('status', 'dismissed')->values()->all();
        $row = $db->table('tx_deals')->where('id', $deal->id)->first(['compliance_score', 'compliance_scanned_at', 'file_submitted_at']);

        return ['score' => $row?->compliance_score !== null ? (int) $row->compliance_score : null,
            'scanned_at' => $row?->compliance_scanned_at !== null ? (string) $row->compliance_scanned_at : null,
            'submitted_at' => $row?->file_submitted_at !== null ? (string) $row->file_submitted_at : null,
            'can_submit' => collect($open)->whereIn('severity', ['blocker', 'warning'])->isEmpty(), 'open' => $open, 'dismissed' => $dismissed];
    }

    /** Reviewers may dismiss anything; agents may dismiss information only. A reason is always needed. */
    public function dismiss(Deal $deal, int $findingId, string $reason, User $by, bool $reviewer): void
    {
        $db = DB::connection(Connections::cell());
        $f = $db->table('tx_findings')->where('deal_id', $deal->id)->where('id', $findingId)->where('status', 'open')->first()
            ?? throw ValidationException::withMessages(['finding' => 'Finding not found.']);
        if (! $reviewer && $f->severity !== 'info') {
            throw ValidationException::withMessages(['finding' => 'Only the broker or a reviewer can dismiss blockers and warnings.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say why this is not a problem.']);
        }
        $db->table('tx_findings')->where('id', $f->id)->update(['status' => 'dismissed', 'dismissed_by' => $by->id, 'dismiss_reason' => mb_substr(trim($reason), 0, 300)]);
        app(AuditLogger::class)->record('transactions.compliance.dismissed', $deal, metadata: ['check' => $f->code, 'finding' => $f->message, 'reason' => trim($reason)],
            severity: $f->severity === 'blocker' ? 'critical' : 'notice', label: $deal->street);
        app(ComplianceScan::class)->run($deal);
    }

    /** One click at 100%: the file goes to the broker's review queue. */
    public function submit(Deal $deal, User $by): void
    {
        $result = app(ComplianceScan::class)->run($deal);
        if ($result['blockers'] + $result['warnings'] > 0) {
            throw ValidationException::withMessages(['file' => 'Fix or explain every blocker and warning first (score '.$result['score'].'%).']);
        }
        DB::connection(Connections::cell())->table('tx_deals')->where('id', $deal->id)->update(['file_submitted_at' => now(), 'file_submitted_by' => $by->id]);
        app(AuditLogger::class)->record('transactions.file.submitted', $deal, metadata: ['score' => $result['score']], severity: 'notice', label: $deal->street);
    }

    /**
     * Per check: findings fixed (the check was right) and dismissed (it was not), accuracy, and whether to review it.
     *
     * @return array<string, array{fixed: int, dismissed: int, open: int, accuracy: ?int, review: bool}>
     */
    public static function accuracy(): array
    {
        $out = [];
        foreach (DB::connection(Connections::cell())->table('tx_findings')->selectRaw('code, status, count(*) as n')->groupBy('code', 'status')->get() as $r) {
            $out[$r->code] ??= ['fixed' => 0, 'dismissed' => 0, 'open' => 0, 'accuracy' => null, 'review' => false];
            $key = match ($r->status) {
                'resolved' => 'fixed',
                'dismissed' => 'dismissed',
                default => 'open',
            };
            $out[$r->code][$key] += (int) $r->n;
        }
        foreach ($out as $code => $s) {
            $judged = $s['fixed'] + $s['dismissed'];
            $out[$code]['accuracy'] = $judged > 0 ? (int) round(100 * $s['fixed'] / $judged) : null;
            $out[$code]['review'] = $s['dismissed'] >= self::REVIEW_AFTER && $s['fixed'] < $s['dismissed'];
        }

        return $out;
    }

    public function saveCheck(string $code, bool $enabled, ?string $severity): void
    {
        if (! isset(Checks::CATALOG[$code]) || ($severity !== null && ! in_array($severity, Checks::SEVERITIES, true))) {
            throw ValidationException::withMessages(['check' => 'Unknown check or severity.']);
        }
        DB::connection(Connections::cell())->table('tx_compliance_checks')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'code' => $code],
            ['enabled' => $enabled, 'severity' => $severity]);
        app(AuditLogger::class)->record('transactions.compliance.check', metadata: ['check' => $code, 'enabled' => $enabled, 'severity' => $severity], severity: 'notice');
    }
}
