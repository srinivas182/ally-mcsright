<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Modules\Core\Audit\AuditLogger;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Reading\DocIq;

/** DocIQ on a deal: ask a document, download its safe copy, apply the facts it states (specs/ai/03). */
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
