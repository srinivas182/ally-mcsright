<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Review\AdCheck;
use Modules\Ads\Review\MarketingReviews;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Grow → Marketing check (MK3, specs/ads/06): check, fix, send for approval; reviewers approve or send back. */
final class MarketingCheckController
{
    public function index(Request $request, MarketingReviews $reviews): Response
    {
        $user = self::user($request);
        $reviewer = MarketingReviews::reviewer($user);

        return Inertia::render('ads/marketing-check', [
            'kinds' => AdCheck::KINDS, 'result' => $request->session()->get('marketingCheck'), 'mine' => $reviews->list($user, false),
            'queue' => $reviewer ? $reviews->list($user, true) : [], 'reviewer' => $reviewer, 'approval_required' => $reviews->approvalRequired(),
        ]);
    }

    public function check(Request $request, AdCheck $check, MarketingReviews $reviews): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'kind' => ['required', 'string'], 'mls_number' => ['nullable', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:120'], 'rewrite' => ['nullable', 'boolean']]);
        $kind = isset(AdCheck::KINDS[$data['kind']]) ? $data['kind'] : 'ad';
        $result = $check->run($data['body'], $kind, $data['mls_number'] ?? null);
        $body = $data['body'];
        if ($request->boolean('rewrite')) {
            $body = $reviews->rewrite($data['body'], $kind, $result['findings'], $user);
            $result = $check->run($body, $kind, $data['mls_number'] ?? null) + ['rewritten' => true];
        }
        $request->session()->flash('marketingCheck', $result + ['input' => ['body' => $body, 'kind' => $kind, 'mls_number' => $data['mls_number'] ?? '', 'title' => $data['title'] ?? '']]);

        return back();
    }

    public function submit(Request $request, MarketingReviews $reviews): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'kind' => ['required', 'string'], 'mls_number' => ['nullable', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:120'], 'file' => ['nullable', 'file', 'max:20480']]);
        $reviews->submit(self::user($request), $data['kind'], (string) ($data['title'] ?? ''), $data['body'], $data['mls_number'] ?? null, $request->file('file'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your broker for approval.']);

        return back();
    }

    public function decide(Request $request, MarketingReviews $reviews, string $review): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:1000']]);
        $reviews->decide($review, $data['status'], $data['note'] ?? null, self::user($request));

        return back();
    }

    public function settings(Request $request, MarketingReviews $reviews): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(MarketingReviews::reviewer($user), 403);
        $reviews->setApprovalRequired($request->boolean('approval_required'), $user);

        return back();
    }

    public function file(Request $request, MarketingReviews $reviews, string $review): StreamedResponse
    {
        $file = $reviews->file($review, self::user($request)) ?? abort(404);

        return Storage::disk('local')->response($file['path'], null, ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
