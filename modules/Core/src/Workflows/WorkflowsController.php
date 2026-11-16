<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Support\Connections;

/** Automations (specs/core/06, W1): the list, the builder, run history and approvals. */
final class WorkflowsController
{
    public function __construct(private readonly Workflows $workflows, private readonly WorkflowRegistry $registry) {}

    public function index(Request $request): Response
    {
        self::manager($request);
        $db = DB::connection(Connections::cell());
        $rows = $db->table('workflows as w')->leftJoin('workflow_versions as v', 'v.id', '=', 'w.version_id')->whereNull('w.deleted_at')->orderBy('w.name')
            ->get(['w.ulid', 'w.name', 'w.description', 'w.status', 'w.id', 'v.trigger', 'v.version', 'v.conditions', 'v.steps']);

        return Inertia::render('automations/index', [
            'automations' => $rows->map(fn ($w): array => [
                'ulid' => (string) $w->ulid, 'name' => (string) $w->name, 'description' => $w->description, 'on' => $w->status === 'on', 'version' => (int) $w->version,
                'summary' => 'When '.lcfirst($this->registry->findTrigger((string) $w->trigger)['label'] ?? (string) $w->trigger).', '
                    .$this->workflows->describeConditions((string) $w->trigger, (array) json_decode((string) $w->conditions, true)).': '
                    .implode('; ', array_map(fn (array $s): string => $this->workflows->describeStep($s), (array) json_decode((string) $w->steps, true))).'.',
                'runs' => $db->table('workflow_runs')->where('workflow_id', $w->id)->where('started_at', '>=', CarbonImmutable::now()->subDays(30))->count(),
                'failed' => $db->table('workflow_runs')->where('workflow_id', $w->id)->where('status', 'failed')->count(),
            ])->values(),
            'templates' => collect(WorkflowTemplates::all())->filter(fn (array $t): bool => $this->available($t))
                ->map(fn (array $t, string $k): array => ['key' => $k, 'name' => $t['name'], 'description' => $t['description']])->values(),
            'approvals' => count($this->workflows->approvalsFor(self::user($request))),
        ]);
    }

    public function edit(Request $request, ?string $workflow = null): Response
    {
        self::manager($request);
        $data = null;
        if ($workflow !== null) {
            $w = DB::connection(Connections::cell())->table('workflows as w')->join('workflow_versions as v', 'v.id', '=', 'w.version_id')->where('w.ulid', $workflow)
                ->whereNull('w.deleted_at')->first(['w.ulid', 'w.name', 'w.description', 'w.status', 'v.trigger', 'v.conditions', 'v.steps', 'v.version']) ?? abort(404);
            $data = ['ulid' => (string) $w->ulid, 'name' => (string) $w->name, 'description' => $w->description, 'on' => $w->status === 'on', 'version' => (int) $w->version,
                'trigger' => (string) $w->trigger, 'conditions' => json_decode((string) $w->conditions, true), 'steps' => json_decode((string) $w->steps, true)];
        }

        return Inertia::render('automations/edit', ['automation' => $data, 'catalogue' => $this->catalogue()]);
    }

    public function save(Request $request, ?string $workflow = null): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'trigger' => ['required', 'string', 'max:60'],
            'conditions' => ['nullable', 'array', 'max:10'], 'steps' => ['required', 'array', 'max:'.Workflows::MAX_STEPS]]);
        $ulid = $this->workflows->save($workflow, $data['name'], $data['description'] ?? null, $data['trigger'], array_values((array) ($data['conditions'] ?? [])), array_values((array) $data['steps']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Automation saved. Runs already under way finish on the version they started with.']);

        return redirect('/automations/'.$ulid);
    }

    public function switch(Request $request, string $workflow): RedirectResponse
    {
        $this->workflows->switch($workflow, $request->boolean('on'), self::manager($request));

        return back();
    }

    public function destroy(Request $request, string $workflow): RedirectResponse
    {
        $this->workflows->delete($workflow, self::manager($request));

        return redirect('/automations');
    }

    public function template(Request $request, string $key): RedirectResponse
    {
        $user = self::manager($request);
        $t = WorkflowTemplates::all()[$key] ?? abort(404);
        $ulid = $this->workflows->save(null, $t['name'], $t['description'], $t['trigger'], $t['conditions'], $t['steps'], $user, $key);

        return redirect('/automations/'.$ulid);
    }

    public function dryRun(Request $request): JsonResponse
    {
        self::manager($request);
        $data = $request->validate(['trigger' => ['required', 'string'], 'conditions' => ['nullable', 'array'], 'steps' => ['required', 'array']]);

        return response()->json($this->workflows->dryRun($data['trigger'], array_values((array) ($data['conditions'] ?? [])), array_values((array) $data['steps'])));
    }

    public function draft(Request $request, WorkflowDrafter $drafter): JsonResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        $draft = $drafter->draft($data['text'], ['triggers' => $this->catalogue()['triggers'], 'actions' => $this->catalogue()['actions']], $user);

        return response()->json(['draft' => $draft]);
    }

    public function runs(Request $request, string $workflow): Response
    {
        self::manager($request);
        $db = DB::connection(Connections::cell());
        $w = $db->table('workflows')->where('ulid', $workflow)->first() ?? abort(404);
        $runs = $db->table('workflow_runs')->where('workflow_id', $w->id)->orderByDesc('id')->limit(100)->get();
        $steps = $db->table('workflow_run_steps')->whereIn('run_id', $runs->pluck('id'))->orderBy('id')->get()->groupBy('run_id');

        return Inertia::render('automations/runs', [
            'automation' => ['ulid' => (string) $w->ulid, 'name' => (string) $w->name],
            'runs' => $runs->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'subject' => (string) $r->subject_label, 'status' => (string) $r->status, 'error' => $r->error,
                'started_at' => CarbonImmutable::parse((string) $r->started_at)->toIso8601String(), 'version' => (int) $db->table('workflow_versions')->where('id', $r->version_id)->value('version'),
                'resume_at' => $r->resume_at !== null ? CarbonImmutable::parse((string) $r->resume_at)->toIso8601String() : null,
                'steps' => ($steps[$r->id] ?? collect())->map(fn ($s): array => ['type' => (string) $s->type, 'status' => (string) $s->status, 'note' => (string) $s->note])->values()])->values(),
        ]);
    }

    public function retry(Request $request, string $run): RedirectResponse
    {
        $this->workflows->retry($run, self::manager($request));

        return back();
    }

    public function cancel(Request $request, string $run): RedirectResponse
    {
        $this->workflows->cancel($run, self::manager($request));

        return back();
    }

    public function approvals(Request $request): Response
    {
        return Inertia::render('automations/approvals', ['approvals' => $this->workflows->approvalsFor(self::user($request))]);
    }

    public function decide(Request $request, string $approval): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:300']]);
        $this->workflows->decide($approval, self::user($request), (bool) $data['approve'], $data['comment'] ?? null);

        return back();
    }

    /**
     * @return array{triggers: array<string, array{key: string, label: string, subject: string, facts: array<string, string>}>, actions: array<string, array{label: string, subjects: list<string>, params: array<string, mixed>}>, ops: array<string, string>, approvers: array<string, string>, fields: array<string, array<string, string>>}
     */
    private function catalogue(): array
    {
        $fields = [];
        foreach (array_keys(CustomFields::ENTITIES) as $entity) {
            foreach (app(CustomFields::class)->definitions($entity) as $f) {
                $fields[$entity]['field.'.$f['key']] = $f['label'];
            }
        }

        return [
            'triggers' => $this->registry->triggers(),
            'actions' => array_map(fn (WorkflowAction $a): array => ['label' => $a->label(), 'subjects' => $a->subjects(), 'params' => $a->params()], $this->registry->actions()),
            'ops' => Workflows::OPS,
            'approvers' => Workflows::APPROVER_PERMISSIONS,
            'fields' => $fields,
        ];
    }

    /**
     * @param  array{trigger: string, steps: list<array{type: string, params: array<string, mixed>}>}  $t
     */
    private function available(array $t): bool
    {
        if ($this->registry->findTrigger($t['trigger']) === null) {
            return false;
        }
        foreach ($t['steps'] as $s) {
            if (! in_array($s['type'], ['wait', 'approval'], true) && $this->registry->findAction($s['type']) === null) {
                return false;
            }
        }

        return true;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless(Workflows::manages($user), 403);

        return $user;
    }
}
