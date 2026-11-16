<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Repairs\RepairRequests;

/** Repair requests from the inspection report (specs/ai/02, §17, DQ4). */
final class RepairsController
{
    public function index(Request $request, Deal $deal): Response
    {
        self::authorise($request, $deal);
        $deadline = DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->whereNull('done_at')
            ->where(fn ($q) => $q->where('label', 'like', '%option%')->orWhere('label', 'like', '%inspection%'))->orderBy('due_on')->first();

        return Inertia::render('deals/repairs', [
            'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street, 'locked' => $deal->locked_at !== null],
            'documents' => Document::query()->where('deal_id', $deal->id)->whereIn('mime', ['application/pdf', 'image/jpeg', 'image/png'])->orderByDesc('id')->get()
                ->map(fn (Document $d): array => ['ulid' => $d->ulid, 'filename' => $d->filename])->values(),
            'requests' => RepairRequests::forDeal($deal),
            'deadline' => $deadline !== null ? ['label' => (string) $deadline->label, 'date' => substr((string) $deadline->due_on, 0, 10)] : null,
            'categories' => RepairRequests::CATEGORIES,
            'packet' => $request->session()->get('packet'),
        ]);
    }

    public function start(Request $request, Deal $deal, RepairRequests $repairs, Documents $documents): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['document' => ['nullable', 'string', 'size:26'], 'file' => ['nullable', 'file', 'max:25600']]);
        $doc = isset($data['file']) ? $documents->upload($deal, null, $data['file'], 'upload', $user)
            : Document::query()->where('deal_id', $deal->id)->where('ulid', (string) ($data['document'] ?? ''))->firstOrFail();
        $repairs->start($deal, $doc, $user);

        return back();
    }

    public function save(Request $request, Deal $deal, string $repair, RepairRequests $repairs): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $request->validate(['items' => ['present', 'array', 'max:80'], 'items.*.title' => ['required', 'string', 'max:160'], 'items.*.ask' => ['required', 'in:repair,credit,none'],
            'items.*.credit' => ['nullable', 'numeric', 'min:0'], 'items.*.note' => ['nullable', 'string', 'max:500']]);
        $items = array_values(array_filter((array) $request->input('items', []), 'is_array'));
        $repairs->save($deal, $repair, array_map(fn (array $i): array => array_merge($i, ['credit_cents' => (int) round((float) ($i['credit'] ?? 0) * 100)]), $items), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function draft(Request $request, Deal $deal, string $repair, RepairRequests $repairs): RedirectResponse
    {
        $packet = $repairs->draft($deal, $repair, self::authorise($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Repair amendment drafted on your form. Check it, then send it for signature.']);

        return back()->with('packet', $packet);
    }

    public function share(Request $request, Deal $deal, string $repair, RepairRequests $repairs): RedirectResponse
    {
        $repairs->share($deal, $repair, $request->boolean('share'), self::authorise($request, $deal));

        return back();
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && DealAccess::canSee($user, $deal), 403);

        return $user;
    }
}
