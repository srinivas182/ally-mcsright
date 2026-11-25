<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\WhoGetsPaid;
use Modules\Core\Access\Fields;
use Modules\Core\Access\Permissions;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Undo\Undo;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Cdas;
use Modules\Transactions\Closing\Appointments;
use Modules\Transactions\Closing\Vendors;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Compliance\Findings;
use Modules\Transactions\Deadlines\Deadlines;
use Modules\Transactions\DealAccess;
use Modules\Transactions\DealInbox;
use Modules\Transactions\DealIq;
use Modules\Transactions\DealIq\EmailIntake;
use Modules\Transactions\Deals;
use Modules\Transactions\Documents;
use Modules\Transactions\DualAgency;
use Modules\Transactions\EarnestMoney;
use Modules\Transactions\Forecast;
use Modules\Transactions\KeyDates;
use Modules\Transactions\Mls\MlsGuard;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Offers\Offers;
use Modules\Transactions\Playbooks\Playbooks;
use Modules\Transactions\Portal;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Referrals;
use Modules\Transactions\Reviews;
use Modules\Transactions\Risk\DealRisk;
use Modules\Transactions\WinCards;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deals: pipeline, deal page, create (also from a contact), status changes, uploads, broker review.
 * Agents see their own deals; owners, staff and reviewers see every deal.
 */
final class DealController
{
    public function index(Request $request, Deals $deals): Response
    {
        $user = self::user($request);
        $q = Deal::query()->orderByDesc('updated_at');
        if (! self::reviewer($user)) {
            $q->where('agent_user_id', self::actingFor($user));
        }

        $list = $q->limit(300)->get();
        $missing = $deals->missingCounts(array_values($list->pluck('id')->map(fn ($id): int => (int) $id)->all())); // PS1: no queries per deal
        $agents = User::query()->whereIn('id', $list->pluck('agent_user_id')->unique()->all())->pluck('first_name', 'id');

        return Inertia::render('deals/index', [
            'deals' => $list->map(fn (Deal $d): array => [
                'ulid' => $d->ulid, 'street' => $d->street, 'city' => $d->city, 'type' => $d->type, 'status' => $d->status, 'price_cents' => $d->price_cents,
                'closing_date' => $d->closing_date?->toDateString(), 'agent' => $agents[$d->agent_user_id] ?? null,
                'missing' => $missing[$d->id] ?? 0,
            ]),
            'forecast' => self::reviewer($user) && Fields::visible($user, 'commission') ? app(Forecast::class)->pending() : null,
            'canBringOverFiles' => self::reviewer($user),
            // DQ4: forward a signed contract to start a deal.
            'dealIqEmail' => in_array($user->kind, [UserKind::Agent, UserKind::Owner], true) ? app(EmailIntake::class)->address($user) : null,
            'atRisk' => self::atRisk(self::actingFor($user)),
            'alerts' => app(Deadlines::class)->prefs($user),
            'canCreateFor' => self::reviewer($user) ? User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]) : [],
        ]);
    }

    /** Custom field values on a deal (specs/core/10, N1). A locked (closed) deal can't change. */
    public function saveFields(Request $request, Deal $deal): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        abort_if($deal->locked_at !== null, 422, 'This deal is closed and locked.');
        $data = $request->validate(['fields' => ['required', 'array']]);
        app(CustomFields::class)->save('deal', $deal->id, (array) $data['fields'], $user, $deal->agent_user_id);

        return back();
    }

    /** "Why can I see this?" (specs/core/03, R1). */
    public function why(Request $request, Deal $deal): JsonResponse
    {
        $user = $this->authorise($request, $deal);
        $reasons = [];
        if ($deal->agent_user_id === $user->id) {
            $reasons[] = 'It is your deal.';
        } elseif ($user->assisted_user_id === $deal->agent_user_id) {
            $reasons[] = 'You assist the agent on this deal.';
        } else {
            $reasons = app(Permissions::class)->explain($user, 'transactions.review')['reasons'];
        }
        if (! Fields::visible($user, 'commission', $deal->agent_user_id)) {
            $reasons[] = 'Commission amounts are hidden from you: none of your roles includes "See commission amounts and earnings".';
        }

        return response()->json(['allowed' => true, 'reasons' => $reasons]);
    }

    public function show(Request $request, Deal $deal, Deals $deals, Reviews $reviews): Response
    {
        $user = $this->authorise($request, $deal);
        $db = DB::connection(Connections::cell());
        $all = Document::query()->where('deal_id', $deal->id)->orderBy('version')->get();
        $docs = $all->groupBy('item_id');
        $readings = DocumentReading::query()->whereIn('document_id', $all->pluck('id'))->get()->keyBy('document_id');
        $doc = fn (Document $d): array => ['ulid' => $d->ulid, 'filename' => $d->filename, 'version' => $d->version, 'via' => $d->via,
            'shared' => $d->getAttribute('shared_at') !== null, 'reading' => self::reading($readings->get($d->id), $d)];

        return Inertia::render('deals/show', [
            'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street, 'city' => $deal->city, 'state_code' => $deal->state_code, 'postal_code' => $deal->postal_code,
                'type' => $deal->type, 'status' => $deal->status, 'price_cents' => $deal->price_cents, 'gci_cents' => Fields::visible($user, 'commission', $deal->agent_user_id) ? $deal->gci_cents : null,
                'commission_hidden' => ! Fields::visible($user, 'commission', $deal->agent_user_id), 'mls_number' => $deal->mls_number,
                'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString(), 'closed_on' => $deal->closed_on?->toDateString(),
                'locked' => $deal->locked_at !== null, 'cancel_reason' => $deal->cancel_reason, 'agent' => User::query()->find($deal->agent_user_id)?->name,
                'imported_from' => $deal->imported_from, 'money_status' => $deal->money_status, 'money_note' => $deal->money_note, 'pay_overrides' => $deal->pay_overrides,
                'agent_paid_outside' => $deal->agent_paid_outside],
            // DA1: the other side of a dual deal, and what the state expects when one agent is on both sides.
            'dual' => self::dual($deal, $deals),
            'parties' => $db->table('tx_parties')->where('deal_id', $deal->id)->get(['role', 'name', 'company', 'email', 'phone']),
            'items' => Item::query()->where('deal_id', $deal->id)->orderBy('sort')->get()->map(fn (Item $i): array => [
                'ulid' => $i->ulid, 'label' => $i->label, 'required' => $i->required, 'stage' => $i->stage, 'status' => $i->status, 'fix_reason' => $i->fix_reason,
                'added_reason' => $i->getAttribute('added_reason'),
                'overdue' => $reviews->overdue($i),
                'documents' => ($docs[$i->id] ?? collect())->map($doc)->values(),
            ]),
            'missing' => $deals->missing($deal),
            'customFields' => app(CustomFields::class)->valuesFor('deal', $deal->id, $user, $deal->agent_user_id),
            'dealIq' => ['property_type' => $deal->property_type, 'financing' => $deal->financing, 'propertyTypes' => DealIq\Adapt::PROPERTY_TYPES, 'financingTypes' => DealIq\Adapt::FINANCING,
                'uploadRequests' => app(DealIq\UploadRequests::class)->forDeal($deal), 'history' => app(DealIq\DealAssistant::class)->history($deal, $user),
                'roles' => DealIq\DealAssistant::ROLES, 'setup' => $deal->setup_status !== null, 'offers' => Offers::forListing($deal)
                    ? DB::connection(Connections::cell())->table('tx_offers')->where('deal_id', $deal->id)->whereIn('status', ['received', 'shortlisted', 'countered'])->count() : null],
            'playbook' => app(Playbooks::class)->forDeal($deal) + [
                'mls' => MlsGuard::forDeal($deal),
                'linked' => ($l = $deal->getAttribute('linked_deal_id') !== null ? Deal::query()->whereKey((int) $deal->getAttribute('linked_deal_id'))->first() : null) !== null ? ['ulid' => $l->ulid, 'street' => $l->street] : null,
                'linkable' => Deal::query()->where('agent_user_id', $deal->agent_user_id)->where('id', '!=', $deal->id)->whereIn('status', ['pre_contract', 'under_contract'])->orderByDesc('id')->limit(30)
                    ->get(['ulid', 'street'])->map(fn (Deal $d): array => ['ulid' => $d->ulid, 'street' => $d->street])->all()],
            'closing' => ['time' => $deal->getAttribute('closing_time'), 'place' => $deal->getAttribute('closing_place'), 'appointments' => Appointments::forDeal($deal),
                'kinds' => Appointments::KINDS, 'vendors' => Vendors::all()],
            'portal' => ['people' => app(Portal\PortalAccess::class)->forDeal($deal), 'questions' => Portal\Concierge::forDeal($deal), 'locales' => Portal\Copy::LOCALES,
                'feedback' => DB::connection(Connections::cell())->table('tx_portal_feedback')->join('tx_portal_access', 'tx_portal_access.id', '=', 'tx_portal_feedback.access_id')
                    ->where('tx_portal_feedback.deal_id', $deal->id)->get(['tx_portal_access.name', 'tx_portal_feedback.rating', 'tx_portal_feedback.comment', 'tx_portal_feedback.may_quote'])
                    ->map(fn ($f): array => ['name' => (string) $f->name, 'rating' => (int) $f->rating, 'comment' => $f->comment !== null ? (string) $f->comment : null, 'may_quote' => (bool) $f->may_quote])->all()],
            'dates' => app(KeyDates::class)->forDeal($deal),
            'unsorted' => $all->filter(fn (Document $d): bool => $d->item_id === null)->map($doc)->values(),
            'emailIn' => app(DealInbox::class)->address($deal),
            'facts' => DocIq::dealFacts($deal),
            'docAnswer' => $request->session()->get('docAnswer'),
            'docCompare' => $request->session()->get('docCompare'),
            'docExplain' => $request->session()->get('docExplain'),
            'docShare' => $request->session()->get('docShare'),
            'compliance' => $deal->getAttribute('compliance_scanned_at') === null ? (function () use ($deal): array {
                app(ComplianceScan::class)->run($deal);

                return Findings::forDeal($deal);
            })() : Findings::forDeal($deal),
            'deposits' => app(EarnestMoney::class)->forDeal($deal),
            'referrals' => app(Referrals::class)->forDeal($deal),
            'winCard' => $deal->status === 'closed' && ($user->id === $deal->agent_user_id || self::reviewer($user)) ? app(WinCards::class)->forDeal($deal) : null, // RF1
            'cdas' => self::reviewer($user) ? app(Cdas::class)->forDeal($deal) : [],
            'titles' => self::reviewer($user) ? DB::connection(Connections::cell())->table('tx_title_companies')->whereNotNull('verified_at')->orderBy('name')->get(['ulid', 'name']) : [],
            'can' => ['trust' => $user->isKind(UserKind::Owner) || $user->can('transactions.trust.manage'), 'approveCda' => $user->isKind(UserKind::Owner) || $user->can('transactions.cda.approve'),
                'export' => $user->isKind(UserKind::Owner) || $user->can('transactions.export'), 'owner' => $user->isKind(UserKind::Owner),
                'managePay' => $user->isKind(UserKind::Owner) || $user->can('compensation.calculations.manage')],
            'reviewer' => self::reviewer($user),
            'reasons' => self::reviewer($user) ? $reviews->reasons() : [],
            'risk' => DealRisk::forDeals([$deal->id])[$deal->id], // RD1
            // CM5: the agents on the deal and their share; who can be added.
            'dealAgents' => (function () use ($deals, $deal): array {
                $list = $deals->agents($deal);
                $people = User::query()->whereIn('id', array_column($list, 'user_id'))->get()->keyBy('id');

                return array_map(fn (array $a): array => ['ulid' => (string) $people->get($a['user_id'])?->ulid, 'name' => (string) $people->get($a['user_id'])?->name,
                    'share' => $a['share_percent'], 'primary' => $a['user_id'] === $deal->agent_user_id], $list);
            })(),
            'agentChoices' => self::reviewer($user) || $user->isKind(UserKind::Owner) || $user->can('compensation.calculations.manage')
                ? User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values()
                : null,
            // CM2: who gets paid, tier by tier (amounts hidden from people who may not see commission).
            'whoGetsPaid' => Fields::visible($user, 'commission', $deal->agent_user_id) ? app(WhoGetsPaid::class)->for($deal->ulid, $user) : null,
        ]);
    }

    /** CM5: who is on the deal and their share (brokerage reviewers and money managers, before closing). */
    public function agents(Request $request, Deal $deal, Deals $deals): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        abort_unless(self::reviewer($user) || $user->isKind(UserKind::Owner) || $user->can('compensation.calculations.manage'), 403);
        $data = $request->validate(['agents' => ['required', 'array', 'min:1', 'max:10'], 'agents.*.user' => ['required', 'string', 'size:26'], 'agents.*.share' => ['required', 'numeric']]);
        $deals->setAgents($deal, $data['agents'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agents on this deal saved.']);

        return back();
    }

    public function store(Request $request, Deals $deals): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(Deals::TYPES)], 'street' => ['required', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:80'],
            'state_code' => ['required', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:10'], 'mls_number' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'], 'agent' => ['nullable', 'string', 'size:26'], 'contact' => ['nullable', 'string', 'size:26'],
            'buyer_agent' => ['nullable', 'string', 'size:26'], // DA1: who has the buyer side of a dual deal (brokerage staff choose)
        ]);
        $agent = isset($data['agent']) && self::reviewer($user) ? User::query()->where('ulid', $data['agent'])->firstOrFail()
            : User::query()->findOrFail(self::actingFor($user));
        $contact = null;
        if (isset($data['contact'])) {
            $contact = Contact::query()->where('ulid', $data['contact'])->firstOrFail();
            abort_unless(app(ContactVisibility::class)->canSee($user, $contact), 403);
            if ($contact->practice) { // SX5: a deal is real (money, compliance, the broker's reports)
                throw ValidationException::withMessages(['contact' => 'Practice people cannot be put on a real deal. Choose a real client.']);
            }
        }
        $buyerAgent = isset($data['buyer_agent']) && self::reviewer($user) ? User::query()->where('ulid', $data['buyer_agent'])->firstOrFail() : null;
        $deal = $deals->create($data + ['price_cents' => (int) round((float) ($data['price'] ?? 0) * 100), 'buyer_agent_user_id' => $buyerAgent?->id], $agent, $user, $contact);

        return redirect('/deals/'.$deal->ulid);
    }

    public function status(Request $request, Deal $deal, Deals $deals): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        $data = $request->validate([
            'status' => ['required', 'in:under_contract,closed,fell_through'], 'price' => ['nullable', 'numeric', 'min:0'], 'gci' => ['nullable', 'numeric', 'min:0'],
            'contract_date' => ['nullable', 'date'], 'closing_date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:300'],
            'buyer_gci' => ['nullable', 'numeric', 'min:0'], // DA1: the buyer side's commission on a dual deal
        ]);
        abort_if($data['status'] === 'closed' && ! self::reviewer($user), 403, 'The brokerage closes deals after review.');
        $deals->moveTo($deal, $data['status'], $user, [
            'price_cents' => isset($data['price']) ? (int) round((float) $data['price'] * 100) : null, 'gci_cents' => isset($data['gci']) ? (int) round((float) $data['gci'] * 100) : null,
            'contract_date' => $data['contract_date'] ?? null, 'closing_date' => $data['closing_date'] ?? null, 'reason' => $data['reason'] ?? null,
            'buyer_gci_cents' => isset($data['buyer_gci']) ? (int) round((float) $data['buyer_gci'] * 100) : null,
        ]);

        return back();
    }

    public function upload(Request $request, Deal $deal, Documents $documents): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        $data = $request->validate(['file' => ['required', 'file', 'max:25600'], 'item' => ['nullable', 'string', 'size:26'], 'via' => ['nullable', 'in:upload,photo']]);
        $item = isset($data['item']) ? Item::query()->where('ulid', $data['item'])->where('deal_id', $deal->id)->firstOrFail() : null;
        $documents->upload($deal, $item, $request->file('file'), (string) ($data['via'] ?? 'upload'), $user);

        return back();
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorise($request, Deal::query()->findOrFail($document->deal_id));

        return Storage::disk('local')->download($document->path, $document->filename);
    }

    public function review(Request $request, Item $item, Reviews $reviews): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::reviewer($user), 403);
        $data = $request->validate(['action' => ['required', 'in:approve,fix,waive'], 'reason' => ['nullable', 'string', 'max:300']]);
        match ((string) $data['action']) {
            'approve' => $reviews->approve($item, $user),
            'fix' => $reviews->requestFix($item, (string) ($data['reason'] ?? ''), $user),
            default => $reviews->waive($item, (string) ($data['reason'] ?? ''), $user),
        };

        return back();
    }

    /** Review queue: submitted items across deals, oldest (and overdue) first. */
    public function queue(Request $request, Reviews $reviews): Response
    {
        abort_unless(self::reviewer(self::user($request)), 403);

        $queue = Item::query()->where('status', 'submitted')->orderBy('submitted_at')->limit(200)->get();
        $risk = DealRisk::forDeals(array_values($queue->pluck('deal_id')->unique()->map(fn ($v): int => (int) $v)->all())); // RD1

        return Inertia::render('deals/review', [
            'sla_hours' => $reviews->slaHours(),
            'items' => $queue->map(function (Item $i) use ($reviews, $request, $risk): array {
                $deal = Deal::query()->findOrFail($i->deal_id);

                return ['ulid' => $i->ulid, 'label' => $i->label, 'deal' => $deal->ulid, 'street' => $deal->street, 'agent' => User::query()->whereKey($deal->agent_user_id)->value('first_name'),
                    'risk' => $risk[$deal->id] ?? ['score' => 0, 'level' => 'low', 'reasons' => []],
                    'submitted_at' => $i->submitted_at?->toIso8601String(), 'overdue' => $reviews->overdue($i),
                    'mine' => $i->getAttribute('assigned_reviewer_id') === $request->user()?->getAuthIdentifier(),
                    'reviewer' => $i->getAttribute('assigned_reviewer_id') !== null ? User::query()->whereKey($i->getAttribute('assigned_reviewer_id'))->value('first_name') : null];
            }),
            'reasons' => $reviews->reasons(),
        ]);
    }

    public function addDate(Request $request, Deal $deal, KeyDates $dates): RedirectResponse
    {
        $this->authorise($request, $deal);
        $data = $request->validate(['label' => ['required', 'string', 'max:80'], 'due_on' => ['required', 'date_format:Y-m-d']]);
        $dates->add($deal, $data['label'], $data['due_on']);

        return back();
    }

    public function toggleDate(Request $request, Deal $deal, int $date, KeyDates $dates): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        $dates->toggle($deal, $date);
        $row = DB::connection(Connections::cell())->table('tx_dates')->where('id', $date)->first(['label', 'done_at']);
        if ($row !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => ($row->done_at !== null ? 'Done: ' : 'Not done: ').$row->label,
                'undo' => app(Undo::class)->offer($user, 'deal.date.toggle', ['deal' => $deal->id, 'date' => $date], ($row->done_at !== null ? 'ticked off ' : 'unticked ').$row->label)]);
        }

        return back();
    }

    public function removeDate(Request $request, Deal $deal, int $date, KeyDates $dates): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        $row = DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->where('id', $date)->first();
        $dates->remove($deal, $date);
        if ($row !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed: '.$row->label,
                'undo' => app(Undo::class)->offer($user, 'deal.date.remove', ['row' => (array) $row], 'removed '.$row->label)]);
        }

        return back();
    }

    private function authorise(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal), 403);

        return $user;
    }

    /** Assistants work on the deals of the agent they assist. */
    /**
     * At risk today on the agent's own deals: key dates due within 3 days or missed, and open blockers.
     *
     * @return list<array{deal: string, street: string, what: string, when: ?string, level: string}>
     */
    private static function atRisk(int $agentId): array
    {
        $db = DB::connection(Connections::cell());
        $today = CarbonImmutable::today();
        $deals = $db->table('tx_deals')->where('agent_user_id', $agentId)->whereIn('status', ['pre_contract', 'under_contract'])->get(['id', 'ulid', 'street'])->keyBy('id');
        $out = [];
        foreach ($db->table('tx_dates')->whereIn('deal_id', $deals->keys())->whereNull('done_at')->where('due_on', '<=', $today->addDays(3)->toDateString())->orderBy('due_on')->get() as $d) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse(substr((string) $d->due_on, 0, 10)), false);
            $deal = $deals->get($d->deal_id);
            $out[] = ['deal' => (string) $deal?->ulid, 'street' => (string) $deal?->street, 'what' => (string) $d->label,
                'when' => $days < 0 ? 'missed '.CarbonImmutable::parse((string) $d->due_on)->format('M j') : ($days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days")),
                'level' => $days <= 0 ? 'high' : 'medium'];
        }
        foreach ($db->table('tx_findings')->whereIn('deal_id', $deals->keys())->where('status', 'open')->where('severity', 'blocker')->where('code', 'not like', 'risk.missed')->orderBy('id')->limit(20)->get() as $f) {
            $deal = $deals->get($f->deal_id);
            $out[] = ['deal' => (string) $deal?->ulid, 'street' => (string) $deal?->street, 'what' => (string) $f->message, 'when' => null, 'level' => 'high'];
        }

        return $out;
    }

    /**
     * What DocIQ made of one document, for the deal page.
     *
     * @return array<string, mixed>|null
     */
    private static function reading(?DocumentReading $r, Document $d): ?array
    {
        if ($r === null) {
            return null;
        }
        $methods = array_count_values((array) $r->methods);

        return ['status' => $r->status, 'pages' => $r->pages, 'ocr_pages' => (int) ($methods['ocr'] ?? 0), 'type' => $r->title,
            'split_into' => $d->getAttribute('split_into'), 'parent' => $d->getAttribute('parent_document_id') !== null,
            'sensitive' => $r->sensitive, 'facts' => count((array) $r->facts)];
    }

    /** Whether the user may work on this deal: reviewers, or its agent (and their assistant). */
    public static function canWork(User $user, Deal $deal): bool
    {
        return self::reviewer($user) || $deal->agent_user_id === self::actingFor($user);
    }

    private static function actingFor(User $user): int
    {
        return DealAccess::actingFor($user);
    }

    private static function reviewer(User $user): bool
    {
        return DealAccess::reviewer($user);
    }

    /**
     * @return array{side: string, other: array{ulid: string, agent: string|null, status: string, gci_cents: int|null}|null, warning: string|null, consent: bool}|null
     */
    private static function dual(Deal $deal, Deals $deals): ?array
    {
        if ($deal->type !== 'dual' && ! DualAgency::isBuyerSide($deal)) {
            return null;
        }
        $other = DualAgency::other($deal);
        $dual = $deal->type === 'dual' ? $deal : $other;

        return ['side' => $deal->type === 'dual' ? 'seller' : 'buyer',
            'other' => $other !== null ? ['ulid' => $other->ulid, 'agent' => User::query()->find($other->agent_user_id)?->name, 'status' => $other->status,
                'gci_cents' => $other->gci_cents] : null,
            'warning' => $dual !== null ? DualAgency::warning($dual, $deals) : null, 'consent' => $dual !== null && DualAgency::consentIn($dual)];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
