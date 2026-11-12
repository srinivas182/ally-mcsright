<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/**
 * Broker review of checklist items: approve, request a fix (from a reasons library that learns), or
 * waive with a reason; each submitted item has an SLA from the brokerage's setting (default 24 hours).
 */
final class Reviews
{
    public function approve(Item $item, User $by): void
    {
        $this->guard($item, ['submitted', 'needs_fix']);
        $item->update(['status' => 'approved', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'fix_reason' => null]);
        $this->log($item, 'approved');
    }

    public function requestFix(Item $item, string $reason, User $by): void
    {
        $this->guard($item, ['submitted', 'approved']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say exactly what to fix.']);
        }
        $item->update(['status' => 'needs_fix', 'fix_reason' => mb_substr($reason, 0, 300), 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $db = DB::connection(Connections::cell());
        $existing = $db->table('tx_fix_reasons')->where('text', $reason)->first();
        $existing !== null
            ? $db->table('tx_fix_reasons')->where('id', $existing->id)->increment('used')
            : $db->table('tx_fix_reasons')->insert(['tenant_id' => $item->getAttribute('tenant_id'), 'text' => mb_substr($reason, 0, 300), 'used' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->log($item, 'fix requested', ['reason' => $reason]);
    }

    public function waive(Item $item, string $reason, User $by): void
    {
        $this->guard($item, ['missing', 'submitted', 'needs_fix']);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say why this item is not needed.']);
        }
        $item->update(['status' => 'waived', 'fix_reason' => trim($reason), 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $this->log($item, 'waived', ['reason' => $reason]);
    }

    public function slaHours(): int
    {
        return (int) (DB::connection(Connections::cell())->table('tx_settings')->value('review_sla_hours') ?? 24);
    }

    public function overdue(Item $item): bool
    {
        return $item->status === 'submitted' && $item->submitted_at !== null && $item->submitted_at->addHours($this->slaHours())->isPast();
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_fix_reasons')->orderByDesc('used')->limit(30)->pluck('text')->map(fn ($t): string => (string) $t)->all());
    }

    /**
     * @param  list<string>  $allowed
     */
    private function guard(Item $item, array $allowed): void
    {
        $deal = Deal::query()->findOrFail($item->deal_id);
        if ($deal->locked_at !== null || ! in_array($item->status, $allowed, true)) {
            throw ValidationException::withMessages(['item' => 'This item cannot be changed now.']);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(Item $item, string $what, array $extra = []): void
    {
        $deal = Deal::query()->find($item->deal_id);
        app(AuditLogger::class)->record('transactions.item.reviewed', $deal, metadata: ['item' => $item->label, 'result' => $what] + $extra, label: $deal?->street);
    }
}
