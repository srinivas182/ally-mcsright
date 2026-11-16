<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Connect\Models\Connection;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Sites\Reviews\Review;
use Modules\Sites\Reviews\Reviews;

/** Grow → Reviews (specs/connect/20, C8a): reviews from Google and RealSatisfied, replies, and hiding with a reason. */
final class ReviewsController
{
    public function __construct(private readonly Reviews $reviews) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $brokerage = $request->query('who') === 'brokerage' && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
        $shown = $this->reviews->shown($brokerage ? null : $user->id);
        $rows = Review::query()->where('user_id', $brokerage ? null : $user->id)->orderByDesc('reviewed_at')->limit(100)->get();

        return Inertia::render('reviews/index', [
            'brokerage' => $brokerage,
            'can_brokerage' => in_array($user->kind, [UserKind::Owner, UserKind::Staff], true),
            'can_hide' => $user->kind === UserKind::Owner,
            'summary' => ['count' => $shown['count'], 'sources' => $shown['sources']],
            'reasons' => Reviews::HIDE_REASONS,
            'connected' => Connection::query()->whereIn('app_key', ['google_business', 'realsatisfied'])
                ->where(fn ($q) => $brokerage ? $q->whereNull('owner_user_id') : $q->where('owner_user_id', $user->id))->pluck('app_key')->unique()->values()->all(),
            'reviews' => $rows->map(fn (Review $r): array => ['ulid' => $r->ulid, 'source' => Reviews::SOURCES[$r->source] ?? $r->source, 'can_reply' => $r->source === 'google',
                'author' => $r->author, 'rating' => $r->rating, 'text' => $r->text, 'date' => $r->reviewed_at->format('M j, Y'), 'reply' => $r->reply,
                'hidden' => $r->hidden, 'hidden_reason' => $r->hidden_reason !== null ? (Reviews::HIDE_REASONS[$r->hidden_reason] ?? $r->hidden_reason) : null])->values()->all(),
        ]);
    }

    public function reply(Request $request, string $review): RedirectResponse
    {
        $this->reviews->reply($this->find($review), self::user($request), (string) $request->input('reply', ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reply posted on Google.']);

        return back();
    }

    public function hide(Request $request, string $review): RedirectResponse
    {
        $this->reviews->hide($this->find($review), self::user($request), (string) $request->input('reason', ''));

        return back();
    }

    public function show(Request $request, string $review): RedirectResponse
    {
        $this->reviews->unhide($this->find($review), self::user($request));

        return back();
    }

    private function find(string $ulid): Review
    {
        return Review::query()->where('ulid', $ulid)->firstOrFail();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
