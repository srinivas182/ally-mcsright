<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Team\Help\SupportTickets;

/** HQ → Support: every brokerage's help requests in one queue, answered by email (CH1). */
final class HqSupportController
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['open', 'answered', 'closed', 'all'], true) ? (string) $request->query('status') : 'open';

        return Inertia::render('hq/support', ['status' => $status, 'tickets' => SupportTickets::queue($status)]);
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
