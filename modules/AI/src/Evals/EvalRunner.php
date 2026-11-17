<?php

declare(strict_types=1);

namespace Modules\AI\Evals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiRequest;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Runs the AI test sets (AQ1, specs/ai/08).
 *
 * - offline (every pull request, no model calls): each case is complete, every {{placeholder}} the prompt uses is
 *   supplied, and the reviewed example answer passes every check. This keeps the sets and the checks honest.
 * - live (weekly and on demand, HQ only, within a budget): each case goes through the real gateway (redaction,
 *   output policy, the feature's model chain) and the answer is checked. Scores are kept per prompt version.
 */
final class EvalRunner
{
    public function __construct(private readonly PromptRegistry $prompts, private readonly TenantContext $tenants) {}

    /** @return list<string> problems, empty when every set is sound */
    public function offline(EvalSet $set): array
    {
        $problems = [];
        try {
            $template = $this->prompts->get($set->prompt);
        } catch (Throwable $e) {
            return ["{$set->prompt}: {$e->getMessage()}"];
        }
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $template->body, $m);
        $needed = array_values(array_unique($m[1]));
        if (count($set->cases) < 3) {
            $problems[] = "{$set->prompt}: needs at least 3 cases.";
        }
        foreach ($set->cases as $case) {
            foreach (array_diff($needed, array_keys($case['inputs'])) as $missing) {
                $problems[] = "{$set->prompt} / {$case['name']}: no value for {{{$missing}}}.";
            }
            foreach (Checks::failures($case['golden'], self::source($case), $case['expect']) as $f) {
                $problems[] = "{$set->prompt} / {$case['name']}: the example answer fails: {$f}";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>|null  $only  prompt keys to run
     * @return array{ulid: string, status: string, cases: int, passed: int, cost_microcents: int}
     */
    public function live(Tenant $hq, int $budgetMicrocents, ?array $only = null, ?int $byUserId = null): array
    {
        $db = DB::connection(Connections::control());
        $ulid = (string) Str::ulid();
        $runId = $db->table('ai_eval_runs')->insertGetId(['ulid' => $ulid, 'status' => 'running', 'budget_microcents' => $budgetMicrocents,
            'requested_by' => $byUserId, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $spent = 0;
        $cases = 0;
        $passed = 0;
        $stopped = false;
        $this->tenants->runAs($hq, function () use ($db, $runId, $ulid, $budgetMicrocents, $only, &$spent, &$cases, &$passed, &$stopped): void {
            foreach (EvalSet::all() as $set) {
                if ($only !== null && ! in_array($set->prompt, $only, true)) {
                    continue;
                }
                if (! AiFeatureRoute::query()->where('feature_key', $set->feature)->exists()) {
                    $db->table('ai_eval_results')->insert(['run_id' => $runId, 'prompt_key' => $set->prompt, 'prompt_version' => '', 'case_name' => '*',
                        'passed' => false, 'failures' => json_encode(["No AI route for {$set->feature}."]), 'cost_microcents' => 0, 'latency_ms' => 0, 'created_at' => now()]);

                    continue;
                }
                $version = $this->prompts->get($set->prompt)->version;
                foreach ($set->cases as $case) {
                    if ($spent >= $budgetMicrocents) {
                        $stopped = true;

                        return;
                    }
                    $key = 'eval:'.$ulid.':'.$set->prompt.':'.$case['name'];
                    $failures = [];
                    $answer = '';
                    try {
                        $answer = app(AiGateway::class)->run(new AiTask($set->feature, $set->prompt, $key, inputs: $case['inputs'],
                            context: array_map(fn (array $r): RetrievedRecord => RetrievedRecord::shared($r['type'], $r['id'], $r['fields']), $case['context']),
                            untrustedContent: $case['untrusted'], outputSchema: $set->schema))->text;
                        $failures = Checks::failures($answer, self::source($case), $case['expect']);
                    } catch (Throwable $e) {
                        $failures = ['No answer: '.class_basename($e).'.'];
                    }
                    $requests = AiRequest::query()->where('idempotency_key', $key)->get();
                    $cost = (int) $requests->sum('cost_microcents');
                    $spent += $cost;
                    $cases++;
                    $passed += $failures === [] ? 1 : 0;
                    $db->table('ai_eval_results')->insert(['run_id' => $runId, 'prompt_key' => $set->prompt, 'prompt_version' => $version, 'case_name' => $case['name'],
                        'model_id' => $requests->where('status', 'ok')->last()?->model_id, 'passed' => $failures === [], 'failures' => json_encode($failures),
                        'answer_excerpt' => mb_substr($answer, 0, 500), 'cost_microcents' => $cost, 'latency_ms' => (int) $requests->sum('latency_ms'), 'created_at' => now()]);
                }
            }
        });
        $status = $stopped ? 'budget_stopped' : ($cases > 0 && $passed === $cases ? 'passed' : 'failures');
        $db->table('ai_eval_runs')->where('id', $runId)->update(['status' => $status, 'cases' => $cases, 'passed' => $passed, 'cost_microcents' => $spent,
            'finished_at' => CarbonImmutable::now(), 'updated_at' => now()]);

        return ['ulid' => $ulid, 'status' => $status, 'cases' => $cases, 'passed' => $passed, 'cost_microcents' => $spent];
    }

    /**
     * Score per prompt from the latest live run that tested it.
     *
     * @return array<string, array{version: string, cases: int, passed: int, at: string}>
     */
    public static function latestScores(): array
    {
        $out = [];
        $rows = DB::connection(Connections::control())->table('ai_eval_results')->join('ai_eval_runs', 'ai_eval_runs.id', '=', 'ai_eval_results.run_id')
            ->where('ai_eval_results.case_name', '!=', '*')->orderByDesc('ai_eval_runs.id')
            ->get(['ai_eval_results.prompt_key', 'ai_eval_results.prompt_version', 'ai_eval_results.passed', 'ai_eval_results.run_id', 'ai_eval_runs.started_at']);
        $seen = [];
        foreach ($rows as $r) {
            $k = (string) $r->prompt_key;
            if (isset($seen[$k]) && $seen[$k] !== (int) $r->run_id) {
                continue;
            }
            $seen[$k] = (int) $r->run_id;
            $out[$k] ??= ['version' => (string) $r->prompt_version, 'cases' => 0, 'passed' => 0, 'at' => (string) $r->started_at];
            $out[$k]['cases']++;
            $out[$k]['passed'] += (bool) $r->passed ? 1 : 0;
        }

        return $out;
    }

    /** @param  array{inputs: array<string, string>, context: list<array{type: string, id: string, fields: array<string, string>}>, untrusted: list<string>}  $case */
    public static function source(array $case): string
    {
        $parts = array_values($case['inputs']);
        foreach ($case['context'] as $r) {
            $parts[] = implode("\n", $r['fields']);
        }

        return implode("\n", array_merge($parts, $case['untrusted']));
    }
}
