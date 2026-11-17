<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers\Hq;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\Evals\EvalRunner;
use Modules\AI\Evals\EvalSet;
use Modules\AI\Evals\Jobs\RunEvals;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/** HQ › AI quality (AQ1, specs/ai/08): each prompt's test set, its latest score, and what failed, in plain words. */
final class AiQualityController
{
    public function index(PromptRegistry $prompts): Response
    {
        $scores = EvalRunner::latestScores();
        $db = DB::connection(Connections::control());
        $latestRun = $db->table('ai_eval_runs')->orderByDesc('id')->first();
        $failures = $latestRun === null ? collect() : $db->table('ai_eval_results')->where('run_id', $latestRun->id)->where('passed', false)->get()->groupBy('prompt_key');
        $tested = [];
        $sets = [];
        foreach (EvalSet::all() as $set) {
            $tested[] = $set->prompt;
            $s = $scores[$set->prompt] ?? null;
            $version = $prompts->get($set->prompt)->version;
            $sets[] = [
                'prompt' => $set->prompt, 'feature' => $set->feature, 'cases' => count($set->cases), 'version' => $version,
                'score' => $s !== null && $s['cases'] > 0 ? (int) round(100 * $s['passed'] / $s['cases']) : null,
                'scored_version' => $s['version'] ?? null, 'scored_at' => $s['at'] ?? null,
                'failures' => array_values(($failures[$set->prompt] ?? collect())->map(fn ($r): array => ['case' => (string) $r->case_name,
                    'why' => (array) json_decode((string) $r->failures, true)])->all()),
            ];
        }

        return Inertia::render('hq/ai/quality', [
            'sets' => $sets,
            'untested' => array_values(array_diff(EvalSet::promptKeys(), $tested)),
            'runs' => $db->table('ai_eval_runs')->orderByDesc('id')->limit(10)->get()->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'status' => (string) $r->status,
                'cases' => (int) $r->cases, 'passed' => (int) $r->passed, 'cost_cents' => intdiv((int) $r->cost_microcents, 1_000_000), 'started_at' => (string) $r->started_at])->all(),
            'budget_cents' => (int) config('ai.eval_budget_cents', 300),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (DB::connection(Connections::control())->table('ai_eval_runs')->where('status', 'running')->where('started_at', '>', now()->subHour())->exists()) {
            return back()->withErrors(['run' => 'A test run is already going.']);
        }
        RunEvals::dispatch((int) config('ai.eval_budget_cents', 300), null, $user->id);
        app(AuditLogger::class)->record('hq.ai.evals_started', metadata: ['budget_cents' => (int) config('ai.eval_budget_cents', 300)], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Testing started. Results appear here in a few minutes.']);

        return back();
    }
}
