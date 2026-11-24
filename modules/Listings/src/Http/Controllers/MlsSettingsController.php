<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Idx\BoardFees;
use Modules\Listings\Idx\BoardForm;
use Modules\Listings\Idx\BoardRequests;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\DemoListings;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Settings → MLS: the brokerage requests access to its MLSs and sees status, freshness and the rules that apply (never credentials). */
final class MlsSettingsController
{
    public function index(Request $request, TenantContext $tenants, MlsAccess $access): Response
    {
        self::owner($request);
        $mine = MlsAuthorization::query()->where('tenant_id', $tenants->id())->get()->keyBy('mls_source_id');
        $profiles = RulesProfile::query()->get()->keyBy('id');

        $boards = app(Subscriptions::class)->forTenant($tenants->id());
        $taken = array_column(array_filter($boards, fn (array $b): bool => $b['status'] !== 'cancelled'), 'board_code');
        $coverage = app(Coverage::class);

        return Inertia::render('settings/mls', [
            // IDX4: the brokerage's MLS boards and the boards it can add (open states, public, priced).
            'boards' => $boards,
            // IDX7: one board is included in the package; each extra board is added to the monthly invoice.
            'fees' => app(BoardFees::class)->current(),
            // IDX8: "My board isn't listed": the brokerage's own requests and the states it can pick.
            'requests' => app(BoardRequests::class)->forTenant($tenants->id()),
            'all_states' => collect($coverage->states())->pluck('name', 'code')->all(),
            'demo' => ['on' => app(DemoListings::class)->enabled($tenants->id()), 'showing' => app(DemoListings::class)->sourcesFor($tenants->id()) !== []],
            'available' => array_values(array_filter($coverage->offered($coverage->enabledStates()), fn (array $b): bool => ! in_array($b['code'], $taken, true))),
            'sources' => MlsSource::query()->whereIn('status', ['active', 'setup'])->where(fn ($q) => $q->whereNull('locked_tenant_id')->orWhere('locked_tenant_id', $tenants->id()))->orderBy('name')->get()->map(function (MlsSource $s) use ($mine, $profiles): array {
                $a = $mine->get($s->id);
                $p = $s->rules_profile_id !== null ? $profiles->get($s->rules_profile_id) : null;

                return ['ulid' => $s->ulid, 'name' => $s->name, 'states' => $s->states, 'minutes_behind' => $s->minutesBehind(),
                    'licence' => $a !== null ? ['status' => $a->status, 'broker_mls_id' => $a->broker_mls_id, 'scopes' => $a->scopes, 'expires_on' => $a->expires_on?->toDateString(),
                        'note' => $a->decision_note] : null,
                    'rules' => $p !== null ? ['program' => $p->program, 'sold_allowed' => $p->sold_allowed, 'sold_delay_days' => $p->sold_delay_days, 'attribution' => $p->attribution_template] : null];
            }),
        ]);
    }

    public function request(Request $request, MlsSource $source, TenantContext $tenants, MlsAccess $access): RedirectResponse
    {
        $user = self::owner($request);
        $data = $request->validate(['broker_mls_id' => ['required', 'string', 'max:30'], 'office_mls_ids' => ['nullable', 'string', 'max:300'], 'agreement_ref' => ['nullable', 'string', 'max:120']]);
        $access->request($tenants->id(), $source, $data['broker_mls_id'], preg_split('/[\s,]+/', (string) ($data['office_mls_ids'] ?? '')) ?: [], $data['agreement_ref'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request sent. RightAlly confirms access with the MLS, usually within two business days.']);

        return back();
    }

    public function addBoard(Request $request, TenantContext $tenants, Subscriptions $subs): RedirectResponse
    {
        $user = self::owner($request);
        $subs->add($tenants->id(), (string) $request->validate(['board' => ['required', 'string', 'size:26']])['board'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Board added. Demo listings show on your website until the MLS approves.']);

        return back();
    }

    /** IDX8: ask HQ to add a board that is not listed yet. */
    public function requestBoard(Request $request, TenantContext $tenants, BoardRequests $requests): RedirectResponse
    {
        $requests->ask($tenants->id(), $request->only(['name', 'state', 'website', 'note']), self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you. We will email you as soon as this board is ready.']);

        return back();
    }

    public function submitBoard(Request $request, TenantContext $tenants, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $subs->submit($tenants->id(), $subscription, $request->all(), self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you. Next: the agreement to sign. We will remind you if it waits.']);

        return back();
    }

    public function demo(Request $request, TenantContext $tenants, DemoListings $demo): RedirectResponse
    {
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $demo->set($tenants->id(), $on, self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'Demo listings on until your first board is live.' : 'Demo listings off.']);

        return back();
    }

    /** IDX6: the brokerage's copy of its signed MLS agreement. */
    public function copy(Request $request, TenantContext $tenants, string $subscription): StreamedResponse
    {
        self::owner($request);
        $path = DB::connection(Connections::control())->table('idx_subscriptions')->where('ulid', $subscription)->where('tenant_id', $tenants->id())->value('signed_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'IDX agreement.pdf');
    }

    /** IDX12: the board's form filled in (no signatures yet), to check before signing. */
    public function preview(Request $request, TenantContext $tenants, string $subscription): HttpResponse
    {
        self::owner($request);
        $db = DB::connection(Connections::control());
        $sub = $db->table('idx_subscriptions')->where('ulid', $subscription)->where('tenant_id', $tenants->id())->first();
        $board = $sub !== null ? $db->table('idx_boards')->where('id', $sub->board_id)->first() : null;
        $pdf = $sub !== null && $board !== null && BoardForm::inUse($board) ? BoardForm::preview($board, $sub) : null;
        abort_if($pdf === null, 404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="IDX agreement preview.pdf"', 'Cache-Control' => 'no-store']);
    }

    /** IDX12: save the brokerage's values (to preview), or save and send the form for signature. */
    public function review(Request $request, TenantContext $tenants, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $data = $request->validate(['values' => ['present', 'array', 'max:200'], 'values.*' => ['nullable', 'string', 'max:300'], 'send' => ['required', 'boolean']]);
        $subs->review($tenants->id(), $subscription, (array) $data['values'], self::owner($request), (bool) $data['send']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['send'] ? 'Sent for signature. Check your email to sign.' : 'Saved. Open the preview to check it.']);

        return back();
    }

    public function cancelBoard(Request $request, TenantContext $tenants, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $subs->cancel($subscription, $tenants->id(), self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Board removed. Its listings no longer show on your website.']);

        return back();
    }

    /** IDX13: email the signing link again. */
    public function resendSigning(Request $request, TenantContext $tenants, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $n = $subs->resendSigning($subscription, $tenants->id(), self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $n === 1 ? 'Sent. Check your inbox (and spam folder) for the signing email.' : 'Sent to everyone who signs next.']);

        return back();
    }

    /** IDX13: sign straight from here when it is the owner's turn. */
    public function signNow(Request $request, TenantContext $tenants, Subscriptions $subs, string $subscription): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::location($subs->signNow($tenants->id(), $subscription, self::owner($request)));
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
