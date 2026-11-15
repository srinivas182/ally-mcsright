<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Postcards\PostcardRun;
use Modules\Crm\Postcards\Postcards;
use Modules\Crm\SmartLists;

/** Grow → Postcards (specs/connect/18, C7c): make a mailing, see the price and the card, approve, cancel. */
final class PostcardsController
{
    public function index(Request $request, Postcards $postcards, SmartLists $lists): Response
    {
        $user = self::user($request);
        $last = PostcardRun::query()->where('user_id', $user->id)->latest('id')->first();

        return Inertia::render('grow/postcards', [
            'ready' => $postcards->ready(),
            'kinds' => Postcards::KINDS,
            'lists' => array_map(fn (array $l): array => ['key' => $l['key'], 'name' => $l['name'], 'count' => $l['count']], $lists->listsFor($user)),
            'from' => $last?->from,
            'prices' => $postcards->ready() ? ['4x6' => $postcards->creditsFor($postcards->centsPerCard('4x6')), '6x9' => $postcards->creditsFor($postcards->centsPerCard('6x9'))] : null,
            'runs' => PostcardRun::query()->where('user_id', $user->id)->latest('id')->limit(30)->get()->map(fn (PostcardRun $r): array => [
                'ulid' => $r->ulid, 'kind' => $r->kind, 'size' => $r->size, 'headline' => $r->headline, 'status' => $r->status, 'cards' => $r->cards, 'undeliverable' => $r->undeliverable,
                'credits' => $r->credits - $r->credits_refunded, 'send_date' => $r->send_date?->toDateString(), 'created' => $r->created_at->toIso8601String(),
                'cancellable' => $r->status === 'scheduled' && $r->send_date !== null && $r->send_date->isFuture()])->values()->all(),
        ]);
    }

    public function store(Request $request, Postcards $postcards): RedirectResponse
    {
        $run = $postcards->draft(self::user($request), $request->only(['kind', 'size', 'smart_list', 'listing', 'headline', 'message', 'link', 'from_name', 'from_line1', 'from_city',
            'from_state', 'from_zip']));

        return redirect('/postcards?run='.$run->ulid);
    }

    public function preview(Request $request, string $run, Postcards $postcards): HttpResponse
    {
        $r = self::run($request, $run);

        return response($postcards->html($r, $request->query('side') === 'back' ? 'back' : 'front', 'Jane'))->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex')->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    public function approve(Request $request, string $run, Postcards $postcards): RedirectResponse
    {
        $r = $postcards->approve(self::run($request, $run), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$r->cards} cards go to print on {$r->send_date?->format('M j')}."
            .($r->undeliverable > 0 ? " {$r->undeliverable} addresses couldn't be mailed; their credits are back." : '')]);

        return back();
    }

    public function cancel(Request $request, string $run, Postcards $postcards): RedirectResponse
    {
        $n = $postcards->cancel(self::run($request, $run), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Cancelled {$n} cards; the credits are back."]);

        return back();
    }

    private static function run(Request $request, string $ulid): PostcardRun
    {
        return PostcardRun::query()->where('ulid', $ulid)->where('user_id', self::user($request)->id)->firstOrFail();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
