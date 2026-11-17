<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Reading\Explainer;
use Modules\Transactions\Reading\VersionCompare;

/**
 * DocIQ on a deal: ask a document, download its safe copy, apply the facts it states (specs/ai/03); compare it
 * with the version it replaced, explain it in plain English or Spanish, and share a safe copy by link (specs/ai/07).
 */
final class DocumentReadingController
{
    public function ask(Request $request, Document $document, DocIq $docIq): RedirectResponse
    {
        $user = $this->authorise($request, $document);
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);

        return back()->with('docAnswer', ['document' => $document->ulid, 'question' => $data['question']] + $docIq->ask($document, $data['question'], $user));
    }

    public function safe(Request $request, Document $document, DocIq $docIq): Response
    {
        $this->authorise($request, $document);
        $pdf = $docIq->safeCopy($document);
        app(AuditLogger::class)->record('transactions.document.safe_copy', Deal::query()->find($document->deal_id), metadata: ['filename' => $document->filename]);
        $name = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $document->filename).' (safe copy).pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'"']);
    }

    public function compare(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);

        return back()->with('docCompare', ['document' => $document->ulid, 'filename' => $document->filename] + VersionCompare::compare($document));
    }

    public function explain(Request $request, Document $document, Explainer $explainer): RedirectResponse
    {
        $user = $this->authorise($request, $document);
        $data = $request->validate(['lang' => ['required', 'in:en,es']]);

        return back()->with('docExplain', ['document' => $document->ulid, 'filename' => $document->filename] + $explainer->explain($document, $data['lang'], $user));
    }

    /**
     * A link to the safe copy (sensitive numbers blacked out) for a lender, title company or client: it works for
     * the days chosen (7 at most), opens only the safe copy, and every open is in the audit log.
     */
    public function share(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:7'], 'to' => ['nullable', 'string', 'max:120']]);
        $until = CarbonImmutable::now()->addDays((int) $data['days']);
        $link = app(SpeedToLead::class)->link(URL::temporarySignedRoute('shared.document', $until, ['document' => $document->ulid], false));
        app(AuditLogger::class)->record('transactions.document.safe_shared', Deal::query()->find($document->deal_id),
            metadata: ['filename' => $document->filename, 'to' => $data['to'] ?? null, 'until' => $until->toDateString()]);

        return back()->with('docShare', ['document' => $document->ulid, 'link' => $link, 'until' => $until->toDateString()]);
    }

    /** The shared safe copy (no sign-in; the signed link is the key). Private, never cached or indexed. */
    public function shared(Request $request, string $document, DocIq $docIq): Response
    {
        abort_unless($request->hasValidSignature(false), 403, 'This link has expired. Please ask your agent for a new one.');
        $doc = Document::query()->where('ulid', $document)->firstOrFail();
        $pdf = $docIq->safeCopy($doc);
        app(AuditLogger::class)->record('transactions.document.safe_opened', Deal::query()->find($doc->deal_id), metadata: ['filename' => $doc->filename, 'ip' => $request->ip()]);
        $name = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $doc->filename).' (safe copy).pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.addcslashes($name, '"\\').'"',
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function apply(Request $request, Deal $deal, DocIq $docIq): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(DealController::canWork($user, $deal), 403);
        abort_if($deal->locked_at !== null, 403);
        $data = $request->validate(['facts' => ['required', 'array', 'min:1'], 'facts.*' => ['string', 'max:40']]);
        $n = $docIq->apply($deal, array_values($data['facts']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$n} detail(s) applied to the deal."]);

        return back();
    }

    private function authorise(Request $request, Document $document): User
    {
        $user = self::user($request);
        abort_unless(DealController::canWork($user, Deal::query()->findOrFail($document->deal_id)), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
