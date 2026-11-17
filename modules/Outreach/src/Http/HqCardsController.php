<?php

declare(strict_types=1);

namespace Modules\Outreach\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Outreach\HandwrittenCards;
use Modules\Outreach\Models\CardRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HQ → Handwritten cards (MK2): the queue for the handwriting partner, as a CSV in its upload format, then marked mailed. */
#[CrossTenantAccess('HQ sends every brokerage\'s queued handwritten cards to the handwriting partner')]
final class HqCardsController
{
    public function index(): Response
    {
        $rows = CrossTenant::query(CardRequest::class, $this)->where('status', 'queued')->orderBy('id')->limit(1000)->get();

        return Inertia::render('hq/cards', ['queued' => $rows->map(fn (CardRequest $r): array => ['id' => $r->id, 'to' => $r->to_name, 'city' => $r->to_city.', '.$r->to_state,
            'from' => $r->from_name.' · '.$r->from_company, 'occasion' => HandwrittenCards::OCCASIONS[$r->occasion] ?? $r->occasion, 'message' => $r->message,
            'created_at' => (string) $r->created_at?->toDateString()])->values()]);
    }

    public function export(): StreamedResponse
    {
        $rows = CrossTenant::query(CardRequest::class, $this)->where('status', 'queued')->orderBy('id')->limit(1000)->get();
        app(AuditLogger::class)->record('hq.cards_exported', metadata: ['count' => $rows->count()], severity: 'notice');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['request_id', 'message', 'to_name', 'to_address1', 'to_address2', 'to_city', 'to_state', 'to_zip', 'from_name', 'from_company'], escape: '');
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn (?string $c): string => preg_match('/^[=+\-@\t\r]/', (string) $c) === 1 ? "'".$c : (string) $c,
                    [(string) $r->id, $r->message, $r->to_name, $r->to_line1, $r->to_line2, $r->to_city, $r->to_state, $r->to_zip, $r->from_name, $r->from_company]), escape: '');
            }
            fclose($out);
        }, 'handwritten-cards-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function sent(Request $request): RedirectResponse
    {
        $ids = array_map('intval', (array) $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['integer']])['ids']);
        $n = CrossTenant::query(CardRequest::class, $this)->whereIn('id', $ids)->where('status', 'queued')->update(['status' => 'sent', 'sent_at' => now()]);
        app(AuditLogger::class)->record('hq.cards_marked_sent', metadata: ['count' => $n], severity: 'notice');

        return back();
    }
}
