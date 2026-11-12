<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\PlanVersion;
use Modules\Compensation\Rules\RevenueShareRules;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

/**
 * Distributes revenue share when a deal is finalised (legacy rules 5–9, 15–17). For each agent on the
 * deal, the distributable amount (basis × total of the levels) is split exactly across the levels; each
 * level goes to the upline on the closing date if active, otherwise to the root account (within its
 * per-deal cap) or into a missed earning, as the plan says. Payments credit each recipient's wallet
 * (Revenue share payable sub-account) in one balanced journal; reversals post exact negatives.
 */
final class RevenueShareEngine
{
    public const MAX_DEPTH = RevenueShareRules::MAX_LEVELS;

    /** @var array<int, int> recipient => cents in the deal being distributed */
    private array $credits = [];

    private ?string $limitReason = null;

    public function __construct(private readonly Sponsorships $sponsorships, private readonly Unlocking $unlocking, private readonly Members $members, private readonly Holds $holds) {}

    public function distribute(Calculation $final): void
    {
        $input = DealSideInput::fromArray($final->inputs);
        $date = $input->closingDate;
        $this->credits = [];
        $this->unlocking->forget();
        $budget = $this->budgetRoom($date);

        foreach ((array) ($final->result['agents'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $rules = $this->rulesFor($row);
            if ($rules === null || $rules->levels === []) {
                continue;
            }
            $base = $this->base($rules->basis, $row);
            $companyTotal = (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
            // Never more than the brokerage earned on the deal, nor above the plan's per-deal maximum.
            $distributable = min($this->distributable(max(0, $base), $rules), max(0, $companyTotal), $rules->perDealMaxCents ?? PHP_INT_MAX);
            if ($distributable <= 0) {
                continue;
            }
            $agentId = (int) $row['user_id'];
            $chain = $this->sponsorships->chain($agentId, $date, count($rules->levels));
            $co = $this->sponsorships->coSponsorOf($agentId, $date);
            $rootPaid = 0;

            foreach (Money::of($distributable)->allocate($rules->levels) as $i => $part) {
                if ($part->cents === 0) {
                    continue;
                }
                $level = $i + 1;
                $entry = ['level' => $level, 'percent' => $rules->levels[$i], 'base_cents' => $base, 'basis' => $rules->basis];
                // Level 1 is shared with a co-sponsor; deeper levels follow the primary sponsor's line.
                $slots = $level === 1 && $co !== null && isset($chain[0])
                    ? array_map(null, [$chain[0], $co[0]], array_values(array_map(fn (Money $m): int => $m->cents, $part->allocate([(string) BigDecimal::of(100)->minus($co[1]), $co[1]]))))
                    : [[$chain[$i] ?? null, $part->cents]];

                foreach ($slots as [$payee, $amount]) {
                    $this->slot($final, $row, $date, $rules, $entry, $payee, (int) $amount, $rootPaid, $budget);
                }
            }
        }

        $this->milestones($final, $date);

        if ($this->credits !== []) {
            $journal = $this->post($final, $this->credits, $date, 1, null);
            DB::connection(Connections::cell())->table('rs_entries')->where('calculation_id', $final->id)
                ->whereIn('kind', ['share', 'root', 'bonus'])->whereNull('journal_id')->update(['journal_id' => $journal]);
        }
        $this->warnBudget($date);
    }

    /**
     * Pays one level (or one co-sponsor's part of level 1), or records why it was missed.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $entry
     */
    private function slot(Calculation $final, array $row, CarbonImmutable $date, RevenueShareRules $rules, array $entry, ?int $payee, int $amount, int &$rootPaid, ?int &$budget): void
    {
        if ($amount <= 0) {
            return;
        }
        $reason = $payee === null ? 'missing_upline' : null;
        $resolved = null;
        if ($payee !== null) {
            [$resolved, $why] = $this->members->payee($payee, $rules, $date);
            $reason = $resolved === null ? $why : (! $this->unlocking->open($payee, (int) $entry['level'], $rules, $date) ? 'locked_level' : null);
        }

        if ($reason === null && $resolved !== null) {
            $pay = $this->limit($resolved, $amount, $rules, $date, $budget);
            $cutReason = $this->limitReason;
            if ($pay > 0) {
                $signals = $this->holds->signals((int) $row['user_id'], $resolved, $row, $rules, $date);
                if ($signals !== []) {
                    // Looks like self-sponsoring or gaming: held until the brokerage reviews it.
                    $id = $this->insert($final, $row, $date, $entry + ['kind' => 'held', 'amount_cents' => $pay, 'recipient_user_id' => $resolved, 'reason' => $signals[0]]);
                    $this->holds->hold($id, $final->tenant_id, $signals);
                } else {
                    $this->insert($final, $row, $date, $entry + ['kind' => 'share', 'amount_cents' => $pay, 'recipient_user_id' => $resolved]);
                    $this->credits[$resolved] = ($this->credits[$resolved] ?? 0) + $pay;
                }
            }
            $this->limitReason = $cutReason;
            if ($amount - $pay > 0) {
                $this->insert($final, $row, $date, $entry + ['kind' => 'missed', 'amount_cents' => $amount - $pay, 'reason' => $this->limitReason, 'missed_user_id' => $payee]);
            }

            return;
        }

        $policy = $payee === null ? $rules->missingUpline : $rules->ineligibleUpline;
        $root = $policy === 'root' ? $this->rootUserId() : null;
        $toRoot = 0;
        if ($root !== null) {
            $room = $rules->fallbackCapCents !== null ? max(0, $rules->fallbackCapCents - $rootPaid) : $amount;
            $toRoot = $this->limit($root, min($amount, $room), $rules, $date, $budget, recipientCap: false);
        }
        if ($toRoot > 0) {
            $this->insert($final, $row, $date, $entry + ['kind' => 'root', 'amount_cents' => $toRoot, 'reason' => $reason, 'recipient_user_id' => $root, 'missed_user_id' => $payee]);
            $this->credits[(int) $root] = ($this->credits[(int) $root] ?? 0) + $toRoot;
            $rootPaid += $toRoot;
        }
        if ($amount - $toRoot > 0) {
            $this->insert($final, $row, $date, $entry + ['kind' => 'missed', 'amount_cents' => $amount - $toRoot,
                'reason' => $root !== null ? ($this->limitReason ?? 'fallback_cap') : $reason, 'missed_user_id' => $payee]);
        }
    }

    /**
     * Applies the per-recipient yearly maximum and the brokerage budget; sets limitReason when it cuts.
     */
    private function limit(int $recipient, int $amount, RevenueShareRules $rules, CarbonImmutable $date, ?int &$budget, bool $recipientCap = true): int
    {
        $this->limitReason = null;
        $pay = $amount;
        if ($recipientCap && $rules->perRecipientYearlyMaxCents !== null) {
            $received = (int) DB::connection(Connections::cell())->table('rs_entries')->where('recipient_user_id', $recipient)->where('kind', 'share')
                ->whereYear('closing_date', $date->year)->sum('amount_cents') + ($this->credits[$recipient] ?? 0);
            $room = max(0, $rules->perRecipientYearlyMaxCents - $received);
            if ($pay > $room) {
                $pay = $room;
                $this->limitReason = 'recipient_limit';
            }
        }
        if ($budget !== null && $pay > $budget) {
            $pay = max(0, $budget);
            $this->limitReason = 'budget';
        }
        if ($budget !== null) {
            $budget -= $pay;
        }

        return $pay;
    }

    /** Room left in the brokerage's yearly revenue share budget (null when no budget is set). */
    public function budgetRoom(CarbonImmutable $date): ?int
    {
        $percent = DB::connection(Connections::cell())->table('rs_settings')->value('budget_percent');
        if ($percent === null) {
            return null;
        }

        return max(0, $this->ofPercent($this->companyDollarYtd($date), (string) $percent) - $this->paidYtd($date));
    }

    public function companyDollarYtd(CarbonImmutable $date): int
    {
        $total = 0;
        foreach (Calculation::query()->where('status', 'final')->get() as $calc) {
            if (substr((string) ($calc->inputs['closing_date'] ?? ''), 0, 4) !== (string) $date->year) {
                continue;
            }
            foreach ((array) ($calc->result['agents'] ?? []) as $r) {
                $total += (int) $r['company_cents'] + (int) ($r['post_cap_fee_cents'] ?? 0);
            }
        }

        return $total;
    }

    /** A percentage of an amount in cents, rounded down, with exact decimal maths. */
    private function ofPercent(int $cents, string $percent): int
    {
        return BigDecimal::of($cents)->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::Down)->toInt();
    }

    public function paidYtd(CarbonImmutable $date): int
    {
        return (int) DB::connection(Connections::cell())->table('rs_entries')->whereIn('kind', ['share', 'root'])
            ->whereYear('closing_date', $date->year)->sum('amount_cents');
    }

    /** Owners are emailed once a year when revenue share passes 80% of the budget. */
    private function warnBudget(CarbonImmutable $date): void
    {
        $db = DB::connection(Connections::cell());
        $settings = $db->table('rs_settings')->first();
        if ($settings === null || $settings->budget_percent === null || (int) ($settings->budget_warned_year ?? 0) === $date->year) {
            return;
        }
        $budget = $this->ofPercent($this->companyDollarYtd($date), (string) $settings->budget_percent);
        if ($budget <= 0 || $this->paidYtd($date) * 10 < $budget * 8) {
            return;
        }
        $db->table('rs_settings')->update(['budget_warned_year' => $date->year, 'updated_at' => now()]);
        foreach (User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get() as $owner) {
            $owner->notify(new BudgetWarning((string) $settings->budget_percent, $this->paidYtd($date), $budget));
        }
    }

    /** Reversal: exact negatives of every row, and a reversing journal. */
    public function reverse(Calculation $final): void
    {
        $db = DB::connection(Connections::cell());
        $rows = $db->table('rs_entries')->where('calculation_id', $final->id)->whereNull('reversal_of')
            ->whereNotIn('id', $db->table('rs_entries')->whereNotNull('reversal_of')->select('reversal_of'))->get();
        if ($rows->isEmpty()) {
            return;
        }
        $credits = [];
        foreach ($rows as $r) {
            $db->table('rs_entries')->insert([
                'tenant_id' => $r->tenant_id, 'calculation_id' => $r->calculation_id, 'deal_ref' => $r->deal_ref, 'source_agent_user_id' => $r->source_agent_user_id,
                'recipient_user_id' => $r->recipient_user_id, 'missed_user_id' => $r->missed_user_id, 'level' => $r->level, 'kind' => $r->kind, 'reason' => $r->reason,
                'basis' => $r->basis, 'base_cents' => -(int) $r->base_cents, 'percent' => $r->percent, 'amount_cents' => -(int) $r->amount_cents,
                'reversal_of' => $r->id, 'closing_date' => $r->closing_date, 'created_at' => now(),
            ]);
            if (in_array($r->kind, ['share', 'root', 'bonus'], true) && $r->recipient_user_id !== null) {
                $credits[(int) $r->recipient_user_id] = ($credits[(int) $r->recipient_user_id] ?? 0) + (int) $r->amount_cents;
            }
        }
        if ($credits !== []) {
            $this->post($final, $credits, CarbonImmutable::now(), -1, 'Reversal of revenue share for '.$final->deal_ref);
        }
        // Payments still held for review are cancelled with the deal.
        $db->table('rs_holds')->whereIn('entry_id', $rows->where('kind', 'held')->pluck('id'))->where('status', 'held')
            ->update(['status' => 'rejected', 'note' => 'Deal reversed', 'reviewed_at' => now(), 'updated_at' => now()]);
    }

    /** Posts a released held payment to the recipient's wallet. */
    public function postRelease(Calculation $final, int $entryId, int $recipient, int $cents): int
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $name = User::query()->whereKey($recipient)->value('first_name') ?? 'Agent';
        $draft = JournalDraft::make('revenue_share', 'revenue_share_release:'.$entryId)->source('revenue_share', $final->ulid)
            ->describe('Released revenue share for '.$final->deal_ref)
            ->debit($accounts->account('5000'), Money::of($cents))
            ->credit($accounts->subAccount('2100', 'user', $recipient, "Revenue share — {$name}"), Money::of($cents));

        return app(LedgerService::class)->post($draft)->id;
    }

    /** Milestone bonus (addition 10): to the sponsor when a recruit closes their very first deal. */
    private function milestones(Calculation $final, CarbonImmutable $date): void
    {
        $db = DB::connection(Connections::cell());
        foreach ((array) ($final->result['agents'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $rules = $this->rulesFor($row);
            $agentId = (int) $row['user_id'];
            if ($rules?->milestoneFirstClosingCents === null
                || $db->table('comp_calculation_agents')->where('agent_user_id', $agentId)->where('status', 'final')->count() !== 1
                || $db->table('rs_entries')->where('source_agent_user_id', $agentId)->where('kind', 'bonus')->whereNull('reversal_of')->exists()) {
                continue;
            }
            $sponsor = $this->sponsorships->sponsorOf($agentId, $date);
            [$payee] = $sponsor !== null ? $this->members->payee($sponsor, $rules, $date) : [null];
            if ($payee === null) {
                continue;
            }
            $this->insert($final, $row, $date, ['level' => 1, 'kind' => 'bonus', 'reason' => 'first_closing', 'basis' => 'milestone', 'base_cents' => 0,
                'percent' => '0', 'amount_cents' => $rules->milestoneFirstClosingCents, 'recipient_user_id' => $payee]);
            $this->credits[$payee] = ($this->credits[$payee] ?? 0) + $rules->milestoneFirstClosingCents;
        }
    }

    /** The agent's revenue share wallet balance. */
    public function wallet(User $user): Money
    {
        $account = app(ChartOfAccounts::class)->subAccount('2100', 'user', $user->id, "Revenue share — {$user->name}");

        return app(LedgerService::class)->balance($account);
    }

    /** Basis × total of the levels (the whole basis when levels total 100). */
    public function distributable(int $base, RevenueShareRules $rules): int
    {
        $total = array_reduce($rules->levels, fn (string $s, string $p): string => (string) BigDecimal::of($s)->plus($p), '0');

        return Money::of($base)->percentage($total)->cents;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function base(string $basis, array $row): int
    {
        $company = (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);

        return match ($basis) {
            'gci' => (int) $row['share_cents'],
            'company_dollar' => $company,
            default => $company - (int) ($row['brokerage_fees_cents'] ?? 0) - (int) ($row['credit_used_cents'] ?? 0),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rulesFor(array $row): ?RevenueShareRules
    {
        $version = isset($row['plan_version_id']) ? PlanVersion::query()->find((int) $row['plan_version_id']) : null;

        return $version instanceof PlanVersion ? $version->commissionRules()->revenueShare : null;
    }

    private function rootUserId(): ?int
    {
        $root = DB::connection(Connections::cell())->table('rs_settings')->value('root_user_id');
        if ($root !== null) {
            return (int) $root;
        }
        $owner = User::query()->where('kind', UserKind::Owner)->orderBy('id')->value('id');

        return $owner !== null ? (int) $owner : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $entry
     */
    private function insert(Calculation $final, array $row, CarbonImmutable $date, array $entry): int
    {
        return DB::connection(Connections::cell())->table('rs_entries')->insertGetId($entry + [
            'tenant_id' => $final->tenant_id, 'calculation_id' => $final->id, 'deal_ref' => $final->deal_ref,
            'source_agent_user_id' => (int) $row['user_id'], 'recipient_user_id' => null, 'missed_user_id' => null, 'reason' => null,
            'closing_date' => $date->toDateString(), 'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, int>  $credits  recipient => cents
     */
    private function post(Calculation $final, array $credits, CarbonImmutable $date, int $sign, ?string $description): int
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $key = 'revenue_share:'.$final->ulid.($sign < 0 ? ':reversal' : '');
        $draft = JournalDraft::make('revenue_share', $key)->effectiveOn($date)->source('revenue_share', $final->ulid)
            ->describe($description ?? 'Revenue share for '.$final->deal_ref);
        $total = Money::of(array_sum($credits));
        $sign > 0 ? $draft->debit($accounts->account('5000'), $total) : $draft->credit($accounts->account('5000'), $total);
        foreach ($credits as $userId => $cents) {
            $name = User::query()->whereKey($userId)->value('first_name') ?? 'Agent';
            $account = $accounts->subAccount('2100', 'user', $userId, "Revenue share — {$name}");
            $sign > 0 ? $draft->credit($account, Money::of($cents)) : $draft->debit($account, Money::of($cents));
        }

        return app(LedgerService::class)->post($draft)->id;
    }
}
