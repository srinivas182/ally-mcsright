<?php

declare(strict_types=1);

namespace Modules\Insights\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Insights\AskYourNumbers;
use Modules\Insights\Reports;
use Modules\Insights\SavedReports;

/** The report builder, saved reports and "Ask your numbers" (specs/insights/01, K2). */
final class ReportsController
{
    public function __construct(private readonly Reports $reports, private readonly SavedReports $saved) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $catalogue = [];
        foreach (Reports::subjects() as $key => $s) {
            $catalogue[$key] = ['label' => $s['label'], 'default' => $s['default'],
                'columns' => array_map(fn (array $c): array => ['label' => $c['label'], 'type' => $c['type'], 'options' => $c['options'] ?? [], 'filterable' => isset($c['column']) || isset($c['custom'])],
                    $this->reports->columns($key))];
        }

        return Inertia::render('insights/reports', ['catalogue' => $catalogue, 'ops' => Reports::OPS, 'saved' => $this->saved->visibleTo($user),
            'open' => $request->query('report')]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string'], 'columns' => ['nullable', 'array'], 'filters' => ['nullable', 'array'], 'group_by' => ['nullable', 'string']]);

        return response()->json($this->reports->run(self::user($request), $data));
    }

    public function export(Request $request): HttpResponse
    {
        $user = self::user($request);
        $data = $request->validate(['subject' => ['required', 'string'], 'columns' => ['nullable', 'array'], 'filters' => ['nullable', 'array'], 'name' => ['nullable', 'string', 'max:120']]);
        $result = $this->reports->run($user, $data);
        app(AuditLogger::class)->record('insights.report.exported', $user, metadata: ['subject' => $data['subject'], 'rows' => count($result['rows'])], severity: 'notice', label: (string) ($data['name'] ?? 'Report'));

        return response(SavedReports::csv($result), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.str((string) ($data['name'] ?? 'report'))->slug().'.csv"']);
    }

    public function save(Request $request, ?string $report = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'definition' => ['required', 'array'], 'shared' => ['nullable', 'boolean'], 'schedule' => ['nullable', 'in:weekly,monthly']]);
        $this->saved->save(self::user($request), $report, $data['name'], (array) $data['definition'], (bool) ($data['shared'] ?? false), $data['schedule'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Report saved.']);

        return back();
    }

    public function destroy(Request $request, string $report): RedirectResponse
    {
        $this->saved->delete(self::user($request), $report);

        return back();
    }

    public function ask(Request $request, AskYourNumbers $asker): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:500']]);
        $answer = $asker->ask($data['question'], self::user($request));

        return response()->json($answer ?? ['definition' => null, 'intro' => 'We could not answer that from your numbers. Try asking about deals, contacts, leads or agents.', 'result' => null]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
