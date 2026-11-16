<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Portal\Concierge;
use Modules\Transactions\Portal\Copy;
use Modules\Transactions\Portal\PortalAccess;
use Modules\Transactions\Portal\SignIn;

/** The agent's side of the deal portal: who is in it, sharing documents with clients, and replying to questions. */
final class PortalAdminController
{
    public function invite(Request $request, Deal $deal, PortalAccess $portal): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['role' => ['required', Rule::in(DealAssistant::ROLES)], 'name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:254'],
            'locale' => ['required', Rule::in(array_keys(Copy::LOCALES))]]);
        $portal->invite($deal, $data['role'], $data['name'], $data['email'], $data['locale'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation sent to '.$data['name'].'.']);

        return back();
    }

    public function revoke(Request $request, Deal $deal, string $access, PortalAccess $portal): RedirectResponse
    {
        $portal->revoke($deal, $access, self::authorise($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed from the portal.']);

        return back();
    }

    public function signOutEverywhere(Request $request, Deal $deal, string $access, SignIn $signIn): RedirectResponse
    {
        self::authorise($request, $deal);
        $row = DB::connection(Connections::cell())->table('tx_portal_access')->where('deal_id', $deal->id)->where('ulid', $access)->first() ?? abort(404);
        $signIn->everywhere($row);
        app(AuditLogger::class)->record('transactions.portal.signed_out_everywhere', $deal, metadata: ['who' => AuditLogger::mask((string) $row->email)], label: $deal->street);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Signed out on every device. They can sign in again with a new link and code.']);

        return back();
    }

    public function share(Request $request, Deal $deal, string $document): RedirectResponse
    {
        self::authorise($request, $deal);
        $doc = Document::query()->where('ulid', $document)->where('deal_id', $deal->id)->firstOrFail();
        $on = $doc->getAttribute('shared_at') === null;
        $doc->forceFill(['shared_at' => $on ? now() : null])->save();
        app(AuditLogger::class)->record($on ? 'transactions.portal.document_shared' : 'transactions.portal.document_unshared', $deal, metadata: ['filename' => $doc->filename], label: $deal->street);
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'Shared with the clients in the portal.' : 'No longer shared.']);

        return back();
    }

    public function reply(Request $request, Deal $deal, string $question, Concierge $concierge): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['reply' => ['required', 'string', 'max:3000']]);
        $concierge->reply($deal, $question, $data['reply'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reply sent.']);

        return back();
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && DealAccess::canSee($user, $deal), 403);

        return $user;
    }
}
