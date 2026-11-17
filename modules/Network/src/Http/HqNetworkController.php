<?php

declare(strict_types=1);

namespace Modules\Network\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Network\NetworkReferrals;

/** HQ › Referral network (AR2): how the network is used, and fee disputes to follow up. No client details. */
final class HqNetworkController
{
    public function index(): Response
    {
        $db = DB::connection(Connections::control());
        $refs = $db->table('network_referrals');
        $names = $db->table('network_profiles')->pluck('name', 'id');
        $brokerages = $db->table('network_profiles')->pluck('brokerage', 'id');

        return Inertia::render('hq/network', [
            'totals' => ['brokerages' => $db->table('network_brokerages')->where('enabled', true)->count(), 'agents' => $db->table('network_profiles')->where('status', 'active')->count(),
                'sent_30d' => $refs->clone()->where('created_at', '>=', now()->subDays(30))->count(), 'closed' => $refs->clone()->where('status', 'closed')->count(),
                'fees_confirmed_cents' => (int) $refs->clone()->whereNotNull('fee_paid_at')->sum('fee_due_cents')],
            'disputes' => $refs->clone()->whereNotNull('disputed_at')->orderByRaw('dispute_closed_at is not null')->orderByDesc('disputed_at')->limit(100)->get()
                ->map(fn ($r): array => ['id' => (int) $r->id, 'area' => (string) $r->area, 'from' => ($names[$r->from_profile_id] ?? '').' ('.($brokerages[$r->from_profile_id] ?? '').')',
                    'to' => ($names[$r->to_profile_id] ?? '').' ('.($brokerages[$r->to_profile_id] ?? '').')', 'fee_due_cents' => (int) $r->fee_due_cents, 'closed_on' => $r->closed_on,
                    'note' => $r->dispute_note, 'reply' => $r->dispute_reply, 'open' => $r->dispute_closed_at === null, 'reported' => substr((string) $r->disputed_at, 0, 10)])->all(),
        ]);
    }

    public function close(int $referral, NetworkReferrals $referrals): RedirectResponse
    {
        $referrals->closeDispute($referral);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispute closed as settled.']);

        return back();
    }
}
