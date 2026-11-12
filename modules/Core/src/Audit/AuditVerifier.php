<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * Recomputes a brokerage's chain. After retention purges, verification starts from the first remaining
 * event, whose link must equal the anchor recorded by the purge.
 */
final class AuditVerifier
{
    /**
     * @return array{ok: bool, checked: int, from: int, message: string}
     */
    public function verify(int $tenantId, ?int $from = null, ?int $to = null): array
    {
        $db = DB::connection(Connections::cell());
        $first = (int) ($db->table('audit_events')->where('tenant_id', $tenantId)->min('chain_seq') ?? 1);
        $from = max($first, $from ?? $first);

        if ($from === 1) {
            $prev = AuditChain::GENESIS;
        } elseif ($from === $first) {
            $prev = (string) $db->table('audit_events')->where('tenant_id', $tenantId)->where('chain_seq', $first)->value('prev_hash');
            $anchor = $this->purgeAnchor($tenantId, $first - 1);
            if ($anchor === null || ! hash_equals($anchor, $prev)) {
                return ['ok' => false, 'checked' => 0, 'from' => $from, 'message' => "Broken at sequence {$first}: earlier events are missing without a retention record."];
            }
        } else {
            $prev = (string) $db->table('audit_events')->where('tenant_id', $tenantId)->where('chain_seq', $from - 1)->value('hash');
        }

        $expected = $from;
        $checked = 0;
        $query = $db->table('audit_events')->where('tenant_id', $tenantId)->where('chain_seq', '>=', $from)->orderBy('chain_seq');
        if ($to !== null) {
            $query->where('chain_seq', '<=', $to);
        }

        foreach ($query->lazy(500) as $row) {
            $event = (array) $row;
            if ((int) $event['chain_seq'] !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'from' => $from, 'message' => "Broken at sequence {$expected}: event missing."];
            }
            if ($event['prev_hash'] !== $prev || AuditChain::hash($prev, $event) !== $event['hash']) {
                return ['ok' => false, 'checked' => $checked, 'from' => $from, 'message' => "Broken at sequence {$expected}: event {$event['ulid']} was altered."];
            }
            $prev = (string) $event['hash'];
            $expected++;
            $checked++;
        }

        $note = $from > 1 && $from === $first ? " (from #{$from}; earlier events removed by retention)" : '';

        return ['ok' => true, 'checked' => $checked, 'from' => $from, 'message' => "Audit log intact: {$checked} event(s) verified{$note}."];
    }

    private function purgeAnchor(int $tenantId, int $upToSeq): ?string
    {
        $db = DB::connection(Connections::cell());
        foreach ($db->table('audit_events')->where('tenant_id', $tenantId)->where('action', 'core.audit.retention_purged')->orderByDesc('chain_seq')->get(['metadata']) as $row) {
            $meta = json_decode((string) $row->metadata, true);
            if (is_array($meta) && (int) ($meta['up_to_seq'] ?? 0) === $upToSeq) {
                return (string) ($meta['anchor_hash'] ?? '');
            }
        }

        return null;
    }
}
