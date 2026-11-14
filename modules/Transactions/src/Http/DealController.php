<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Cdas;
use Modules\Transactions\Deals;
use Modules\Transactions\Documents;
use Modules\Transactions\EarnestMoney;
use Modules\Transactions\Forecast;
use Modules\Transactions\KeyDates;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Referrals;
use Modules\Transactions\Reviews;
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

        return Inertia::render('deals/index', [
            'deals' => $q->limit(300)->get()->map(fn (Deal $d): array => [
                'ulid' => $d->ulid, 'street' => $d->street, 'city' => $d->city, 'type' => $d->type, 'status' => $d->status, 'price_cents' => $d->price_cents,
                'closing_date' => $d->closing_date?->toDateString(), 'agent' => User::query()->whereKey($d->agent_user_id)->value('first_name'),
                'missing' => count($deals->missing($d)),
            ]),
            'forecast' => self::reviewer($user) ? app(Forecast::class)->pending() : null,
            'canCreateFor' => self::reviewer($user) ? User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]) : [],
        ]);
    }

    public function show(Request $request, Deal $deal, Deals $deals, Reviews $reviews): Response
    {
        $user = $this->authorise($request, $deal);
        $db = DB::connection(Connections::cell());
        $all = Document::query()->where('deal_id', $deal->id)->orderBy('version')->get();
        $docs = $all->groupBy('item_id');
        $readings = DocumentReading::query()->whereIn('document_id', $all->pluck('id'))->get()->keyBy('document_id');
        $doc = fn (Document $d): array => ['ulid' => $d->ulid, 'filename' => $d->filename, 'version' => $d->version, 'via' => $d->via,
            'reading' => self::reading($readings->get($d->id), $d)];

        return Inertia::render('deals/show', [
            'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street, 'city' => $deal->city, 'state_code' => $deal->state_code, 'postal_code' => $deal->postal_code,
                'type' => $deal->type, 'status' => $deal->status, 'price_cents' => $deal->price_cents, 'gci_cents' => $deal->gci_cents, 'mls_number' => $deal->mls_number,
                'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString(), 'closed_on' => $deal->closed_on?->toDateString(),
                'locked' => $deal->locked_at !== null, 'cancel_reason' => $deal->cancel_reason, 'agent' => User::query()->find($deal->agent_user_id)?->name],
            'parties' => $db->table('tx_parties')->where('deal_id', $deal->id)->get(['role', 'name', 'company', 'email', 'phone']),
            'items' => Item::query()->where('deal_id', $deal->id)->orderBy('sort')->get()->map(fn (Item $i): array => [
                'ulid' => $i->ulid, 'label' => $i->label, 'required' => $i->required, 'stage' => $i->stage, 'status' => $i->status, 'fix_reason' => $i->fix_reason,
                'added_reason' => $i->getAttribute('added_reason'),
                'overdue' => $reviews->overdue($i),
                'documents' => ($docs[$i->id] ?? collect())->map($doc)->values(),
            ]),
            'missing' => $deals->missing($deal),
            'dates' => app(KeyDates::class)->forDeal($deal),
            'unsorted' => $all->filter(fn (Document $d): bool => $d->item_id === null)->map($doc)->values(),
            'facts' => DocIq::dealFacts($deal),
            'docAnswer' => $request->session()->get('docAnswer'),
            'deposits' => app(EarnestMoney::class)->forDeal($deal),
            'referrals' => app(Referrals::class)->forDeal($deal),
            'cdas' => self::reviewer($user) ? app(Cdas::class)->forDeal($deal) : [],
            'titles' => self::reviewer($user) ? DB::connection(Connections::cell())->table('tx_title_companies')->whereNotNull('verified_at')->orderBy('name')->get(['ulid', 'name']) : [],
            'can' => ['trust' => $user->isKind(UserKind::Owner) || $user->can('transactions.trust.manage'), 'approveCda' => $user->isKind(UserKind::Owner) || $user->can('transactions.cda.approve'),
                'export' => $user->isKind(UserKind::Owner) || $user->can('transactions.export'), 'owner' => $user->isKind(UserKind::Owner)],
            'reviewer' => self::reviewer($user),
            'reasons' => self::reviewer($user) ? $reviews->reasons() : [],
        ]);
    }

    public function store(Request $request, Deals $deals): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(Deals::TYPES)], 'street' => ['required', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:80'],
            'state_code' => ['required', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:10'], 'mls_number' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'], 'agent' => ['nullable', 'string', 'size:26'], 'contact' => ['nullable', 'string', 'size:26'],
        ]);
        $agent = isset($data['agent']) && self::reviewer($user) ? User::query()->where('ulid', $data['agent'])->firstOrFail()
            : User::query()->findOrFail(self::actingFor($user));
        $contact = null;
        if (isset($data['contact'])) {
            $contact = Contact::query()->where('ulid', $data['contact'])->firstOrFail();
            abort_unless(app(ContactVisibility::class)->canSee($user, $contact), 403);
        }
        $deal = $deals->create($data + ['price_cents' => (int) round((float) ($data['price'] ?? 0) * 100)], $agent, $user, $contact);

        return redirect('/deals/'.$deal->ulid);
    }

    public function status(Request $request, Deal $deal, Deals $deals): RedirectResponse
    {
        $user = $this->authorise($request, $deal);
        $data = $request->validate([
            'status' => ['required', 'in:under_contract,closed,fell_through'], 'price' => ['nullable', 'numeric', 'min:0'], 'gci' => ['nullable', 'numeric', 'min:0'],
            'contract_date' => ['nullable', 'date'], 'closing_date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:300'],
        ]);
        abort_if($data['status'] === 'closed' && ! self::reviewer($user), 403, 'The brokerage closes deals after review.');
        $deals->moveTo($deal, $data['status'], $user, [
            'price_cents' => isset($data['price']) ? (int) round((float) $data['price'] * 100) : null, 'gci_cents' => isset($data['gci']) ? (int) round((float) $data['gci'] * 100) : null,
            'contract_date' => $data['contract_date'] ?? null, 'closing_date' => $data['closing_date'] ?? null, 'reason' => $data['reason'] ?? null,
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

        return Inertia::render('deals/review', [
            'sla_hours' => $reviews->slaHours(),
            'items' => Item::query()->where('status', 'submitted')->orderBy('submitted_at')->limit(200)->get()->map(function (Item $i) use ($reviews, $request): array {
                $deal = Deal::query()->findOrFail($i->deal_id);

                return ['ulid' => $i->ulid, 'label' => $i->label, 'deal' => $deal->ulid, 'street' => $deal->street, 'agent' => User::query()->whereKey($deal->agent_user_id)->value('first_name'),
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
        $this->authorise($request, $deal);
        $dates->toggle($deal, $date);

        return back();
    }

    public function removeDate(Request $request, Deal $deal, int $date, KeyDates $dates): RedirectResponse
    {
        $this->authorise($request, $deal);
        $dates->remove($deal, $date);

        return back();
    }

    private function authorise(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(self::reviewer($user) || $deal->agent_user_id === self::actingFor($user), 403);

        return $user;
    }

    /** Assistants work on the deals of the agent they assist. */
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
        return $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? $user->assisted_user_id : $user->id;
    }

    private static function reviewer(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || $user->can('transactions.review');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
