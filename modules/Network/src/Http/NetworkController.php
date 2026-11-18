<?php

declare(strict_types=1);

namespace Modules\Network\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Network\NetworkReferrals;
use Modules\Network\Profiles;
use Modules\Network\TrustScore;
use Modules\Transactions\DealAccess;

/** The referral network pages (AR1, specs/network/01). */
final class NetworkController
{
    public function index(Request $request, Profiles $profiles, NetworkReferrals $referrals): Response
    {
        $user = self::user($request);
        $mine = $profiles->mine($user);
        $area = trim((string) $request->query('area', ''));
        $side = in_array($request->query('side'), ['buyer', 'seller'], true) ? (string) $request->query('side') : null;
        $language = trim((string) $request->query('language', ''));

        return Inertia::render('network/index', [
            'tab' => in_array($request->query('tab'), ['received', 'sent', 'find', 'profile'], true) ? (string) $request->query('tab') : 'received',
            'brokerage' => $profiles->brokerage(),
            'profile' => $mine !== null ? Profiles::card($mine) + ['licence' => $mine->licence, 'phone_raw' => $mine->phone, 'share_phone' => (bool) $mine->share_phone,
                'status' => (string) $mine->status, 'sides_key' => (string) $mine->sides, 'public_url' => 'https://'.config('core.hq_host').'/agents/'.$mine->code,
                'tips' => TrustScore::tips((array) json_decode((string) ($mine->trust_parts ?? '{}'), true), (int) $mine->trust_count)] : null,
            'referrals' => $referrals->forUser($user),
            'search' => ['area' => $area, 'side' => $side, 'language' => $language],
            'results' => $area !== '' ? array_values(array_map(fn ($p): array => Profiles::card($p),
                array_filter($profiles->search($area, $side, $language), fn ($p): bool => $mine === null || (int) $p->id !== (int) $mine->id))) : [],
            'deals' => DealAccess::query($user)->whereIn('status', ['pre_contract', 'under_contract'])->orderByDesc('id')->limit(50)->get(['ulid', 'street', 'status'])
                ->map(fn ($d): array => ['ulid' => (string) $d->ulid, 'label' => $d->street.($d->status === 'pre_contract' ? '' : ' (under contract)')])->all(),
            'sides' => Profiles::SIDES,
            'isOwner' => $user->kind === UserKind::Owner,
        ]);
    }

    public function saveProfile(Request $request, Profiles $profiles): RedirectResponse
    {
        $data = $request->validate([
            'areas' => ['required', 'array', 'max:12'], 'areas.*' => ['string', 'max:60'], 'sides' => ['required', 'in:buyers,sellers,both'],
            'languages' => ['array', 'max:6'], 'languages.*' => ['string', 'max:30'], 'state_code' => ['required', 'string', 'size:2'],
            'licence' => ['nullable', 'string', 'max:40'], 'bio' => ['nullable', 'string', 'max:500'], 'phone' => ['nullable', 'string', 'max:20'],
            'share_phone' => ['boolean'], 'public' => ['boolean'], 'paused' => ['boolean'],
        ]);
        $profiles->save(self::user($request), ['areas' => array_values(array_map('strval', (array) $data['areas'])), 'sides' => (string) $data['sides'],
            'languages' => array_values(array_map('strval', (array) ($data['languages'] ?? []))), 'state_code' => (string) $data['state_code'],
            'licence' => $data['licence'] ?? null, 'bio' => $data['bio'] ?? null, 'phone' => $data['phone'] ?? null,
            'share_phone' => (bool) ($data['share_phone'] ?? false), 'public' => (bool) ($data['public'] ?? false), 'paused' => (bool) ($data['paused'] ?? false)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your network profile is saved.']);

        return back();
    }

    public function send(Request $request, NetworkReferrals $referrals): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'size:26'], 'side' => ['required', 'in:buyer,seller'], 'area' => ['required', 'string', 'max:120'],
            'price_band' => ['nullable', 'string', 'max:40'], 'timing' => ['nullable', 'string', 'max:60'], 'language' => ['nullable', 'string', 'max:30'],
            'needs' => ['nullable', 'string', 'max:600'], 'fee_percent' => ['nullable', 'numeric'], 'client_agreed' => ['accepted'],
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['nullable', 'string', 'max:60'], 'email' => ['nullable', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'],
        ], ['client_agreed.accepted' => 'Tick that your client agreed to you passing on their details.']);
        $str = fn (string $k): ?string => isset($data[$k]) && $data[$k] !== '' ? (string) $data[$k] : null;
        $referrals->send(self::user($request), ['to' => (string) $data['to'], 'side' => (string) $data['side'], 'area' => (string) $data['area'], 'price_band' => $str('price_band'),
            'timing' => $str('timing'), 'language' => $str('language'), 'needs' => $str('needs'), 'fee_percent' => $str('fee_percent'), 'client_agreed' => true,
            'first_name' => (string) $data['first_name'], 'last_name' => $str('last_name'), 'email' => $str('email'), 'phone' => $str('phone')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Referral sent. You\'ll hear back within 72 hours.']);

        return redirect('/network?tab=sent');
    }

    public function accept(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Tick that you agree to the referral fee.']);
        $referrals->accept(self::user($request), $referral);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Accepted. The client is in your contacts.']);

        return back();
    }

    public function decline(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $referrals->decline(self::user($request), $referral, $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null);

        return back();
    }

    public function update(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,under_contract,closed,fell_through'], 'deal' => ['nullable', 'string', 'size:26'],
            'fee_due' => ['nullable', 'numeric', 'min:0'], 'closed_on' => ['nullable', 'date']]);
        $referrals->update(self::user($request), $referral, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Update sent to the referring agent.']);

        return back();
    }

    public function fee(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $referrals->fee(self::user($request), $referral, (string) $request->validate(['step' => ['required', 'in:sent,paid']])['step']);

        return back();
    }

    public function rate(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'note' => ['nullable', 'string', 'max:300']]);
        $referrals->rate(self::user($request), $referral, (int) $data['rating'], isset($data['note']) ? (string) $data['note'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks for the rating.']);

        return back();
    }

    public function dispute(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $referrals->dispute(self::user($request), $referral, (string) $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']])['note']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reported. The other agent and RightAlly have been told.']);

        return back();
    }

    public function disputeReply(Request $request, string $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $referrals->replyDispute(self::user($request), $referral, (string) $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']])['note']);

        return back();
    }

    /** Settings › Referral network (owner): switch it on, the usual fee, and licence checks. */
    public function settings(Request $request, Profiles $profiles, TenantContext $tenants): Response
    {
        abort_unless(self::user($request)->kind === UserKind::Owner, 403);

        return Inertia::render('network/settings', [
            'brokerage' => $profiles->brokerage(),
            'profiles' => DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $tenants->id())->orderBy('name')->get()
                ->map(fn ($p): array => ['ulid' => (string) $p->ulid, 'name' => (string) $p->name, 'licence' => $p->licence, 'state' => (string) $p->state_code,
                    'verified' => $p->verified_at !== null, 'status' => (string) $p->status])->all(),
        ]);
    }

    public function saveSettings(Request $request, Profiles $profiles): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $data = $request->validate(['enabled' => ['boolean'], 'default_fee_percent' => ['required', 'numeric']]);
        $profiles->saveBrokerage((bool) ($data['enabled'] ?? false), (string) $data['default_fee_percent'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function verify(Request $request, string $profile, Profiles $profiles): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($user->kind === UserKind::Owner, 403);
        $profiles->verify($profile, (bool) $request->validate(['checked' => ['boolean']])['checked'], $user);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
