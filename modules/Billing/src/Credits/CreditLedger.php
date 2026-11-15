<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\Models\CreditAccount;
use Modules\Billing\Credits\Models\CreditLedgerEntry;
use Modules\Billing\Jobs\TopUpCredits;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Each brokerage's RightAlly Credits (specs/billing/01): a pool for the brokerage and an account per person, with an
 * append-only ledger. Included credits (from the package) are used first and expire at the end of the month;
 * purchased and allotted credits carry over. Every charge is idempotent and can be refunded once.
 *
 * Who pays: automations always use the pool; owners and staff use the pool; agents use their own credits first, then
 * the pool if the broker allows (up to an optional monthly cap).
 */
final class CreditLedger
{
    public const ALERT_THRESHOLDS = [80, 100];

    public function __construct(private readonly TenantContext $tenants, private readonly RateCard $rates) {}

    public static function period(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->format('Y-m');
    }

    /**
     * Charges an action once per idempotency key. With $overdraw the charge always goes through (the balance may go
     * below zero): for messages that must not be held back, such as security codes and deadline alerts.
     *
     * @throws InsufficientCredits
     */
    public function charge(string $actionKey, ?int $userId, string $idempotencyKey, int $quantity = 1, bool $overdraw = false): int
    {
        $rate = $this->rates->rate($actionKey);
        $credits = $rate['credits'] * max(1, $quantity);
        if ($credits <= 0 || $this->entry($idempotencyKey) !== null) {
            return 0;
        }
        $user = $userId !== null ? User::query()->find($userId) : null;
        $automation = $rate['automation'] || $user === null;
        $usePoolFirst = $automation || in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);

        $enforce = $this->rates->enforced() && ! $overdraw;

        try {
            DB::connection(Connections::cell())->transaction(function () use ($credits, $actionKey, $userId, $idempotencyKey, $automation, $usePoolFirst, $user, $enforce): void {
                $pool = $this->lock(null);
                if ($usePoolFirst) {
                    if ($enforce && $pool->balance < $credits) {
                        throw new InsufficientCredits($automation ? 'Your brokerage is out of RightAlly Credits for automations. Top up or allot more.' : 'Your brokerage is out of RightAlly Credits. Buy more to keep going.');
                    }
                    $this->consume($pool, $credits, $actionKey, $userId, $automation, $idempotencyKey);

                    return;
                }
                $mine = $this->lock((int) $userId);
                if ($mine->balance >= $credits) {
                    $this->consume($mine, $credits, $actionKey, $userId, false, $idempotencyKey);

                    return;
                }
                $policy = $this->policy();
                $usedFromPool = $this->usedFromPool((int) $userId);
                $blocked = ! $policy['agents_use_pool'] || $pool->balance < $credits || ($policy['agent_monthly_cap'] !== null && $usedFromPool + $credits > $policy['agent_monthly_cap']);
                if ($blocked && ! $enforce) {
                    // Not enforced yet: record against whichever balance would have paid (it may go below zero).
                    $this->consume($mine->balance > 0 || ! $policy['agents_use_pool'] ? $mine : $pool, $credits, $actionKey, $userId, false, $idempotencyKey);

                    return;
                }
                if ($blocked) {
                    throw new InsufficientCredits(($user->first_name !== '' ? $user->first_name.', you' : 'You').' are out of RightAlly Credits. Ask your broker to allot more, or buy your own.');
                }
                $this->consume($pool, $credits, $actionKey, $userId, false, $idempotencyKey);
            }, 3);
        } catch (QueryException $e) {
            if ($this->entry($idempotencyKey) !== null) {
                return 0; // the same request charged at the same moment
            }
            throw $e;
        }
        $this->alertIfLow();
        $this->topUpIfLow($user !== null && ! $usePoolFirst ? $user->id : null);

        return $credits;
    }

    /** Returns the credits for a request that produced nothing usable (once). */
    public function refund(string $idempotencyKey, string $reason): void
    {
        $entry = $this->entry($idempotencyKey);
        if ($entry === null || $this->entry('refund:'.$idempotencyKey) !== null) {
            return;
        }
        DB::connection(Connections::cell())->transaction(function () use ($entry, $idempotencyKey, $reason): void {
            $account = CreditAccount::query()->whereKey($entry->account_id)->lockForUpdate()->first();
            if ($account === null) {
                return;
            }
            $this->write($account->id, 'refund', -$entry->amount, -$entry->included_amount, $entry->action_key, $entry->user_id,
                $entry->automation, 'refund:'.$idempotencyKey, mb_substr($reason, 0, 255), null);
        });
    }

    /**
     * Gives back part of a charge (once per charge): e.g. a Boost that spent less than the credits reserved for it.
     * Never more than was charged; the credits go back to the account that paid.
     */
    public function refundPart(string $idempotencyKey, int $credits, string $reason): int
    {
        $entry = $this->entry($idempotencyKey);
        if ($entry === null || $credits <= 0 || $this->entry('refund:'.$idempotencyKey) !== null) {
            return 0;
        }
        $credits = min($credits, -$entry->amount);
        DB::connection(Connections::cell())->transaction(function () use ($entry, $idempotencyKey, $credits, $reason): void {
            $account = CreditAccount::query()->whereKey($entry->account_id)->lockForUpdate()->first();
            if ($account !== null) {
                $this->write($account->id, 'refund', $credits, 0, $entry->action_key, $entry->user_id, $entry->automation, 'refund:'.$idempotencyKey, mb_substr($reason, 0, 255), null);
            }
        });

        return $credits;
    }

    /** The month's included credits: expires what is left from earlier months, then grants the package's credits (once per month). */
    public function grantIncluded(int $credits, ?CarbonImmutable $at = null): bool
    {
        $period = self::period($at);
        $key = 'grant:'.$period;
        if ($this->entry($key) !== null) {
            return false;
        }
        DB::connection(Connections::cell())->transaction(function () use ($credits, $period, $key): void {
            $pool = $this->lock(null);
            if ($pool->included_balance > 0) {
                $this->write($pool->id, 'expire', -$pool->included_balance, -$pool->included_balance, null, null, false, 'expire:'.$period, 'Included credits from last month', null);
            }
            if ($credits > 0) {
                $this->write($pool->id, 'grant_included', $credits, $credits, null, null, false, $key, 'Included in your package', null);
            } else {
                $this->write($pool->id, 'grant_included', 0, 0, null, null, false, $key, 'No credits included', null);
            }
        });

        return true;
    }

    /** Credits added outside the package (purchases in 18B; HQ adjustments now). */
    public function add(?int $userId, int $credits, string $type, string $note, ?User $by, ?string $idempotencyKey = null): void
    {
        if (! in_array($type, ['purchase', 'adjust'], true) || $credits === 0) {
            throw ValidationException::withMessages(['credits' => 'Enter a number of credits.']);
        }
        DB::connection(Connections::cell())->transaction(function () use ($userId, $credits, $type, $note, $by, $idempotencyKey): void {
            $account = $this->lock($userId);
            if ($account->balance + $credits < 0) {
                throw ValidationException::withMessages(['credits' => 'That would take the balance below zero.']);
            }
            $this->write($account->id, $type, $credits, 0, null, $userId, false, $idempotencyKey ?? $type.':'.Str::ulid(), mb_substr($note, 0, 255), $by?->id);
        });
        app(AuditLogger::class)->record('billing.credits.'.$type, metadata: ['user' => $userId, 'credits' => $credits, 'note' => $note], severity: 'notice');
    }

    /** Moves credits from the brokerage pool to an agent (or back, with a negative number). */
    public function allot(User $agent, int $credits, User $by): void
    {
        if ($credits === 0) {
            throw ValidationException::withMessages(['credits' => 'Enter how many credits to give.']);
        }
        $key = 'allot:'.Str::ulid();
        DB::connection(Connections::cell())->transaction(function () use ($agent, $credits, $by, $key): void {
            $pool = $this->lock(null);
            $mine = $this->lock($agent->id);
            if ($credits > 0 && $pool->balance < $credits) {
                throw ValidationException::withMessages(['credits' => 'The brokerage pool has only '.number_format($pool->balance).' credits.']);
            }
            if ($credits < 0 && $mine->balance < -$credits) {
                throw ValidationException::withMessages(['credits' => $agent->first_name.' has only '.number_format($mine->balance).' credits to take back.']);
            }
            // Included credits stay in the pool; what moves is carried over (it does not expire with the month).
            $fromIncluded = $credits > 0 ? min($pool->included_balance, $credits) : 0;
            $this->write($pool->id, 'allocate_out', -$credits, -$fromIncluded, null, $agent->id, false, $key.':out', 'To '.$agent->name, $by->id);
            $this->drawLots($pool, $credits - $fromIncluded);
            $this->write($mine->id, 'allocate_in', $credits, 0, null, $agent->id, false, $key.':in', 'From the brokerage', $by->id);
        });
        app(AuditLogger::class)->record('billing.credits.allotted', metadata: ['agent' => $agent->id, 'credits' => $credits], severity: 'notice');
    }

    /**
     * What an action will cost this person before they run it (for the consent prompt).
     *
     * @return array{action: string, label: string, credits: int, available: int, payer: string, enough: bool}
     */
    public function quote(string $actionKey, ?User $user): array
    {
        $rate = $this->rates->rate($actionKey);
        $pool = $this->poolSummary()['available'];
        $open = ! $this->rates->enforced(); // not enforced yet: never blocks
        $manager = $user === null || in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
        if ($manager || $rate['automation']) {
            return ['action' => $actionKey, 'label' => $rate['label'], 'credits' => $rate['credits'], 'available' => $pool, 'payer' => 'brokerage', 'enough' => $open || $pool >= $rate['credits']];
        }
        $mine = $this->userSummary($user->id);
        $policy = $this->policy();
        $poolLeft = $policy['agents_use_pool'] ? ($policy['agent_monthly_cap'] !== null ? max(0, min($pool, $policy['agent_monthly_cap'] - $mine['from_pool'])) : $pool) : 0;
        $available = $mine['available'] + $poolLeft;

        return ['action' => $actionKey, 'label' => $rate['label'], 'credits' => $rate['credits'], 'available' => $available,
            'payer' => $mine['available'] >= $rate['credits'] ? 'you' : 'brokerage', 'enough' => $open || $available >= $rate['credits']];
    }

    /**
     * The menu meter: managers see the brokerage pool; agents see what they can spend (their own credits plus the
     * pool share the brokerage allows them) against what they have had this month.
     *
     * @return array{available: int, total: int, used: int, scope: string, period: string, enforced: bool}
     */
    public function meter(User $user): array
    {
        $pool = $this->poolSummary();
        $enforced = $this->rates->enforced();
        if (in_array($user->kind, [UserKind::Owner, UserKind::Staff], true)) {
            return ['available' => max(0, $pool['available']), 'total' => max(0, $pool['total']), 'used' => $pool['used'], 'scope' => 'brokerage', 'period' => $pool['period'], 'enforced' => $enforced];
        }
        $mine = $this->userSummary($user->id);
        $poolLeft = $mine['pool_open'] ? ($mine['cap'] !== null ? max(0, min($pool['available'], $mine['cap'] - $mine['from_pool'])) : $pool['available']) : 0;
        $available = max(0, $mine['available'] + $poolLeft);

        return ['available' => $available, 'total' => $available + $mine['used'], 'used' => $mine['used'], 'scope' => 'you', 'period' => $pool['period'], 'enforced' => $enforced];
    }

    /** Current balance of the pool (null) or a person's account. */
    public function balanceOf(?int $userId): int
    {
        return $this->account($userId)->balance ?? 0;
    }

    /**
     * The pool's month at a glance (for the menu meter and the credits page).
     *
     * @return array{available: int, included: int, used: int, total: int, period: string}
     */
    public function poolSummary(): array
    {
        $pool = $this->account(null);
        $used = $this->usedFrom($pool?->id);

        return ['available' => $pool->balance ?? 0, 'included' => $pool->included_balance ?? 0, 'used' => $used,
            'total' => ($pool->balance ?? 0) + $used, 'period' => self::period()];
    }

    /**
     * One person's credits: their own balance and what they used this month (from their account and the pool).
     *
     * @return array{available: int, used: int, from_pool: int, pool_open: bool, cap: int|null}
     */
    public function userSummary(int $userId): array
    {
        $account = $this->account($userId);
        $policy = $this->policy();
        $db = DB::connection(Connections::cell())->table('credit_ledger');

        return ['available' => $account->balance ?? 0,
            'used' => -(int) (clone $db)->where('tenant_id', $this->tenants->id())->where('period', self::period())->where('user_id', $userId)
                ->whereIn('entry_type', ['consume', 'refund'])->sum('amount'),
            'from_pool' => $this->usedFromPool($userId), 'pool_open' => $policy['agents_use_pool'], 'cap' => $policy['agent_monthly_cap']];
    }

    /**
     * This month's use by person, automation and feature.
     *
     * @return array{people: list<array{user_id: int, credits: int}>, automations: int, features: list<array{action: string, credits: int, uses: int, automation: bool}>}
     */
    public function usage(?string $period = null): array
    {
        $period ??= self::period();
        $rows = CreditLedgerEntry::query()->where('period', $period)->whereIn('entry_type', ['consume', 'refund'])
            ->get(['amount', 'user_id', 'automation', 'action_key', 'entry_type']);
        $people = [];
        $features = [];
        $automations = 0;
        foreach ($rows as $r) {
            $credits = -$r->amount;
            if ($r->automation) {
                $automations += $credits;
            } elseif ($r->user_id !== null) {
                $people[$r->user_id] = ($people[$r->user_id] ?? 0) + $credits;
            }
            $key = (string) $r->action_key;
            $features[$key] ??= ['action' => $key, 'credits' => 0, 'uses' => 0, 'automation' => $r->automation];
            $features[$key]['credits'] += $credits;
            $features[$key]['uses'] += $r->entry_type === 'consume' ? 1 : -1;
        }
        $people = array_filter($people, fn (int $c): bool => $c !== 0);
        $features = array_filter($features, fn (array $f): bool => $f['uses'] !== 0 || $f['credits'] !== 0);
        arsort($people);
        usort($features, fn (array $a, array $b): int => $b['credits'] <=> $a['credits']);

        return ['people' => array_map(fn (int $id, int $c): array => ['user_id' => $id, 'credits' => $c], array_keys($people), array_values($people)),
            'automations' => $automations, 'features' => $features];
    }

    /**
     * @return array{agents_use_pool: bool, agent_monthly_cap: int|null, agents_can_buy: bool}
     */
    public function policy(): array
    {
        $row = DB::connection(Connections::cell())->table('credit_policies')->where('tenant_id', $this->tenants->id())->first();

        return ['agents_use_pool' => (bool) ($row->agents_use_pool ?? true), 'agent_monthly_cap' => isset($row->agent_monthly_cap) ? (int) $row->agent_monthly_cap : null,
            'agents_can_buy' => (bool) ($row->agents_can_buy ?? true)];
    }

    public function savePolicy(bool $agentsUsePool, ?int $cap, User $by, ?bool $agentsCanBuy = null): void
    {
        $values = ['agents_use_pool' => $agentsUsePool, 'agent_monthly_cap' => $cap !== null && $cap > 0 ? $cap : null, 'updated_at' => now(), 'created_at' => now()];
        if ($agentsCanBuy !== null) {
            $values['agents_can_buy'] = $agentsCanBuy;
        }
        DB::connection(Connections::cell())->table('credit_policies')->updateOrInsert(['tenant_id' => $this->tenants->id()], $values);
        app(AuditLogger::class)->record('billing.credits.policy', metadata: ['agents_use_pool' => $agentsUsePool, 'cap' => $cap, 'agents_can_buy' => $agentsCanBuy], severity: 'notice');
    }

    /**
     * Ledger entries for one account (newest first).
     *
     * @return array<int, CreditLedgerEntry>
     */
    public function history(?int $userId, int $limit = 50): array
    {
        $account = $this->account($userId);

        return $account === null ? [] : CreditLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit($limit)->get()->values()->all();
    }

    /** Queues the automatic top-up when the account that paid (and the pool, which agents may fall back on) has one. */
    private function topUpIfLow(?int $userId): void
    {
        $owners = DB::connection(Connections::cell())->table('credit_autopay')->where('tenant_id', $this->tenants->id())->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('user_id')->when($userId !== null, fn ($w) => $w->orWhere('user_id', $userId)))->pluck('user_id');
        foreach ($owners as $owner) {
            TopUpCredits::dispatch($this->tenants->id(), $owner !== null ? (int) $owner : null);
        }
    }

    /** Emails owners once per month when the pool passes 80% used and when it runs out. */
    private function alertIfLow(): void
    {
        $s = $this->poolSummary();
        if ($s['total'] <= 0) {
            return;
        }
        $usedPct = (int) floor(($s['total'] - $s['available']) * 100 / $s['total']);
        $db = DB::connection(Connections::cell())->table('credit_policies');
        $row = $db->where('tenant_id', $this->tenants->id())->first();
        $sent = (array) json_decode((string) ($row->alerts_sent ?? '{}'), true);
        $done = (array) ($sent[$s['period']] ?? []);
        foreach (self::ALERT_THRESHOLDS as $threshold) {
            if ($usedPct >= $threshold && ! in_array($threshold, $done, true)) {
                $done[] = $threshold;
                $sent = [$s['period'] => $done];
                DB::connection(Connections::cell())->table('credit_policies')->updateOrInsert(['tenant_id' => $this->tenants->id()],
                    ['alerts_sent' => json_encode($sent), 'updated_at' => now(), 'created_at' => now()]);
                $owners = User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive());
                Notification::send($owners, new CreditsLow($threshold, $s['available'], $s['total']));
            }
        }
    }

    private function consume(CreditAccount $account, int $credits, string $actionKey, ?int $userId, bool $automation, string $key): void
    {
        $fromIncluded = max(0, min($account->included_balance, $credits));
        $this->write($account->id, 'consume', -$credits, -$fromIncluded, $actionKey, $userId, $automation, $key, null, $userId);
        $this->drawLots($account, $credits - $fromIncluded);
    }

    /**
     * Uses bought credits oldest-expiry first (after the month's included credits), so the ones that would expire
     * soonest are spent first. Credits added any other way (allotted, adjusted, refunded) never expire.
     */
    private function drawLots(CreditAccount $account, int $credits): void
    {
        if ($credits <= 0) {
            return;
        }
        $lots = DB::connection(Connections::cell())->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('status', 'paid')->where('remaining', '>', 0)
            ->where(fn ($q) => $account->user_id === null ? $q->whereNull('account_user_id') : $q->where('account_user_id', $account->user_id))
            ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get(['id', 'remaining']);
        foreach ($lots as $lot) {
            if ($credits <= 0) {
                break;
            }
            $take = min($credits, (int) $lot->remaining);
            DB::connection(Connections::cell())->table('credit_purchases')->where('id', $lot->id)->decrement('remaining', $take);
            $credits -= $take;
        }
    }

    /**
     * Ends what is left of a bought pack 12 months after payment (never more than the account's carried-over
     * balance). Returns the credits that expired.
     */
    public function expirePurchase(string $purchaseUlid): int
    {
        return DB::connection(Connections::cell())->transaction(function () use ($purchaseUlid): int {
            $p = DB::connection(Connections::cell())->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('ulid', $purchaseUlid)
                ->where('status', 'paid')->whereNull('expired_at')->lockForUpdate()->first();
            if ($p === null) {
                return 0;
            }
            $account = $this->lock($p->account_user_id !== null ? (int) $p->account_user_id : null);
            $amount = max(0, min((int) $p->remaining, $account->balance - $account->included_balance));
            if ($amount > 0) {
                $this->write($account->id, 'expire', -$amount, 0, null, $account->user_id, false, 'expire:'.$purchaseUlid, 'Bought credits ended after 12 months ('.$p->pack_name.')', null);
            }
            DB::connection(Connections::cell())->table('credit_purchases')->where('id', $p->id)->update(['remaining' => 0, 'expired_at' => now(), 'updated_at' => now()]);

            return $amount;
        });
    }

    private function write(int $accountId, string $type, int $amount, int $included, ?string $action, ?int $userId, bool $automation, string $key, ?string $note, ?int $by): void
    {
        $db = DB::connection(Connections::cell());
        $db->table('credit_ledger')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'account_id' => $accountId, 'entry_type' => $type,
            'amount' => $amount, 'included_amount' => $included, 'period' => self::period(), 'action_key' => $action, 'user_id' => $userId, 'automation' => $automation,
            'idempotency_key' => $key, 'note' => $note, 'created_by' => $by, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')]);
        $db->table('credit_accounts')->where('id', $accountId)->incrementEach(['balance' => $amount, 'included_balance' => $included, 'lock_version' => 1], ['updated_at' => now()]);
    }

    /** The account row, locked for this transaction (created on first use). */
    private function lock(?int $userId): CreditAccount
    {
        $row = $this->accountQuery($userId)->lockForUpdate()->first();
        if ($row === null) {
            DB::connection(Connections::cell())->table('credit_accounts')->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'user_id' => $userId, 'balance' => 0,
                'included_balance' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = $this->accountQuery($userId)->lockForUpdate()->first();
        }

        return $row ?? throw new \RuntimeException('Credit account could not be created.');
    }

    private function account(?int $userId): ?CreditAccount
    {
        return $this->accountQuery($userId)->first();
    }

    /** @return Builder<CreditAccount> */
    private function accountQuery(?int $userId): Builder
    {
        return $userId === null ? CreditAccount::query()->whereNull('user_id') : CreditAccount::query()->where('user_id', $userId);
    }

    private function entry(string $key): ?CreditLedgerEntry
    {
        return CreditLedgerEntry::query()->where('idempotency_key', $key)->first();
    }

    private function usedFrom(?int $accountId): int
    {
        if ($accountId === null) {
            return 0;
        }

        return -(int) DB::connection(Connections::cell())->table('credit_ledger')->where('account_id', $accountId)->where('period', self::period())
            ->whereIn('entry_type', ['consume', 'refund'])->sum('amount');
    }

    private function usedFromPool(int $userId): int
    {
        $pool = $this->account(null);
        if ($pool === null) {
            return 0;
        }

        return -(int) DB::connection(Connections::cell())->table('credit_ledger')->where('account_id', $pool->id)->where('user_id', $userId)->where('period', self::period())
            ->whereIn('entry_type', ['consume', 'refund'])->sum('amount');
    }
}
