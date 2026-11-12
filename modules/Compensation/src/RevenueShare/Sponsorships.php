<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Who sponsored whom, with effective dates. A change starts a new period and ends the old one; history
 * is never rewritten. A sponsor can never be someone in the agent's own downline (no cycles).
 */
final class Sponsorships
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function set(User $agent, ?User $sponsor, CarbonImmutable $from, ?User $by = null, ?string $reason = null, ?User $coSponsor = null, ?string $coSharePercent = null): void
    {
        if ($coSponsor !== null) {
            if ($sponsor === null || $coSponsor->id === $sponsor->id || $coSponsor->id === $agent->id) {
                throw ValidationException::withMessages(['co_sponsor' => 'A co-sponsor is a second, different person alongside the sponsor.']);
            }
            if ($coSharePercent === null || ! is_numeric($coSharePercent) || ! BigDecimal::of($coSharePercent)->isPositive() || BigDecimal::of($coSharePercent)->isGreaterThanOrEqualTo(100)) {
                throw ValidationException::withMessages(['co_sponsor' => 'Give the co-sponsor a share between 0 and 100% of level 1.']);
            }
            if (in_array($agent->id, $this->chain($coSponsor->id, $from, RevenueShareEngine::MAX_DEPTH), true)) {
                throw ValidationException::withMessages(['co_sponsor' => "{$coSponsor->name} is in {$agent->name}'s own network."]);
            }
        }
        if ($sponsor !== null && $sponsor->id === $agent->id) {
            throw ValidationException::withMessages(['sponsor' => 'An agent cannot sponsor themselves.']);
        }
        if ($sponsor !== null && in_array($agent->id, $this->chain($sponsor->id, $from, RevenueShareEngine::MAX_DEPTH), true)) {
            throw ValidationException::withMessages(['sponsor' => "{$sponsor->name} is in {$agent->name}'s own network, so cannot be their sponsor."]);
        }

        $db = DB::connection(Connections::cell());
        $db->transaction(function () use ($db, $agent, $sponsor, $from, $by, $reason, $coSponsor, $coSharePercent): void {
            $db->table('rs_sponsorships')->where('agent_user_id', $agent->id)->whereNull('effective_to')->where('effective_from', '<', $from->toDateString())
                ->update(['effective_to' => $from->subDay()->toDateString(), 'updated_at' => now()]);
            $db->table('rs_sponsorships')->where('agent_user_id', $agent->id)->where('effective_from', '>=', $from->toDateString())->delete();
            $db->table('rs_sponsorships')->insert([
                'tenant_id' => $this->tenants->id(), 'agent_user_id' => $agent->id, 'sponsor_user_id' => $sponsor?->id,
                'effective_from' => $from->toDateString(), 'reason' => $reason, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
                'co_sponsor_user_id' => $coSponsor?->id, 'co_share_percent' => $coSponsor !== null ? $coSharePercent : null,
            ]);
        });
        app(AuditLogger::class)->record('compensation.sponsor.changed', $agent,
            metadata: ['sponsor' => $sponsor?->name, 'co_sponsor' => $coSponsor?->name, 'co_share_percent' => $coSharePercent, 'from' => $from->toDateString(), 'reason' => $reason],
            severity: 'warning', label: $agent->name);
    }

    /**
     * The co-sponsor and their share of level 1 on a date, if any.
     *
     * @return array{0: int, 1: string}|null
     */
    public function coSponsorOf(int $agentId, CarbonImmutable $on): ?array
    {
        $row = DB::connection(Connections::cell())->table('rs_sponsorships')->where('agent_user_id', $agentId)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on->toDateString()))
            ->orderByDesc('effective_from')->first(['co_sponsor_user_id', 'co_share_percent']);

        return $row !== null && $row->co_sponsor_user_id !== null ? [(int) $row->co_sponsor_user_id, (string) $row->co_share_percent] : null;
    }

    public function sponsorOf(int $agentId, CarbonImmutable $on): ?int
    {
        $id = DB::connection(Connections::cell())->table('rs_sponsorships')->where('agent_user_id', $agentId)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on->toDateString()))
            ->orderByDesc('effective_from')->value('sponsor_user_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Upline on a date: [level 1 sponsor, level 2, …], stopping at the first missing sponsor.
     *
     * @return list<int>
     */
    public function chain(int $agentId, CarbonImmutable $on, int $depth): array
    {
        $chain = [];
        $current = $agentId;
        for ($i = 0; $i < $depth; $i++) {
            $sponsor = $this->sponsorOf($current, $on);
            if ($sponsor === null || in_array($sponsor, $chain, true) || $sponsor === $agentId) {
                break;
            }
            $chain[] = $sponsor;
            $current = $sponsor;
        }

        return $chain;
    }
}
