<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Retention with two bounds (specs/core/04): events are kept at least the brokerage's period (7 years
 * minimum) and removed after it, unless a legal hold covers them. Only the oldest unbroken run is removed,
 * and a "purged up to here" event records the last removed link so the rest stays verifiable.
 */
final class AuditRetention
{
    public const MIN_YEARS = 7;

    public const MAX_YEARS = 25;

    public function __construct(private readonly TenantContext $tenants, private readonly AuditLogger $logger) {}

    public function purge(): int
    {
        $tenantId = $this->tenants->id();
        $years = max(self::MIN_YEARS, (int) $this->tenants->current()->getAttribute('audit_retention_years'));
        $cutoff = CarbonImmutable::now()->subYears($years);
        $holds = LegalHold::query()->whereNull('released_at')->get();
        if ($holds->contains(fn (LegalHold $h): bool => $h->subject_id === null && $h->from_date === null && $h->to_date === null)) {
            return 0; // a brokerage-wide hold pauses all deletion
        }

        $db = DB::connection(Connections::cell());
        $upTo = null;
        $anchor = null;
        foreach ($db->table('audit_events')->where('tenant_id', $tenantId)->orderBy('chain_seq')->lazy(500) as $row) {
            $at = CarbonImmutable::parse((string) $row->occurred_at);
            $held = $holds->contains(fn (LegalHold $h): bool => ($h->subject_id !== null && $h->subject_id === $row->subject_id)
                || ($h->subject_id === null && ($h->from_date === null || $at->gte($h->from_date)) && ($h->to_date === null || $at->lte($h->to_date->endOfDay()))));
            if ($at->gte($cutoff) || $held || $row->action === 'core.audit.retention_purged') {
                break;
            }
            $upTo = (int) $row->chain_seq;
            $anchor = (string) $row->hash;
        }

        if ($upTo === null) {
            return 0;
        }

        $removed = DB::connection(Connections::auditMaintenance())->table('audit_events')->where('tenant_id', $tenantId)->where('chain_seq', '<=', $upTo)->delete();
        $this->logger->record('core.audit.retention_purged', metadata: [
            'up_to_seq' => $upTo, 'anchor_hash' => $anchor, 'count' => $removed, 'older_than' => $cutoff->toDateString(), 'retention_years' => $years,
        ], severity: 'notice', label: "{$removed} audit event(s) older than {$years} years");

        return $removed;
    }
}
