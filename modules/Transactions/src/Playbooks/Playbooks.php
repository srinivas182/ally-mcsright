<?php

declare(strict_types=1);

namespace Modules\Transactions\Playbooks;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Guard;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\DealIq\PartyMessageMail;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Timeline\StatePacks;

/**
 * Deal-type playbooks (specs/ai/02, §19): for each kind of deal, the documents it needs, its own dates and the emails
 * to send along the way. Ready to use, and editable by the brokerage (Deals → Playbooks) without building workflows
 * from scratch. Choosing a playbook adds its checklist items (once, never removed), puts its dates on the smart
 * timeline (recalculated with every change) and schedules its emails. Emails wait for the agent to send them, unless
 * the brokerage made a step automatic.
 *
 * Dates are "targets" unless the law fixes them; 1031 exchange deadlines are exact calendar days that never move.
 */
final class Playbooks
{
    /** Where a playbook date counts from. */
    public const ANCHORS = ['contract' => 'Contract date', 'closing' => 'Closing date', 'accepted' => 'Offer accepted'];

    /**
     * @var array<string, array{name: string, items: list<array{0: string, 1: bool}>, dates: list<array{label: string, anchor: string, days: int, business: bool, exact: bool}>, emails: list<array{key: string, to: list<string>, anchor: string, days: int, subject: string, body: string, auto: bool}>}>
     */
    public const BUILT_IN = [
        'standard_sale' => ['name' => 'Standard sale', 'items' => [], 'dates' => [
            ['label' => 'Order title (target)', 'anchor' => 'contract', 'days' => 1, 'business' => true, 'exact' => false],
            ['label' => 'Appraisal ordered (target)', 'anchor' => 'contract', 'days' => 7, 'business' => true, 'exact' => false],
            ['label' => 'Clear to close (target)', 'anchor' => 'closing', 'days' => -3, 'business' => true, 'exact' => false],
        ], 'emails' => [
            ['key' => 'introductions', 'to' => ['title', 'escrow', 'lender', 'co_op_agent', 'attorney'], 'anchor' => 'contract', 'days' => 0, 'auto' => false,
                'subject' => 'Introductions: {street}',
                'body' => "Hello everyone,\n\n{street} is under contract (contract date {contract}, closing {closing}). I am {agent}, and I am introducing everyone working on this transaction so we can keep it on schedule. Please reply-all with anything you need from the other side.\n\nThank you,\n{agent}"],
            ['key' => 'closing_week', 'to' => ['buyer', 'seller'], 'anchor' => 'closing', 'days' => -5, 'auto' => false,
                'subject' => 'Closing is coming up: {street}',
                'body' => "Hello,\n\nWe close on {closing}. I will send the time and place as soon as title confirms. Never send money based on wiring instructions in an email: call the title company on a number you already know first.\n\n{agent}"],
        ]],
        'new_construction' => ['name' => 'New construction', 'items' => [['Builder contract and addenda', true], ['Builder warranty documents', true], ['Certificate of occupancy', true], ['New-home inspection report', false]],
            'dates' => [
                ['label' => 'Pre-drywall inspection (target)', 'anchor' => 'closing', 'days' => -60, 'business' => false, 'exact' => false],
                ['label' => 'Builder orientation walk-through (target)', 'anchor' => 'closing', 'days' => -7, 'business' => false, 'exact' => false],
                ['label' => '11-month builder warranty inspection', 'anchor' => 'closing', 'days' => 330, 'business' => false, 'exact' => false],
            ], 'emails' => []],
        'short_sale' => ['name' => 'Short sale', 'items' => [['Short-sale lender approval letter', true], ['Short-sale addendum', true], ['Seller hardship letter and financials', false]],
            'dates' => [
                ['label' => 'Send the short-sale package to the lender (target)', 'anchor' => 'contract', 'days' => 3, 'business' => true, 'exact' => false],
                ['label' => 'Ask the lender for an approval update', 'anchor' => 'contract', 'days' => 30, 'business' => false, 'exact' => false],
            ], 'emails' => []],
        'reo' => ['name' => 'Bank-owned (REO)', 'items' => [['Bank (REO) addendum', true], ['Proof of funds or pre-approval sent to the bank', true], ['Seller disclosure exemption notice', false]],
            'dates' => [['label' => 'Bank\'s signed acceptance (target)', 'anchor' => 'accepted', 'days' => 5, 'business' => true, 'exact' => false]], 'emails' => []],
        'exchange_1031' => ['name' => '1031 exchange (sale)', 'items' => [['Qualified intermediary exchange agreement', true], ['Assignment of the contract to the intermediary', true]],
            'dates' => [
                ['label' => '1031: identify the replacement property (45 days)', 'anchor' => 'closing', 'days' => 45, 'business' => false, 'exact' => true],
                ['label' => '1031: close on the replacement (180 days, or the tax return due date if sooner)', 'anchor' => 'closing', 'days' => 180, 'business' => false, 'exact' => true],
            ], 'emails' => []],
        'probate' => ['name' => 'Probate or estate sale', 'items' => [['Letters testamentary or of administration', true], ['Court approval of the sale (where the court must confirm it)', false],
            ['Probate or estate sale addendum', true], ['Personal representative\'s identification', true]],
            'dates' => [['label' => 'Confirm whether the court must approve the sale (target)', 'anchor' => 'accepted', 'days' => 2, 'business' => true, 'exact' => false]], 'emails' => []],
        'lease' => ['name' => 'Lease', 'items' => [['Lease agreement', true], ['Rental application and screening consent', true], ['Security deposit receipt', true], ['Move-in condition checklist', true]],
            'dates' => [
                ['label' => 'Security deposit and first month due', 'anchor' => 'closing', 'days' => -3, 'business' => true, 'exact' => false],
                ['label' => 'Move-in condition walk-through', 'anchor' => 'closing', 'days' => 0, 'business' => false, 'exact' => false],
            ], 'emails' => []],
        'land' => ['name' => 'Land or lot', 'items' => [['Survey', true], ['Zoning or land-use confirmation', false], ['Utility availability letters', false], ['Soil or percolation test (septic)', false]],
            'dates' => [['label' => 'Order the survey (target)', 'anchor' => 'contract', 'days' => 3, 'business' => true, 'exact' => false]], 'emails' => []],
        'referral' => ['name' => 'Referral', 'items' => [['Signed referral agreement', true], ['Referral fee received', false]], 'dates' => [], 'emails' => []],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /** The playbook a deal follows: the one chosen, else the one for its kind. */
    public static function keyFor(Deal $deal): string
    {
        $chosen = (string) $deal->getAttribute('playbook');
        if (array_key_exists($chosen, self::BUILT_IN)) {
            return $chosen;
        }

        return match ($deal->type) {
            'new_construction' => 'new_construction', 'lease' => 'lease', 'referral' => 'referral', default => 'standard_sale',
        };
    }

    /**
     * A playbook as the brokerage set it (its own version, else the built-in one).
     *
     * @return array{name: string, items: list<array{0: string, 1: bool}>, dates: list<array{label: string, anchor: string, days: int, business: bool, exact: bool}>, emails: list<array{key: string, to: list<string>, anchor: string, days: int, subject: string, body: string, auto: bool}>}
     */
    public function definition(string $key): array
    {
        $base = self::BUILT_IN[$key] ?? self::BUILT_IN['standard_sale'];
        $row = DB::connection(Connections::cell())->table('tx_playbooks')->where('tenant_id', $this->tenants->id())->where('key', $key)->value('definition');

        return $row !== null ? self::validate((array) json_decode((string) $row, true), $base['name']) : $base;
    }

    /**
     * The brokerage's own version of a playbook.
     *
     * @param  array<string, mixed>  $definition
     */
    public function save(string $key, array $definition, User $by): void
    {
        if (! array_key_exists($key, self::BUILT_IN)) {
            abort(404);
        }
        $clean = self::validate($definition, self::BUILT_IN[$key]['name']);
        DB::connection(Connections::cell())->table('tx_playbooks')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'key' => $key],
            ['definition' => json_encode($clean), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('transactions.playbook.saved', metadata: ['playbook' => $key], severity: 'notice');
    }

    public function reset(string $key, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_playbooks')->where('tenant_id', $this->tenants->id())->where('key', $key)->delete();
        app(AuditLogger::class)->record('transactions.playbook.reset', metadata: ['playbook' => $key], severity: 'notice');
    }

    /**
     * The agent chooses the deal's playbook: its documents are added, its dates and emails scheduled.
     *
     * @return list<string> documents added
     */
    public function choose(Deal $deal, string $key, User $by): array
    {
        if (! array_key_exists($key, self::BUILT_IN)) {
            throw ValidationException::withMessages(['playbook' => 'Choose a playbook.']);
        }
        $deal->forceFill(['playbook' => $key])->save();
        $added = $this->apply($deal);
        app(AuditLogger::class)->record('transactions.playbook.chosen', $deal, metadata: ['playbook' => $key, 'added' => $added], label: $deal->street);

        return $added;
    }

    /**
     * Adds the playbook's documents (once) and schedules its emails.
     *
     * @return list<string> documents added
     */
    public function apply(Deal $deal): array
    {
        $def = $this->definition(self::keyFor($deal));
        $have = Item::query()->where('deal_id', $deal->id)->pluck('label')->map(fn ($l): string => mb_strtolower((string) $l))->all();
        $sort = (int) Item::query()->where('deal_id', $deal->id)->max('sort');
        $added = [];
        foreach ($def['items'] as [$label, $required]) {
            if (! in_array(mb_strtolower($label), $have, true)) {
                Item::query()->create(['deal_id' => $deal->id, 'label' => $label, 'required' => $required, 'stage' => 'contract', 'sort' => ++$sort, 'status' => 'missing']);
                $added[] = $label;
            }
        }
        $this->schedule($deal);

        return $added;
    }

    /**
     * The playbook's dates for the timeline.
     *
     * @return list<array{label: string, due_on: string, why: string}>
     */
    public function dates(Deal $deal): array
    {
        $out = [];
        foreach ($this->definition(self::keyFor($deal))['dates'] as $d) {
            $from = $this->anchor($deal, $d['anchor']);
            if ($from === null) {
                continue;
            }
            $state = $deal->state_code;
            $due = match (true) {
                $d['exact'] => $from->addDays($d['days']),
                $d['business'] => $d['days'] >= 0 ? StatePacks::addBusinessDays($from, $d['days'], $state) : self::subBusinessDays($from, -$d['days'], $state),
                default => StatePacks::roll($from->addDays($d['days']), $state),
            };
            $out[] = ['label' => $d['label'], 'due_on' => $due->toDateString(),
                'why' => self::ANCHORS[$d['anchor']].' '.($d['days'] >= 0 ? '+' : '−').abs($d['days']).($d['business'] ? ' business' : '').' days'.($d['exact'] ? ' (fixed by law: never moved for weekends or holidays)' : '')];
        }

        return $out;
    }

    /** Puts the playbook's emails on the deal's schedule (dates move with the deal's dates; sent ones stay sent). */
    public function schedule(Deal $deal): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($this->definition(self::keyFor($deal))['emails'] as $e) {
            $from = $this->anchor($deal, $e['anchor']);
            if ($from === null) {
                continue;
            }
            $due = $from->addDays($e['days'])->setTime(9, 0);
            $row = $db->table('tx_playbook_steps')->where('deal_id', $deal->id)->where('step', $e['key'])->first();
            if ($row === null) {
                $db->table('tx_playbook_steps')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'step' => $e['key'], 'due_at' => $due, 'status' => 'waiting',
                    'created_at' => now(), 'updated_at' => now()]);
            } elseif ($row->status === 'waiting') {
                $db->table('tx_playbook_steps')->where('id', $row->id)->update(['due_at' => $due, 'updated_at' => now()]);
            }
        }
    }

    /**
     * Steps that are due become ready for the agent, or are sent when the brokerage made them automatic (hourly).
     *
     * @return array{ready: int, sent: int}
     */
    public function run(): array
    {
        $db = DB::connection(Connections::cell());
        $counts = ['ready' => 0, 'sent' => 0];
        foreach ($db->table('tx_playbook_steps')->where('status', 'waiting')->where('due_at', '<=', now())->get() as $s) {
            $deal = Deal::query()->whereKey((int) $s->deal_id)->first();
            if ($deal === null || ! in_array($deal->status, ['pre_contract', 'under_contract'], true)) {
                $db->table('tx_playbook_steps')->where('id', $s->id)->update(['status' => 'skipped', 'updated_at' => now()]);

                continue;
            }
            $email = $this->email($deal, (string) $s->step);
            $agent = User::query()->whereKey($deal->agent_user_id)->first();
            if ($email !== null && $email['auto'] && $agent !== null && $this->recipients($deal, $email['to']) !== []) {
                $this->send($deal, (string) $s->step, $agent);
                $counts['sent']++;
            } else {
                $db->table('tx_playbook_steps')->where('id', $s->id)->update(['status' => 'ready', 'updated_at' => now()]);
                $counts['ready']++;
            }
        }

        return $counts;
    }

    /** Sends a step's email to everyone in its roles who has an email address (the agent's own words, filled in). */
    public function send(Deal $deal, string $step, User $by): int
    {
        $email = $this->email($deal, $step) ?? abort(404);
        $to = $this->recipients($deal, $email['to']);
        if ($to === []) {
            throw ValidationException::withMessages(['step' => 'Nobody on the deal in those roles has an email address yet.']);
        }
        $agent = User::query()->whereKey($deal->agent_user_id)->first() ?? $by;
        $fill = fn (string $t): string => strtr($t, ['{street}' => $deal->street, '{agent}' => $agent->name, '{contract}' => $deal->contract_date?->toFormattedDateString() ?? 'to be confirmed',
            '{closing}' => $deal->closing_date?->toFormattedDateString() ?? 'to be confirmed']);
        $body = $fill($email['body']);
        Guard::noWiringInstructions($body, 'body');
        foreach ($to as $address) {
            Notification::route('mail', $address)->notify(new PartyMessageMail($fill($email['subject']), $body, $agent->name, (string) $agent->email, $deal->street));
        }
        DB::connection(Connections::cell())->table('tx_playbook_steps')->updateOrInsert(['deal_id' => $deal->id, 'step' => $step],
            ['tenant_id' => $deal->tenant_id, 'status' => 'sent', 'sent_at' => now(), 'sent_by' => $by->id, 'due_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('transactions.playbook.sent', $deal, metadata: ['step' => $step, 'to' => count($to)], label: $deal->street);

        return count($to);
    }

    public function skip(Deal $deal, string $step, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_playbook_steps')->where('deal_id', $deal->id)->where('step', $step)->whereIn('status', ['waiting', 'ready'])
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    /**
     * The deal's playbook and its emails, for the deal page.
     *
     * @return array{key: string, name: string, choices: array<string, string>, steps: list<array{step: string, subject: string, to: list<string>, due: string, status: string, auto: bool, recipients: int}>}
     */
    public function forDeal(Deal $deal): array
    {
        $key = self::keyFor($deal);
        $rows = DB::connection(Connections::cell())->table('tx_playbook_steps')->where('deal_id', $deal->id)->get()->keyBy('step');
        $steps = [];
        foreach ($this->definition($key)['emails'] as $e) {
            $r = $rows->get($e['key']);
            $steps[] = ['step' => $e['key'], 'subject' => strtr($e['subject'], ['{street}' => $deal->street]), 'to' => $e['to'],
                'due' => $r !== null ? CarbonImmutable::parse((string) $r->due_at)->toDateString() : 'when its date is known', 'status' => $r !== null ? (string) $r->status : 'waiting',
                'auto' => $e['auto'], 'recipients' => count($this->recipients($deal, $e['to']))];
        }

        return ['key' => $key, 'name' => $this->definition($key)['name'], 'choices' => array_map(fn (array $p): string => $p['name'], self::BUILT_IN), 'steps' => $steps];
    }

    /**
     * @return array{key: string, to: list<string>, anchor: string, days: int, subject: string, body: string, auto: bool}|null
     */
    private function email(Deal $deal, string $step): ?array
    {
        foreach ($this->definition(self::keyFor($deal))['emails'] as $e) {
            if ($e['key'] === $step) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function recipients(Deal $deal, array $roles): array
    {
        return array_values(array_unique(DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->whereIn('role', $roles)->whereNotNull('email')
            ->pluck('email')->map(fn ($e): string => mb_strtolower((string) $e))->filter(fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)->all()));
    }

    private function anchor(Deal $deal, string $anchor): ?CarbonImmutable
    {
        return match ($anchor) {
            'contract' => $deal->contract_date !== null ? CarbonImmutable::parse($deal->contract_date->toDateString()) : null,
            'closing' => $deal->closing_date !== null ? CarbonImmutable::parse($deal->closing_date->toDateString()) : null,
            'accepted' => ($t = (array) json_decode((string) ($deal->getAttribute('terms') ?? ''), true)) !== [] && isset($t['accepted_on']) ? CarbonImmutable::parse((string) $t['accepted_on']) : null,
            default => null,
        };
    }

    private static function subBusinessDays(CarbonImmutable $day, int $n, ?string $state): CarbonImmutable
    {
        $d = $day;
        while ($n > 0) {
            $d = $d->subDay();
            if (StatePacks::isBusinessDay($d, $state)) {
                $n--;
            }
        }

        return $d;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{name: string, items: list<array{0: string, 1: bool}>, dates: list<array{label: string, anchor: string, days: int, business: bool, exact: bool}>, emails: list<array{key: string, to: list<string>, anchor: string, days: int, subject: string, body: string, auto: bool}>}
     */
    private static function validate(array $d, string $name): array
    {
        $items = [];
        foreach (array_slice((array) ($d['items'] ?? []), 0, 40) as $i) {
            $i = array_values((array) $i);
            $label = mb_substr(trim((string) ($i[0] ?? '')), 0, 150);
            if ($label !== '') {
                $items[] = [$label, (bool) ($i[1] ?? false)];
            }
        }
        $dates = [];
        foreach (array_slice((array) ($d['dates'] ?? []), 0, 30) as $x) {
            $x = (array) $x;
            $label = mb_substr(trim((string) ($x['label'] ?? '')), 0, 80);
            if ($label === '' || ! array_key_exists((string) ($x['anchor'] ?? ''), self::ANCHORS)) {
                continue;
            }
            $dates[] = ['label' => $label, 'anchor' => (string) $x['anchor'], 'days' => max(-400, min(400, (int) ($x['days'] ?? 0))), 'business' => (bool) ($x['business'] ?? false),
                'exact' => (bool) ($x['exact'] ?? false)];
        }
        $emails = [];
        $keys = [];
        foreach (array_slice((array) ($d['emails'] ?? []), 0, 20) as $n => $e) {
            $e = (array) $e;
            $subject = mb_substr(trim((string) ($e['subject'] ?? '')), 0, 150);
            $body = mb_substr(trim((string) ($e['body'] ?? '')), 0, 3000);
            $to = array_values(array_intersect(DealAssistant::ROLES, (array) ($e['to'] ?? [])));
            if ($subject === '' || $body === '' || $to === [] || ! array_key_exists((string) ($e['anchor'] ?? ''), self::ANCHORS)) {
                continue;
            }
            Guard::noWiringInstructions($body, 'emails');
            $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower((string) ($e['key'] ?? ''))) ?: 'email_'.$n;
            while (in_array($key, $keys, true)) {
                $key .= '_';
            }
            $keys[] = $key;
            $emails[] = ['key' => mb_substr($key, 0, 40), 'to' => $to, 'anchor' => (string) $e['anchor'], 'days' => max(-400, min(400, (int) ($e['days'] ?? 0))),
                'subject' => $subject, 'body' => $body, 'auto' => (bool) ($e['auto'] ?? false)];
        }

        return ['name' => $name, 'items' => $items, 'dates' => $dates, 'emails' => $emails];
    }
}
