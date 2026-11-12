<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Agent credits (signing, production, marketing) that pay the agent's fees on closed deals.
 * Used oldest-expiring first when a deal is finalised; restored if that deal is reversed.
 */
final class Credits
{
    public const KINDS = ['signing', 'production', 'marketing', 'other'];

    public function grant(int $agentUserId, string $kind, string $name, int $amountCents, ?CarbonImmutable $expiresOn, User $by): int
    {
        if (! in_array($kind, self::KINDS, true) || $amountCents <= 0 || trim($name) === '') {
            throw ValidationException::withMessages(['credit' => 'Give the credit a kind, a name and an amount.']);
        }
        $id = DB::connection(Connections::cell())->table('comp_credits')->insertGetId([
            'ulid' => (string) Str::ulid(), 'tenant_id' => app(TenantContext::class)->id(), 'agent_user_id' => $agentUserId, 'kind' => $kind,
            'name' => mb_substr(trim($name), 0, 80), 'amount_cents' => $amountCents, 'remaining_cents' => $amountCents,
            'expires_on' => $expiresOn?->toDateString(), 'granted_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AuditLogger::class)->record('compensation.credit.granted', User::query()->find($agentUserId),
            metadata: ['kind' => $kind, 'name' => $name, 'amount_cents' => $amountCents, 'expires_on' => $expiresOn?->toDateString()], severity: 'notice');

        return $id;
    }

    public function available(int $agentUserId, CarbonImmutable $on): int
    {
        return (int) $this->usable($agentUserId, $on)->sum('remaining_cents');
    }

    public function consume(int $agentUserId, int $cents, int $calculationId, CarbonImmutable $on): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($this->usable($agentUserId, $on, lock: true) as $credit) {
            if ($cents <= 0) {
                break;
            }
            /** @var array{id: int, tenant_id: int, remaining_cents: int} $row */
            $row = (array) $credit;
            $use = min($cents, (int) $row['remaining_cents']);
            $db->table('comp_credits')->where('id', $row['id'])->update(['remaining_cents' => (int) $row['remaining_cents'] - $use, 'updated_at' => now()]);
            $db->table('comp_credit_uses')->insert(['tenant_id' => $row['tenant_id'], 'credit_id' => $row['id'], 'calculation_id' => $calculationId, 'cents' => $use, 'created_at' => now()]);
            $cents -= $use;
        }
    }

    public function restore(int $calculationId): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($db->table('comp_credit_uses')->where('calculation_id', $calculationId)->where('cents', '>', 0)->get() as $use) {
            $db->table('comp_credits')->where('id', $use->credit_id)->increment('remaining_cents', (int) $use->cents);
            $db->table('comp_credit_uses')->insert(['tenant_id' => $use->tenant_id, 'credit_id' => $use->credit_id, 'calculation_id' => $calculationId, 'cents' => -(int) $use->cents, 'created_at' => now()]);
        }
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function usable(int $agentUserId, CarbonImmutable $on, bool $lock = false): Collection
    {
        $q = DB::connection(Connections::cell())->table('comp_credits')->where('tenant_id', app(TenantContext::class)->id())
            ->where('agent_user_id', $agentUserId)->where('remaining_cents', '>', 0)
            ->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', $on->toDateString()))
            ->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('id');

        return ($lock ? $q->lockForUpdate() : $q)->get();
    }
}
