<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Enums\Side;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Contact;
use Modules\Ledger\Money\Money;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/**
 * Deals and their lifecycle (specs/transactions/01): pre-contract → under contract (commission
 * calculated) → closed (only when every required document is approved or waived; commission and
 * revenue share finalised; file locked) or fell through (with a reason).
 */
final class Deals
{
    public const TYPES = ['listing', 'buyer', 'dual', 'lease', 'referral', 'new_construction'];

    public function __construct(private readonly Checklists $checklists, private readonly CommissionService $commissions, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  type, street, city, state_code, postal_code, mls_number, price_cents
     */
    public function create(array $data, User $agent, User $by, ?Contact $contact = null): Deal
    {
        if (! in_array($data['type'] ?? null, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose the kind of deal.']);
        }

        return DB::connection(Connections::cell())->transaction(function () use ($data, $agent, $by, $contact): Deal {
            $deal = Deal::query()->create([
                'type' => $data['type'], 'status' => 'pre_contract', 'agent_user_id' => $agent->id, 'contact_id' => $contact?->id,
                'street' => (string) $data['street'], 'city' => $data['city'] ?? null, 'state_code' => strtoupper((string) $data['state_code']),
                'postal_code' => $data['postal_code'] ?? null, 'mls_number' => $data['mls_number'] ?? null, 'price_cents' => (int) ($data['price_cents'] ?? 0),
                'created_by' => $by->id,
            ]);
            if ($contact !== null) {
                // One record from CRM to close: the client is a party, nothing typed twice.
                DB::connection(Connections::cell())->table('tx_parties')->insert([
                    'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => $deal->type === 'listing' ? 'seller' : 'buyer',
                    'name' => trim($contact->first_name.' '.$contact->last_name), 'email' => $contact->primary_email, 'phone' => $contact->primary_phone,
                    'contact_id' => $contact->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->checklists->apply($deal);
            $this->audit->record('transactions.deal.created', $deal, metadata: ['type' => $deal->type, 'address' => $deal->street], label: $deal->street);
            DealStatusChanged::dispatch($deal->tenant_id, $deal->id, null, 'pre_contract');

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $data  price_cents, gci_cents, contract_date, closing_date (under contract); reason (fell through)
     */
    public function moveTo(Deal $deal, string $status, User $by, array $data = []): Deal
    {
        $from = $deal->status;
        if ($deal->locked_at !== null) {
            throw ValidationException::withMessages(['status' => 'This deal is closed and locked.']);
        }

        match ($status) {
            'under_contract' => $this->underContract($deal, $data),
            'closed' => $this->close($deal, $by),
            'fell_through' => $this->fellThrough($deal, (string) ($data['reason'] ?? '')),
            default => throw ValidationException::withMessages(['status' => 'Unknown status.']),
        };
        $this->audit->record('transactions.deal.status', $deal, changes: ['status' => ['old' => $from, 'new' => $status]], severity: $status === 'closed' ? 'notice' : 'info', label: $deal->street);
        app(Compliance\ComplianceScan::class)->run($deal);
        DealStatusChanged::dispatch($deal->tenant_id, $deal->id, $from, $status);

        return $deal->refresh();
    }

    /**
     * How many things each deal still needs, for a list of deals, in a few queries (PS1: the deals list did three
     * queries per deal).
     *
     * @param  list<int>  $dealIds
     * @return array<int, int>
     */
    public function missingCounts(array $dealIds): array
    {
        $items = Item::query()->whereIn('deal_id', $dealIds)->where('required', true)->whereNotIn('status', ['approved', 'waived'])
            ->groupBy('deal_id')->selectRaw('deal_id, count(*) as n')->pluck('n', 'deal_id');
        $fields = app(CustomFields::class)->missingToCloseCounts($dealIds);
        $out = [];
        foreach ($dealIds as $id) {
            $out[$id] = (int) ($items[$id] ?? 0) + (int) ($fields[$id] ?? 0);
        }

        return $out;
    }

    /**
     * Required checklist items that are not approved or waived yet.
     *
     * @return list<array{label: string, status: string}>
     */
    public function missing(Deal $deal): array
    {
        $items = Item::query()->where('deal_id', $deal->id)->where('required', true)->whereNotIn('status', ['approved', 'waived'])->orderBy('sort')
            ->get()->map(fn (Item $i): array => ['label' => $i->label, 'status' => $i->status])->all();
        // Custom fields the brokerage requires before closing (specs/core/10, N1).
        foreach (app(CustomFields::class)->missingToClose($deal->id) as $label) {
            $items[] = ['label' => $label, 'status' => 'field_missing'];
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function underContract(Deal $deal, array $data): void
    {
        $price = (int) ($data['price_cents'] ?? $deal->price_cents);
        $gci = (int) ($data['gci_cents'] ?? 0);
        if ($deal->status !== 'pre_contract' || $price <= 0 || $gci <= 0 || empty($data['contract_date']) || empty($data['closing_date'])) {
            throw ValidationException::withMessages(['status' => 'To go under contract, give the price, the commission (GCI), the contract date and the expected closing date.']);
        }
        foreach (app()->tagged('transactions.deal_guards') as $guard) {
            if ($guard instanceof DealGuard && ($reason = $guard->check($deal)) !== null) {
                throw ValidationException::withMessages(['status' => $reason]);
            }
        }
        $closing = CarbonImmutable::parse((string) $data['closing_date']);
        $calc = $this->commissions->calculate($this->input($deal, $gci, $price, $closing), $deal->ulid);
        $deal->update(['status' => 'under_contract', 'price_cents' => $price, 'gci_cents' => $gci, 'contract_date' => $data['contract_date'],
            'closing_date' => $closing->toDateString(), 'calculation_id' => $calc->id]);
        app(Timeline\Timeline::class)->refresh($deal); // the timeline from the contract date (specs/ai/02, §8)
    }

    private function close(Deal $deal, User $by): void
    {
        if ($deal->status !== 'under_contract') {
            throw ValidationException::withMessages(['status' => 'Only a deal under contract can close.']);
        }
        $missing = $this->missing($deal);
        if ($missing !== []) {
            throw ValidationException::withMessages(['status' => 'Before closing, these still need approval: '.implode(', ', array_column($missing, 'label')).'.']);
        }
        // Recalculate on the actual closing date, then finalise (commission, caps and revenue share).
        $pending = $this->commissions->calculate($this->input($deal, $deal->gci_cents, $deal->price_cents, CarbonImmutable::today()), $deal->ulid);
        $final = $this->commissions->finalise($pending, $by->id);
        $deal->update(['status' => 'closed', 'closed_on' => CarbonImmutable::today()->toDateString(), 'calculation_id' => $final->id, 'locked_at' => now()]);
    }

    private function fellThrough(Deal $deal, string $reason): void
    {
        if (! in_array($deal->status, ['pre_contract', 'under_contract'], true) || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say why the deal fell through.']);
        }
        $deal->update(['status' => 'fell_through', 'cancel_reason' => trim($reason)]);
    }

    /** The commission engine's input for a deal, with the broker's overrides for it (specs/connect/12). */
    public function input(Deal $deal, int $gci, int $price, CarbonImmutable $closing): DealSideInput
    {
        $o = $deal->pay_overrides ?? [];

        return new DealSideInput(Money::of($gci), $price, $closing, [new AgentShare($deal->agent_user_id)],
            $deal->type === 'buyer' ? Side::Buying : Side::Listing,
            match ($deal->type) {
                'lease' => DealClass::Lease, 'referral' => DealClass::ReferralOnly, 'new_construction' => DealClass::NewConstruction, default => DealClass::Sale,
            },
            // Outgoing referral fees come off the top (referrals in and out, specs/transactions/01).
            outsideReferralPercent: app(Referrals::class)->outPercent($deal),
            excludedReason: ($o['no_cap'] ?? false) === true ? (string) ($o['reason'] ?? 'Broker override') : null,
            agentSplitPercent: isset($o['agent_split_percent']) && is_scalar($o['agent_split_percent']) ? (string) $o['agent_split_percent'] : null,
            waiveFees: ($o['waive_fees'] ?? false) === true, noRevenueShare: ($o['no_revenue_share'] ?? false) === true,
            overrideReason: isset($o['reason']) && is_string($o['reason']) ? $o['reason'] : null);
    }
}
