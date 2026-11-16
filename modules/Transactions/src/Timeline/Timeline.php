<?php

declare(strict_types=1);

namespace Modules\Transactions\Timeline;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealIq\DatesChangedMail;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Playbooks\Playbooks;
use Modules\Transactions\Reading\DocIq;

/**
 * The smart timeline (specs/ai/02, §8): the deal's deadlines from its contract (the dates DocIQ read, and the deal's
 * contract and closing dates), filled in and adjusted by the state's rule pack. Rebuilding after a change (an
 * amendment, a new closing date) recalculates every dependent date and says who needs to be told.
 */
final class Timeline
{
    public const SOURCE = 'timeline';

    /** Deadlines DocIQ reads from the contract => the label on the deal. */
    private const READ = ['option_period_end' => 'Option period ends', 'inspection_deadline' => 'Inspection deadline', 'financing_deadline' => 'Financing deadline',
        'appraisal_deadline' => 'Appraisal deadline'];

    /**
     * What the timeline should hold now.
     *
     * @return list<array{label: string, due_on: string, why: string}>
     */
    public function plan(Deal $deal): array
    {
        $state = $deal->state_code;
        $pack = StatePacks::for($state);
        $facts = collect(DocIq::dealFacts($deal))->keyBy('name');
        $out = [];
        $contract = $deal->contract_date !== null ? CarbonImmutable::parse($deal->contract_date->toDateString()) : null;
        if ($contract === null && ($c = $facts->get('contract_date')) !== null && ($d = DocIq::dateOf((string) $c['value'])) !== null) {
            $contract = CarbonImmutable::parse($d);
        }
        if ($contract !== null && $pack['earnest_money_days'] !== null) {
            $due = StatePacks::roll($contract->addDays($pack['earnest_money_days']), $state);
            $out[] = ['label' => 'Earnest money due', 'due_on' => $due->toDateString(), 'why' => "{$pack['earnest_money_days']} days after the contract date ({$pack['source']})"];
        }
        if ($contract !== null && $pack['attorney_review_business_days'] !== null) {
            $out[] = ['label' => 'Attorney review ends', 'due_on' => StatePacks::addBusinessDays($contract, $pack['attorney_review_business_days'], $state)->toDateString(),
                'why' => $pack['source']];
        }
        foreach (self::READ as $fact => $label) {
            $f = $facts->get($fact);
            $date = $f !== null ? DocIq::dateOf((string) $f['value']) : null;
            if ($date !== null) {
                $raw = CarbonImmutable::parse($date);
                $rolled = StatePacks::roll($raw, $state);
                $out[] = ['label' => $label, 'due_on' => $rolled->toDateString(),
                    'why' => 'From the contract (page '.$f['page'].')'.($rolled->notEqualTo($raw) ? ', moved from '.$raw->toFormattedDateString().' because it falls on a weekend or holiday' : '')];
            }
        }
        // The option period from the accepted offer, when the contract itself has not been read.
        $terms = (array) json_decode((string) ($deal->getAttribute('terms') ?? ''), true);
        if ($contract !== null && (int) ($terms['option_days'] ?? 0) > 0 && ! in_array('Option period ends', array_column($out, 'label'), true)) {
            $raw = $contract->addDays((int) $terms['option_days']);
            $out[] = ['label' => 'Option period ends', 'due_on' => StatePacks::roll($raw, $state)->toDateString(), 'why' => (int) $terms['option_days'].' days after the contract date (the accepted offer)'];
        }
        // The deal's playbook (specs/ai/02, §19), and a linked 1031 sale's deadlines on the replacement purchase.
        $playbooks = app(Playbooks::class);
        foreach ($playbooks->dates($deal) as $p) {
            $out[] = $p;
        }
        $linked = $deal->getAttribute('linked_deal_id') !== null ? Deal::query()->whereKey((int) $deal->getAttribute('linked_deal_id'))->first() : null;
        if ($linked !== null && Playbooks::keyFor($linked) === 'exchange_1031') {
            foreach ($playbooks->dates($linked) as $p) {
                $out[] = ['label' => $p['label'], 'due_on' => $p['due_on'], 'why' => 'From the linked sale at '.$linked->street.': '.$p['why']];
            }
        }
        if ($deal->closing_date !== null) {
            $closing = CarbonImmutable::parse($deal->closing_date->toDateString());
            $out[] = ['label' => 'Final walk-through', 'due_on' => $closing->subDay()->toDateString(), 'why' => 'The day before closing'];
            if (! StatePacks::isBusinessDay($closing, $state)) {
                $out[] = ['label' => 'Check the closing date', 'due_on' => $closing->toDateString(), 'why' => 'Closing falls on a weekend or holiday: confirm title can close that day'];
            }
        }

        return $out;
    }

    /**
     * Writes the plan to the deal's dates, replacing earlier timeline dates that aren't done, and returns what changed.
     *
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    public function rebuild(Deal $deal): array
    {
        $db = DB::connection(Connections::cell());
        $old = $db->table('tx_dates')->where('deal_id', $deal->id)->where('source', self::SOURCE)->whereNull('done_at')->get()->keyBy('label');
        // Dates already done, or already on the deal from elsewhere (read and applied, or set by hand), are left alone.
        $keep = $db->table('tx_dates')->where('deal_id', $deal->id)->where(fn ($q) => $q->where('source', '!=', self::SOURCE)->orWhereNotNull('done_at'))
            ->pluck('label')->map(fn ($l): string => mb_strtolower((string) $l))->all();
        $plan = array_values(array_filter($this->plan($deal), fn (array $p): bool => ! in_array(mb_strtolower($p['label']), $keep, true)));
        $changes = [];
        foreach ($plan as $p) {
            $was = $old->get($p['label']);
            $wasOn = $was !== null ? substr((string) $was->due_on, 0, 10) : null;
            if ($wasOn !== $p['due_on']) {
                $changes[] = ['label' => $p['label'], 'from' => $wasOn, 'to' => $p['due_on']];
            }
        }
        foreach ($old as $label => $row) {
            if (! in_array($label, array_column($plan, 'label'), true)) {
                $changes[] = ['label' => (string) $label, 'from' => substr((string) $row->due_on, 0, 10), 'to' => null];
            }
        }
        $db->transaction(function () use ($db, $deal, $plan): void {
            $db->table('tx_dates')->where('deal_id', $deal->id)->where('source', self::SOURCE)->whereNull('done_at')->delete();
            foreach ($plan as $p) {
                $db->table('tx_dates')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'label' => $p['label'], 'due_on' => $p['due_on'],
                    'source' => self::SOURCE, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        app(Playbooks::class)->schedule($deal); // emails tied to these dates move with them

        return $changes;
    }

    /**
     * Rebuild after something changed (an amendment read, a new closing date): when dates already on the timeline
     * moved, the agent is told what moved and who to tell.
     *
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    public function refresh(Deal $deal): array
    {
        $changes = $this->rebuild($deal);
        $moved = array_values(array_filter($changes, fn (array $c): bool => $c['from'] !== null));
        $agent = User::query()->find($deal->agent_user_id);
        if ($moved !== [] && $agent !== null) {
            $tell = array_map(fn (array $p): string => $p['name'].' ('.str_replace('_', '-', $p['role']).')', self::whoToTell($deal));
            $agent->notify(new DatesChangedMail((string) $deal->street, $moved, $tell, '/deals/'.$deal->ulid));
        }

        return $changes;
    }

    /**
     * Who should hear about changed dates: everyone on the deal other than the agent, with how to reach them.
     *
     * @return list<array{role: string, name: string, email: string|null}>
     */
    public static function whoToTell(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->orderBy('id')->get()
            ->map(fn ($p): array => ['role' => (string) $p->role, 'name' => (string) $p->name, 'email' => $p->email !== null ? (string) $p->email : null])->all());
    }
}
