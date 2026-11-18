<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Team\Help\Concierge;
use Modules\Team\Help\PageHelp;
use Modules\Team\Help\SupportTickets;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HQ → Support: every brokerage's help requests in one queue, answered by email (CH1). */
final class HqSupportController
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['open', 'answered', 'closed', 'all'], true) ? (string) $request->query('status') : 'open';

        return Inertia::render('hq/support', ['status' => $status, 'tickets' => SupportTickets::queue($status)]);
    }

    public function videos(): Response
    {
        return Inertia::render('hq/help-videos', ['videos' => PageHelp::videos(), 'pages' => array_keys(PageHelp::PAGES)]);
    }

    public function setVideo(Request $request): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:120'], 'title' => ['nullable', 'string', 'max:120'], 'url' => ['nullable', 'string', 'max:300']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        PageHelp::setVideo($data['path'], (string) ($data['title'] ?? ''), (string) ($data['url'] ?? ''), $user->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    /** SX6: the spreadsheet sent with a concierge setup request (audited). */
    public function file(Request $request, string $ticket): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Concierge::download($ticket, $user);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $data = $request->validate(['reply' => ['required', 'string', 'max:5000']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        SupportTickets::reply($ticket, $data['reply'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Answer sent.']);

        return back();
    }
}
