<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Review\FileReview;

/** The broker's review copilot for one file (specs/ai/02, DQ6). */
final class FileReviewController
{
    public function show(Request $request, Deal $deal): Response
    {
        self::reviewer($request);

        return Inertia::render('deals/file-review', FileReview::page($deal));
    }

    public function brief(Request $request, Deal $deal, FileReview $review): RedirectResponse
    {
        $review->brief($deal, self::reviewer($request));

        return back();
    }

    public function approve(Request $request, Deal $deal, FileReview $review): RedirectResponse
    {
        $review->approve($deal, self::reviewer($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'File approved. The agent has been told.']);

        return back();
    }

    public function sendBack(Request $request, Deal $deal, FileReview $review): RedirectResponse
    {
        $user = self::reviewer($request);
        $review->sendBack($deal, (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent back to the agent with your note.']);

        return back();
    }

    private static function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && Can::manage($user, 'transactions.review'), 403);

        return $user;
    }
}
