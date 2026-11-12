<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Brick\Math\BigDecimal;
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

    public function __construct(private readonly Sponsorships $sponsorships) {}

    public function distribute(Calculation $final): void
    {
        $input = DealSideInput::fromArray($final->inputs);
        $date = $input->closingDate;
        $credits = [];

        foreach ((array) ($final->result['agents'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $rules = $this->rulesFor($row);
            if ($rules === null || $rules->levels === []) {
                continue;
            }
            $base = $this->base($rules->basis, $row);
            if ($base <= 0) {
                continue;
            }
            $distributable = $this->distributable($base, $rules);
            if ($distributable <= 0) {
                continue;
            }
            $parts = Money::of($distributable)->allocate($rules->levels);
            $chain = $this->sponsorships->chain((int) $row['user_id'], $date, count($rules->levels));
            $root = $this->rootUserId();
            $rootPaid = 0;

            foreach ($parts as $i => $part) {
                $amount = $part->cents;
                if ($amount === 0) {
                    continue;
                }
                $upline = $chain[$i] ?? null;
                $entry = ['level' => $i + 1, 'percent' => $rules->levels[$i], 'base_cents' => $base, 'basis' => $rules->basis];

                if ($upline !== null && $this->eligible($upline)) {
                    $this->insert($final, $row, $date, $entry + ['kind' => 'share', 'amount_cents' => $amount, 'recipient_user_id' => $upline]);
                    $credits[$upline] = ($credits[$upline] ?? 0) + $amount;

                    continue;
                }

                $reason = $upline === null ? 'missing_upline' : 'ineligible_upline';
                $policy = $upline === null ? $rules->missingUpline : $rules->ineligibleUpline;
                $toRoot = 0;
                if ($policy === 'root' && $root !== null) {
                    $room = $rules->fallbackCapCents !== null ? max(0, $rules->fallbackCapCents - $rootPaid) : $amount;
                    $toRoot = min($amount, $room);
                }
                if ($toRoot > 0) {
                    $this->insert($final, $row, $date, $entry + ['kind' => 'root', 'amount_cents' => $toRoot, 'reason' => $reason, 'recipient_user_id' => $root, 'missed_user_id' => $upline]);
                    $credits[$root] = ($credits[$root] ?? 0) + $toRoot;
                    $rootPaid += $toRoot;
                }
                if ($amount - $toRoot > 0) {
                    $this->insert($final, $row, $date, $entry + ['kind' => 'missed', 'amount_cents' => $amount - $toRoot,
                        'reason' => $policy === 'root' && $root !== null ? 'fallback_cap' : $reason, 'missed_user_id' => $upline]);
                }
            }
        }

        if ($credits !== []) {
            $journal = $this->post($final, $credits, $date, 1, null);
            DB::connection(Connections::cell())->table('rs_entries')->where('calculation_id', $final->id)
                ->whereIn('kind', ['share', 'root'])->whereNull('journal_id')->update(['journal_id' => $journal]);
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
            if (in_array($r->kind, ['share', 'root'], true) && $r->recipient_user_id !== null) {
                $credits[(int) $r->recipient_user_id] = ($credits[(int) $r->recipient_user_id] ?? 0) + (int) $r->amount_cents;
            }
        }
        if ($credits !== []) {
            $this->post($final, $credits, CarbonImmutable::now(), -1, 'Reversal of revenue share for '.$final->deal_ref);
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

    private function eligible(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && $user->status === UserStatus::Active;
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
    private function insert(Calculation $final, array $row, CarbonImmutable $date, array $entry): void
    {
        DB::connection(Connections::cell())->table('rs_entries')->insert($entry + [
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
