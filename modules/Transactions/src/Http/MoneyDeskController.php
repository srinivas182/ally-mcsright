<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Transactions\Cdas;
use Modules\Transactions\EarnestMoney;
use Modules\Transactions\FileExport;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Referrals;
use Modules\Transactions\Reviews;
use Modules\Transactions\TitleDesk;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Transactions core part 2: earnest money, referrals, CDAs, bulk review, file export, and the
 * Settings › Transactions page (title companies, receiving account, referral fees paid).
 */
final class MoneyDeskController
{
    public function expectDeposit(Request $request, Deal $deal, EarnestMoney $money): RedirectResponse
    {
        $user = $this->forDeal($request, $deal);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'held_by' => ['required', 'in:trust,title,other'], 'held_by_name' => ['nullable', 'string', 'max:160'], 'due_on' => ['nullable', 'date']]);
        $money->expect($deal, (int) round((float) $data['amount'] * 100), $data['held_by'], $data['held_by_name'] ?? null, isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null, $user);

        return back();
    }

    public function receiveDeposit(Request $request, string $deposit, EarnestMoney $money): RedirectResponse
    {
        $data = $request->validate(['received_on' => ['required', 'date', 'before_or_equal:today'], 'method' => ['required', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:60']]);
        $money->receive($deposit, CarbonImmutable::parse($data['received_on']), $data['method'], $data['reference'] ?? null, $this->trust($request));

        return back();
    }

    public function releaseDeposit(Request $request, string $deposit, EarnestMoney $money): RedirectResponse
    {
        $data = $request->validate(['refund' => ['required', 'boolean'], 'to' => ['required', 'string', 'max:160'], 'on' => ['required', 'date']]);
        $money->release($deposit, (bool) $data['refund'], $data['to'], CarbonImmutable::parse($data['on']), $this->trust($request));

        return back();
    }

    public function referral(Request $request, Deal $deal, Referrals $referrals): RedirectResponse
    {
        $this->forDeal($request, $deal);
        $data = $request->validate(['direction' => ['required', 'in:in,out'], 'brokerage' => ['required', 'string', 'max:160'], 'agent' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email'], 'percent' => ['required', 'numeric', 'min:0.01', 'max:100']]);
        $referrals->add($deal, $data['direction'], $data['brokerage'], $data['agent'], $data['email'] ?? null, (string) $data['percent'], null);

        return back();
    }

    public function generateCda(Request $request, Deal $deal, Cdas $cdas): RedirectResponse
    {
        $cdas->generate($deal, $this->reviewer($request));

        return back();
    }

    public function approveCda(Request $request, string $cda, Cdas $cdas): RedirectResponse
    {
        $cdas->approve($cda, self::user($request));

        return back();
    }

    public function sendCda(Request $request, string $cda, Cdas $cdas): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'size:26']]);
        $cdas->send($cda, $data['title'], $this->reviewer($request), $request->getSchemeAndHttpHost());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'CDA sent to the title company.']);

        return back();
    }

    public function bulkApprove(Request $request, Reviews $reviews): RedirectResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:200'], 'items.*' => ['string', 'size:26']]);
        $count = $reviews->bulkApprove(array_values($data['items']), $this->reviewer($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} item(s) approved."]);

        return back();
    }

    public function export(Request $request, Deal $deal, FileExport $export): BinaryFileResponse
    {
        $user = self::user($request);
        abort_unless($user->isKind(UserKind::Owner) || $user->can('transactions.export'), 403);

        return response()->download($export->build($deal, $user), 'deal-'.$deal->ulid.'.zip')->deleteFileAfterSend();
    }

    public function settings(Request $request, TitleDesk $desk, Referrals $referrals): Response
    {
        $this->reviewer($request);
        $year = (int) $request->query('year', (string) (now()->year - (now()->month <= 2 ? 1 : 0)));

        return Inertia::render('settings/transactions', [
            'titles' => DB::connection(Connections::cell())->table('tx_title_companies')->orderBy('name')->get()
                ->map(fn ($t): array => ['ulid' => $t->ulid, 'name' => $t->name, 'email' => $t->email, 'phone' => $t->phone, 'verified_at' => $t->verified_at, 'note' => $t->verification_note]),
            'wire' => $desk->wire(),
            'canChangeWire' => self::user($request)->can('finance.bank_details.manage'),
            'year' => $year,
            'referralsPaid' => $referrals->paidInYear($year),
        ]);
    }

    public function addTitle(Request $request, TitleDesk $desk): RedirectResponse
    {
        $this->reviewer($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email'], 'phone' => ['required', 'string', 'max:30']]);
        $desk->addCompany($data['name'], $data['email'], $data['phone']);

        return back();
    }

    public function verifyTitle(Request $request, string $title, TitleDesk $desk): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $desk->verifyCompany($title, $data['note'], $this->reviewer($request));

        return back();
    }

    public function proposeWire(Request $request, TitleDesk $desk): RedirectResponse
    {
        $data = $request->validate(['bank' => ['required', 'string', 'max:120'], 'last4' => ['required', 'digits:4']]);
        $desk->proposeWire($data['bank'], $data['last4'], self::user($request));

        return back();
    }

    public function confirmWire(Request $request, TitleDesk $desk): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $desk->confirmWire($data['note'], self::user($request));

        return back();
    }

    /** Public: the title officer's CDA (no sign-in; secret link). */
    public function viewCda(string $token, Cdas $cdas): Response
    {
        $cda = $cdas->byToken($token);
        abort_if($cda === null, 404);

        return Inertia::render('public/cda', ['cda' => $cda, 'token' => $token]);
    }

    public function acknowledgeCda(Request $request, string $token, Cdas $cdas): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        abort_unless($cdas->acknowledge($token, $data['name']), 404);

        return back();
    }

    private function forDeal(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        $acting = $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? $user->assisted_user_id : $user->id;
        abort_unless(self::isReviewer($user) || $deal->agent_user_id === $acting, 403);
        abort_if($deal->locked_at !== null, 422, 'This deal is closed and locked.');

        return $user;
    }

    private function reviewer(Request $request): User
    {
        $user = self::user($request);
        abort_unless(self::isReviewer($user), 403);

        return $user;
    }

    private function trust(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->isKind(UserKind::Owner) || $user->can('transactions.trust.manage'), 403);

        return $user;
    }

    private static function isReviewer(User $user): bool
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
