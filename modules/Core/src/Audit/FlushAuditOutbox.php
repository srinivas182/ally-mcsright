<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * Seals outbox events into audit_events in order per brokerage: gapless chain_seq and the hash chain,
 * under a per-brokerage lock. Safe to run many times (the scheduler also runs it every minute).
 */
final class FlushAuditOutbox implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        $db = DB::connection(Connections::cell());

        foreach ($db->table('audit_outbox')->distinct()->pluck('tenant_id') as $tenantId) {
            Cache::lock('audit-chain:'.$tenantId, 30)->block(10, function () use ($db, $tenantId): void {
                $db->transaction(function () use ($db, $tenantId): void {
                    $head = $db->table('audit_heads')->where('tenant_id', $tenantId)->lockForUpdate()->first();
                    $seq = (int) ($head->last_seq ?? 0);
                    $prev = (string) ($head->last_hash ?? AuditChain::GENESIS);

                    foreach ($db->table('audit_outbox')->where('tenant_id', $tenantId)->orderBy('id')->limit(1000)->get() as $row) {
                        /** @var array<string, mixed> $event */
                        $event = json_decode((string) $row->payload, true);
                        $event['tenant_id'] = (int) $tenantId;
                        $event['chain_seq'] = ++$seq;
                        $hash = AuditChain::hash($prev, $event);

                        $db->table('audit_events')->insert([
                            ...array_intersect_key($event, array_flip(AuditChain::HASHED)),
                            'changes' => isset($event['changes']) ? json_encode($event['changes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                            'metadata' => isset($event['metadata']) ? json_encode($event['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                            'prev_hash' => $prev,
                            'hash' => $hash,
                        ]);
                        $db->table('audit_outbox')->where('id', $row->id)->delete();
                        $prev = $hash;
                    }

                    $db->table('audit_heads')->updateOrInsert(['tenant_id' => $tenantId], ['last_seq' => $seq, 'last_hash' => $prev]);
                });
            });
        }
    }
}
