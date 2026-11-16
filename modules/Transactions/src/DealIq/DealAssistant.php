<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Models\Contact;
use Modules\Forms\Guard;
use Modules\Transactions\Deals;
use Modules\Transactions\KeyDates;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Timeline\Timeline;

/**
 * Talk or type to Deal-IQ on a deal (specs/ai/02, §1). A question gets an answer from the deal; an instruction becomes
 * a list of proposed actions shown in plain English, and nothing happens until the agent confirms. Actions come from
 * a fixed list: dates, tasks, checklist items, upload requests to the other side, a message to a party, or a draft
 * form. Nothing here moves money, changes wiring instructions or deletes anything.
 */
final class DealAssistant
{
    public const FEATURE = 'docs.assistant';

    public const ACTIONS = ['set_date', 'add_date', 'add_task', 'add_checklist_item', 'request_upload', 'message_party', 'draft_form'];

    public const ROLES = ['lender', 'title', 'escrow', 'co_op_agent', 'attorney', 'buyer', 'seller'];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{ulid: string, answer: string, actions: list<array{type: string, params: array<string, mixed>, says: string, problem: string|null}>}
     */
    public function propose(Deal $deal, string $text, User $by): array
    {
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'transactions.deal_assistant', 'deal-assistant:'.$deal->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['text' => mb_substr(trim($text), 0, 1000), 'deal' => (string) json_encode($this->context($deal)), 'today' => CarbonImmutable::today()->format('l Y-m-d')],
            outputSchema: ['type' => 'object', 'required' => ['answer'], 'properties' => ['answer' => ['type' => 'string'], 'actions' => ['type' => 'array']]], userId: $by->id));
        $json = (array) $result->json;
        $actions = [];
        foreach (array_slice((array) ($json['actions'] ?? []), 0, 8) as $a) {
            $a = (array) $a;
            $type = (string) ($a['type'] ?? '');
            if (! in_array($type, self::ACTIONS, true)) {
                continue;
            }
            $params = array_map(fn ($v) => is_scalar($v) ? $v : null, (array) ($a['params'] ?? []));
            $actions[] = ['type' => $type, 'params' => $params, 'says' => $this->describe($deal, $type, $params), 'problem' => $this->problem($deal, $type, $params)];
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_assistant_turns')->insert(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'user_id' => $by->id,
            'said' => mb_substr(trim($text), 0, 1000), 'answer' => mb_substr(trim((string) ($json['answer'] ?? '')), 0, 2000), 'actions' => json_encode($actions),
            'status' => $actions === [] ? 'done' : 'proposed', 'created_at' => now(), 'updated_at' => now()]);

        return ['ulid' => $ulid, 'answer' => mb_substr(trim((string) ($json['answer'] ?? '')), 0, 2000), 'actions' => $actions];
    }

    /**
     * Does the confirmed actions (the agent may untick some).
     *
     * @param  list<int>  $chosen  indexes of the actions to do
     * @return list<array{says: string, done: bool, note: string, link?: string}>
     */
    public function confirm(Deal $deal, string $turnUlid, array $chosen, User $by): array
    {
        $db = DB::connection(Connections::cell());
        $turn = $db->table('tx_assistant_turns')->where('ulid', $turnUlid)->where('deal_id', $deal->id)->where('user_id', $by->id)->first();
        if ($turn === null || $turn->status !== 'proposed') {
            throw ValidationException::withMessages(['turn' => 'This has already been done or dismissed.']);
        }
        $results = [];
        $datesChanged = false;
        foreach ((array) json_decode((string) $turn->actions, true) as $i => $a) {
            if (! in_array($i, $chosen, true)) {
                continue;
            }
            $a = (array) $a;
            $params = (array) ($a['params'] ?? []);
            try {
                if (($a['problem'] ?? null) !== null) {
                    throw ValidationException::withMessages(['action' => (string) $a['problem']]);
                }
                [$note, $link] = $this->run($deal, (string) $a['type'], $params, $by);
                $datesChanged = $datesChanged || in_array($a['type'], ['set_date', 'add_date'], true);
                $results[] = ['says' => (string) $a['says'], 'done' => true, 'note' => $note] + ($link !== null ? ['link' => $link] : []);
            } catch (ValidationException $e) {
                $results[] = ['says' => (string) $a['says'], 'done' => false, 'note' => (string) collect($e->errors())->flatten()->first()];
            }
        }
        $db->table('tx_assistant_turns')->where('id', $turn->id)->update(['status' => 'done', 'results' => json_encode($results), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.assistant.done', $deal, metadata: ['said' => $turn->said, 'done' => array_column(array_filter($results, fn ($r) => $r['done']), 'says')], label: $deal->street);
        if ($datesChanged) {
            app(Timeline::class)->rebuild($deal->refresh());
        }

        return $results;
    }

    public function dismiss(Deal $deal, string $turnUlid, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_assistant_turns')->where('ulid', $turnUlid)->where('deal_id', $deal->id)->where('user_id', $by->id)
            ->where('status', 'proposed')->update(['status' => 'dismissed', 'updated_at' => now()]);
    }

    /**
     * @return list<array{said: string, answer: string|null, status: string, results: list<array<string, mixed>>, at: string}>
     */
    public function history(Deal $deal, User $by): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_assistant_turns')->where('deal_id', $deal->id)->where('user_id', $by->id)->orderByDesc('id')->limit(10)->get()
            ->map(fn ($t): array => ['said' => (string) $t->said, 'answer' => $t->answer !== null ? (string) $t->answer : null, 'status' => (string) $t->status,
                'results' => array_values((array) json_decode((string) ($t->results ?? '[]'), true)), 'at' => CarbonImmutable::parse((string) $t->created_at)->toIso8601String()])->all());
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{0: string, 1: string|null}
     */
    private function run(Deal $deal, string $type, array $p, User $by): array
    {
        $date = fn (): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) === 1 ? (string) $p['date']
            : throw ValidationException::withMessages(['date' => 'Give the date as a day.']);
        switch ($type) {
            case 'set_date':
                $label = trim((string) ($p['label'] ?? ''));
                if (in_array(mb_strtolower($label), ['closing date', 'closing'], true)) {
                    $deal->update(['closing_date' => $date()]);

                    return ['Closing date set to '.CarbonImmutable::parse($date())->toFormattedDateString().'.', null];
                }
                if (in_array(mb_strtolower($label), ['contract date', 'effective date'], true)) {
                    $deal->update(['contract_date' => $date()]);

                    return ['Contract date set.', null];
                }
                $db = DB::connection(Connections::cell());
                $row = $db->table('tx_dates')->where('deal_id', $deal->id)->whereRaw('lower(label) = ?', [mb_strtolower($label)])->first();
                if ($row === null) {
                    app(KeyDates::class)->add($deal, $label, $date());
                } else {
                    // Moved by hand: it stops being recalculated by the timeline.
                    $db->table('tx_dates')->where('id', $row->id)->update(['due_on' => $date(), 'source' => 'manual', 'updated_at' => now()]);
                }

                return [$label.' moved to '.CarbonImmutable::parse($date())->toFormattedDateString().'.', null];
            case 'add_date':
                app(KeyDates::class)->add($deal, (string) ($p['label'] ?? 'Date'), $date());

                return ['Date added.', null];
            case 'add_task':
                app(Tasks::class)->create($deal->contact_id !== null ? Contact::query()->find($deal->contact_id) : null, [
                    'title' => mb_substr(trim((string) ($p['title'] ?? 'Follow up')), 0, 200).' ('.$deal->street.')',
                    'due_at' => CarbonImmutable::now()->addDays(max(0, (int) ($p['due_days'] ?? 0)))->setTime(17, 0)->toIso8601String(), 'assigned_to' => $deal->agent_user_id], $by);

                return ['Task added.', '/tasks'];
            case 'add_checklist_item':
                $label = mb_substr(trim((string) ($p['label'] ?? '')), 0, 150);
                if ($label === '' || Item::query()->where('deal_id', $deal->id)->where('label', $label)->exists()) {
                    return ['Already on the checklist.', null];
                }
                Item::query()->create(['deal_id' => $deal->id, 'label' => $label, 'required' => (bool) ($p['required'] ?? true), 'stage' => 'contract',
                    'sort' => (int) Item::query()->where('deal_id', $deal->id)->max('sort') + 1, 'status' => 'missing']);

                return ['Added to the checklist.', null];
            case 'request_upload':
                $party = $this->party($deal, (string) ($p['role'] ?? ''));
                $what = mb_substr(trim((string) ($p['what'] ?? '')), 0, 160);
                $item = Item::query()->where('deal_id', $deal->id)->whereRaw('lower(label) = ?', [mb_strtolower($what)])->first();
                app(UploadRequests::class)->send($deal, $item, $what, (string) $party->role, (string) $party->name, (string) $party->email, null, $by);

                return ['Upload link sent to '.$party->name.'.', null];
            case 'message_party':
                $party = $this->party($deal, (string) ($p['role'] ?? ''));
                $body = trim((string) ($p['body'] ?? ''));
                Guard::noWiringInstructions($body, 'body');
                Notification::route('mail', (string) $party->email)->notify(new PartyMessageMail(mb_substr(trim((string) ($p['subject'] ?? 'About '.$deal->street)), 0, 150),
                    mb_substr($body, 0, 3000), $by->name, (string) $by->email, (string) $deal->street));
                app(AuditLogger::class)->record('transactions.party.messaged', $deal, metadata: ['role' => $party->role, 'to' => AuditLogger::mask((string) $party->email)], label: $deal->street);

                return ['Message sent to '.$party->name.'.', null];
            case 'draft_form':
                $draft = app(FormDrafter::class)->draft($deal, (string) ($p['instruction'] ?? ''), $by)
                    ?? throw ValidationException::withMessages(['form' => 'None of your brokerage\'s forms fits this. Add the form under Forms, or draft it there.']);

                return ['Drafted "'.$draft['form'].'" for you to check'.($draft['flags'] !== [] ? '. Check: '.implode('; ', $draft['flags']) : '').'. Nothing is sent until you send it.',
                    '/forms/packets/'.$draft['packet']];
        }

        throw ValidationException::withMessages(['action' => 'Deal-IQ can\'t do that.']);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function describe(Deal $deal, string $type, array $p): string
    {
        $when = fn (): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) === 1 ? CarbonImmutable::parse((string) $p['date'])->format('l, M j') : 'a date';
        $who = fn (): string => ($party = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->where('role', (string) ($p['role'] ?? ''))->first()) !== null
            ? $party->name.' ('.str_replace('_', '-', (string) $party->role).')' : 'the '.str_replace('_', '-', (string) ($p['role'] ?? 'party'));

        return match ($type) {
            'set_date' => 'Move "'.($p['label'] ?? '').'" to '.$when(),
            'add_date' => 'Add the date "'.($p['label'] ?? '').'" on '.$when(),
            'add_task' => 'Add a task: '.($p['title'] ?? '').' (due in '.(int) ($p['due_days'] ?? 0).' days)',
            'add_checklist_item' => 'Add "'.($p['label'] ?? '').'" to the checklist',
            'request_upload' => 'Send '.$who().' a secure link to upload '.($p['what'] ?? 'a document'),
            'message_party' => 'Email '.$who().': "'.Str::limit((string) ($p['body'] ?? ''), 160).'"',
            'draft_form' => 'Draft a form: '.($p['instruction'] ?? ''),
            default => $type,
        };
    }

    /**
     * Why an action can't be done as proposed (shown before confirming), or null.
     *
     * @param  array<string, mixed>  $p
     */
    private function problem(Deal $deal, string $type, array $p): ?string
    {
        if ($deal->locked_at !== null) {
            return 'This deal is closed and locked.';
        }
        if (in_array($type, ['request_upload', 'message_party'], true)) {
            $role = (string) ($p['role'] ?? '');
            $party = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->where('role', $role)->whereNotNull('email')->first();
            if (! in_array($role, self::ROLES, true) || $party === null) {
                return 'Add the '.str_replace('_', '-', $role).' with an email address to the deal first.';
            }
        }
        if (in_array($type, ['set_date', 'add_date'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) !== 1) {
            return 'Say which day.';
        }
        if ($type === 'message_party') {
            try {
                Guard::noWiringInstructions((string) ($p['body'] ?? ''), 'body');
            } catch (ValidationException) {
                return 'Messages can\'t contain bank or wiring details.';
            }
        }

        return null;
    }

    private function party(Deal $deal, string $role): \stdClass
    {
        return DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->where('role', $role)->whereNotNull('email')->first()
            ?? throw ValidationException::withMessages(['party' => 'Add the '.str_replace('_', '-', $role).' with an email address to the deal first.']);
    }

    /**
     * What Deal-IQ knows about the deal, for answering.
     *
     * @return array<string, mixed>
     */
    private function context(Deal $deal): array
    {
        $db = DB::connection(Connections::cell());

        return [
            'address' => trim($deal->street.', '.$deal->city.', '.$deal->state_code), 'type' => $deal->type, 'status' => $deal->status, 'price' => $deal->price_cents / 100,
            'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString(),
            'dates' => array_map(fn (array $d): array => ['label' => $d['label'], 'date' => $d['due_on'], 'done' => $d['done']], app(KeyDates::class)->forDeal($deal)),
            'still_needed' => array_column(app(Deals::class)->missing($deal), 'label'),
            'parties' => $db->table('tx_parties')->where('deal_id', $deal->id)->get(['role', 'name'])->map(fn ($p): array => ['role' => (string) $p->role, 'name' => (string) $p->name])->all(),
        ];
    }
}
