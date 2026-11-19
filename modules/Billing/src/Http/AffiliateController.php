<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** RF1 (specs/billing/08): referral links, the brokerage's own page, a person's dashboard, and HQ's list. */
final class AffiliateController
{
    /** A referral link: counted, remembered for 60 days, then on to the prices and signup. */
    public function link(string $code, Affiliates $affiliates): RedirectResponse
    {
        $a = $affiliates->find($code);
        if ($a === null) {
            return redirect('/start');
        }
        $affiliates->click($a);

        return redirect('/start')->withCookie(cookie(Affiliates::COOKIE, (string) $a->code, Affiliates::COOKIE_DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }

    /** Settings › Refer a brokerage (owners). */
    public function brokerage(Request $request, Affiliates $affiliates, TenantContext $tenants): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);
        $a = $affiliates->forBrokerage($tenants->current(), (string) $user->email);

        return Inertia::render('settings/refer', [
            'link' => 'https://'.config('core.hq_host').'/r/'.$a->code,
            'stats' => $affiliates->stats($a, false),
            'share' => Affiliates::rewards()['brokerage'], 'hold_days' => Affiliates::HOLD_DAYS,
        ]);
    }

    /** A person's dashboard, from the private link HQ sent them. */
    public function dashboard(string $token, Affiliates $affiliates): HttpResponse
    {
        $a = $affiliates->byToken($token) ?? abort(404);

        return response()->view('billing::affiliate', ['affiliate' => $a, 'stats' => $affiliates->stats($a, false), 'link' => 'https://'.config('core.hq_host').'/r/'.$a->code,
            'share' => Affiliates::rewards()['person'], 'hold' => Affiliates::HOLD_DAYS])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    /** HQ › Referral programme. */
    public function hq(Request $request): Response
    {
        $db = DB::connection(Connections::control());
        $affiliates = $db->table('affiliates')->orderByDesc('id')->limit(200)->get();
        $refs = $db->table('affiliate_referrals')->orderByDesc('id')->limit(300)->get();
        $tenants = Tenant::query()->whereIn('id', $refs->pluck('tenant_id')->merge($affiliates->pluck('tenant_id'))->filter()->unique()->all())->pluck('name', 'id');
        $byId = $affiliates->keyBy('id');

        return Inertia::render('hq/affiliates', [
            'affiliates' => $affiliates->map(fn ($a): array => ['ulid' => (string) $a->ulid, 'kind' => (string) $a->kind, 'name' => (string) $a->name, 'code' => (string) $a->code,
                'joined' => $refs->where('affiliate_id', $a->id)->where('status', '!=', 'void')->count()])->values()->all(),
            'referrals' => $refs->map(fn ($r): array => ['id' => (int) $r->id, 'affiliate' => (string) ($byId[$r->affiliate_id]->name ?? ''), 'brokerage' => (string) ($tenants[$r->tenant_id] ?? ''),
                'status' => (string) $r->status, 'reward_cents' => (int) $r->reward_cents, 'reward_kind' => (string) $r->reward_kind, 'paid_cents' => (int) $r->paid_cents,
                'note' => $r->note, 'first_paid_at' => $r->first_paid_at !== null ? substr((string) $r->first_paid_at, 0, 10) : null])->values()->all(),
            'token' => $request->session()->get('affiliateToken'),
            'share' => Affiliates::rewards()['person'],
            'brokerageShare' => Affiliates::rewards()['brokerage'],
        ]);
    }

    public function addPerson(Request $request, Affiliates $affiliates): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:190']]);
        $made = $affiliates->addPerson($data['name'], $data['email'], $request->user()?->getAuthIdentifier() !== null ? (int) $request->user()->getAuthIdentifier() : null);

        return back()->with('affiliateToken', ['code' => $made['code'], 'link' => 'https://'.config('core.hq_host').'/r/'.$made['code'],
            'dashboard' => 'https://'.config('core.hq_host').'/affiliate/'.$made['token']]);
    }

    /** PB3: HQ sets what people and brokerages earn for a referral. */
    public function saveRewards(Request $request, Affiliates $affiliates): RedirectResponse
    {
        $data = $request->validate(['person' => ['required', 'integer', 'min:0', 'max:50'], 'brokerage' => ['required', 'integer', 'min:0', 'max:50']]);
        $id = $request->user()?->getAuthIdentifier();
        $affiliates->saveRewards((int) $data['person'], (int) $data['brokerage'], is_numeric($id) ? (int) $id : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Referral shares saved.']);

        return back();
    }

    public function markPaid(Request $request, int $referral, Affiliates $affiliates): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:255']])['note'];
        $id = $request->user()?->getAuthIdentifier();
        $affiliates->markPaid($referral, $note, is_numeric($id) ? (int) $id : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marked as paid.']);

        return back();
    }
}
