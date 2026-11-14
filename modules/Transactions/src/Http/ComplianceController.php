<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Transactions\Compliance\Checks;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Compliance\Evidence;
use Modules\Transactions\Compliance\Findings;
use Modules\Transactions\Compliance\Rules;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/** Deal-IQ compliance scan: findings on a deal, the broker's checks and rules (specs/ai/02, Sprint 15B). */
final class ComplianceController
{
    public function scan(Request $request, Deal $deal, ComplianceScan $scan): RedirectResponse
    {
        $this->work($request, $deal);
        $r = $scan->run($deal);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Checked: {$r['score']}% broker-ready."]);

        return back();
    }

    public function dismiss(Request $request, Deal $deal, int $finding, Findings $findings): RedirectResponse
    {
        $user = $this->work($request, $deal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $findings->dismiss($deal, $finding, $data['reason'], $user, self::reviewer($user));

        return back();
    }

    public function submit(Request $request, Deal $deal, Findings $findings): RedirectResponse
    {
        $findings->submit($deal, $this->work($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'File sent to the broker for review.']);

        return back();
    }

    public function approveRule(Request $request, Deal $deal, string $rule, Rules $rules, ComplianceScan $scan): RedirectResponse
    {
        $user = $this->work($request, $deal);
        abort_unless(self::reviewer($user), 403);
        $rules->approve($deal, $rule, $user);
        $scan->run($deal);

        return back();
    }

    public function evidence(Request $request, Document $document): HttpResponse
    {
        $this->work($request, Deal::query()->findOrFail($document->deal_id));
        $data = $request->validate(['page' => ['required', 'integer', 'min:1', 'max:999'], 'quote' => ['nullable', 'string', 'max:200']]);

        return response(Evidence::page($document, (int) $data['page'], (string) ($data['quote'] ?? '')), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="evidence-page-'.(int) $data['page'].'.pdf"']);
    }

    /** The broker's compliance settings: checks with their accuracy, and plain-English rules. */
    public function settings(Request $request): Response
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        $accuracy = Findings::accuracy();
        $db = DB::connection(Connections::cell());

        return Inertia::render('deals/compliance', [
            'checks' => collect(Checks::settings())->map(fn (array $s, string $code): array => ['code' => $code, 'label' => Checks::CATALOG[$code][0],
                'default' => Checks::CATALOG[$code][1], 'enabled' => $s['enabled'], 'severity' => $s['severity'],
                'stats' => $accuracy[$code] ?? ['fixed' => 0, 'dismissed' => 0, 'open' => 0, 'accuracy' => null, 'review' => false]])->values(),
            'rules' => $db->table('tx_compliance_rules')->orderByDesc('id')->get(['ulid', 'text', 'summary', 'active', 'confirmed_at']),
            'rule_stats' => $accuracy['rule'] ?? null,
            'submitted' => $db->table('tx_deals')->whereNotNull('file_submitted_at')->whereNull('closed_on')->orderBy('file_submitted_at')->limit(50)
                ->get(['ulid', 'street', 'compliance_score', 'file_submitted_at']),
            'at_risk' => $db->table('tx_deals')->whereIn('status', ['pre_contract', 'under_contract'])->where('compliance_score', '<', 100)->orderBy('compliance_score')->limit(50)
                ->get(['ulid', 'street', 'status', 'compliance_score']),
        ]);
    }

    public function saveCheck(Request $request, string $code, Findings $findings): RedirectResponse
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'severity' => ['nullable', 'string']]);
        $findings->saveCheck($code, (bool) $data['enabled'], $data['severity'] ?? null);

        return back();
    }

    public function draftRule(Request $request, Rules $rules): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::reviewer($user), 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $rules->draft($data['text'], $user);

        return back();
    }

    public function confirmRule(Request $request, string $rule, Rules $rules, ComplianceScan $scan): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::reviewer($user), 403);
        $rules->confirm($rule, $user);
        // Apply the new rule to every open file now.
        foreach (Deal::query()->whereIn('status', ['pre_contract', 'under_contract'])->cursor() as $deal) {
            $scan->run($deal);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rule on. Every open file has been checked against it.']);

        return back();
    }

    public function deactivateRule(Request $request, string $rule, Rules $rules): RedirectResponse
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        $rules->deactivate($rule);

        return back();
    }

    private function work(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(DealController::canWork($user, $deal), 403);

        return $user;
    }

    private static function reviewer(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || $user->can('transactions.review');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
