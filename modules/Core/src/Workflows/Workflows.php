<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Can;
use Modules\Core\Access\Permissions;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The workflow engine (specs/core/06, W1): "when this happens, if these are true, do these steps".
 *
 * - Each save publishes a new version; a run always finishes on the version it started with.
 * - Steps are actions registered by modules, plus two the engine runs itself: wait, and ask for approval (one to
 *   three approvers; whoever started it can never approve).
 * - Every trigger is logged for 90 days, so a dry run can show what an automation would have done.
 * - Automations act on behalf of the person who switched them on, and every change they make is audited as
 *   automation. They can't start themselves in a loop (three levels deep at most).
 */
final class Workflows
{
    public const OPS = ['is' => 'is', 'is_not' => 'is not', 'over' => 'is over', 'under' => 'is under', 'contains' => 'contains', 'one_of' => 'is one of', 'filled' => 'is filled in', 'empty' => 'is empty'];

    public const APPROVER_PERMISSIONS = ['core.workflows.manage' => 'People who manage automations', 'transactions.cda.approve' => 'People who approve CDAs',
        'finance.payouts.approve' => 'People who approve payouts', 'transactions.review' => 'Deal reviewers'];

    public const MAX_STEPS = 20;

    private static int $depth = 0;

    public function __construct(private readonly WorkflowRegistry $registry, private readonly AuditLogger $audit) {}

    public static function manages(User $user): bool
    {
        return Can::manage($user, 'core.workflows.manage');
    }

    /**
     * Something happened: log it and start every automation that listens for it and whose conditions hold.
     *
     * @param  array<string, mixed>  $facts
     */
    public function fire(string $trigger, int $subjectId, array $facts = [], ?User $by = null): int
    {
        $def = $this->registry->findTrigger($trigger);
        if ($def === null || self::$depth >= 3) {
            return 0;
        }
        $db = DB::connection(Connections::cell());
        $tenantId = $by->tenant_id ?? app(TenantContext::class)->current()->id;
        $db->table('workflow_events')->insert(['tenant_id' => $tenantId, 'trigger' => $trigger, 'subject_type' => $def['subject'], 'subject_id' => $subjectId,
            'facts' => json_encode($facts), 'by_user_id' => $by?->id, 'at' => CarbonImmutable::now()]);

        $started = 0;
        $versions = $db->table('workflows as w')->join('workflow_versions as v', 'v.id', '=', 'w.version_id')->where('w.status', 'on')->whereNull('w.deleted_at')
            ->where('v.trigger', $trigger)->get(['w.id as workflow_id', 'v.id as version_id', 'v.conditions', 'v.steps']);
        foreach ($versions as $v) {
            $subject = $this->registry->resolve($def['subject'], $subjectId);
            if ($subject === null) {
                continue;
            }
            $all = $facts + $this->fieldFacts($subject);
            if (! $this->matches($this->decode($v->conditions), $all)) {
                continue;
            }
            $runId = $db->table('workflow_runs')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'workflow_id' => $v->workflow_id,
                'version_id' => $v->version_id, 'subject_type' => $subject->type, 'subject_id' => $subject->id, 'subject_label' => mb_substr($subject->label, 0, 200),
                'status' => 'running', 'step' => 0, 'facts' => json_encode($all + ['_by' => $by?->id]), 'started_at' => CarbonImmutable::now()]);
            $this->continue($runId);
            $started++;
        }

        return $started;
    }

    /** Runs a run's steps from where it is until it finishes, waits or fails. */
    public function continue(int $runId): void
    {
        $db = DB::connection(Connections::cell());
        $run = $db->table('workflow_runs')->where('id', $runId)->first();
        if ($run === null || ! in_array($run->status, ['running', 'waiting', 'approval'], true)) {
            return;
        }
        $version = $db->table('workflow_versions')->where('id', $run->version_id)->first();
        $workflow = $db->table('workflows')->where('id', $run->workflow_id)->first();
        $subject = $this->registry->resolve((string) $run->subject_type, (int) $run->subject_id);
        if ($version === null || $workflow === null || $subject === null) {
            $this->finish($runId, 'cancelled', 'The record or the automation no longer exists.');

            return;
        }
        $steps = $this->decode($version->steps);
        $facts = $this->decode($run->facts);
        $runner = $this->runner($workflow->created_by !== null ? (int) $workflow->created_by : null);
        $index = (int) $run->step;
        $db->table('workflow_runs')->where('id', $runId)->update(['status' => 'running', 'resume_at' => null]);

        self::$depth++;
        try {
            while ($index < count($steps)) {
                $step = (array) $steps[$index];
                $type = (string) ($step['type'] ?? '');
                $params = (array) ($step['params'] ?? []);
                if ($type === 'wait') {
                    $until = CarbonImmutable::now()->addDays((int) ($params['days'] ?? 0))->addHours((int) ($params['hours'] ?? 0));
                    if ($run->status !== 'waiting' || (int) $run->step !== $index) {
                        $this->log($runId, $index, 'wait', 'waiting', 'Waiting until '.$until->toDayDateTimeString());
                        $db->table('workflow_runs')->where('id', $runId)->update(['status' => 'waiting', 'step' => $index, 'resume_at' => $until]);

                        return;
                    }
                    $run->status = 'running';
                    $this->log($runId, $index, 'wait', 'done', 'Wait over.');
                    $index++;

                    continue;
                }
                if ($type === 'approval') {
                    $approval = $db->table('workflow_approvals')->where('run_id', $runId)->where('step', $index)->first();
                    if ($approval === null) {
                        $this->askApproval($runId, $index, $params, $subject, $facts, $workflow);
                        $db->table('workflow_runs')->where('id', $runId)->update(['status' => 'approval', 'step' => $index]);

                        return;
                    }
                    if ($approval->status === 'pending') {
                        $db->table('workflow_runs')->where('id', $runId)->update(['status' => 'approval', 'step' => $index]);

                        return;
                    }
                    if ($approval->status === 'rejected') {
                        $this->log($runId, $index, 'approval', 'done', 'Not approved, so the automation stopped here.');
                        $this->finish($runId, 'done');

                        return;
                    }
                    $this->log($runId, $index, 'approval', 'done', 'Approved.');
                    $index++;

                    continue;
                }
                $action = $this->registry->findAction($type);
                if ($action === null || ($action->subjects() !== [] && ! in_array($subject->type, $action->subjects(), true))) {
                    $this->log($runId, $index, $type, 'skipped', 'This step doesn\'t apply here.');
                    $index++;

                    continue;
                }
                try {
                    $note = $this->audit->asAutomation('workflow', $runner->id, (string) $workflow->name, fn (): string => $action->run($subject, $params, $facts, $runner));
                    $this->log($runId, $index, $type, 'done', $note);
                } catch (\Throwable $e) {
                    $message = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : 'Something went wrong in this step.';
                    if (! $e instanceof ValidationException) {
                        report($e);
                    }
                    $this->log($runId, $index, $type, 'failed', $message);
                    $db->table('workflow_runs')->where('id', $runId)->update(['status' => 'failed', 'step' => $index, 'error' => mb_substr($message, 0, 500)]);

                    return;
                }
                $index++;
                $db->table('workflow_runs')->where('id', $runId)->update(['step' => $index]);
            }
            $this->finish($runId, 'done');
        } finally {
            self::$depth--;
        }
    }

    /** Hourly and every few minutes: runs whose wait is over carry on. */
    public function resumeDue(): int
    {
        $n = 0;
        foreach (DB::connection(Connections::cell())->table('workflow_runs')->where('status', 'waiting')->where('resume_at', '<=', CarbonImmutable::now())->pluck('id') as $id) {
            $this->continue((int) $id);
            $n++;
        }
        DB::connection(Connections::cell())->table('workflow_events')->where('at', '<', CarbonImmutable::now()->subDays(90))->delete();

        return $n;
    }

    public function retry(string $runUlid, User $by): void
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $run = $db->table('workflow_runs')->where('ulid', $runUlid)->first() ?? abort(404);
        if ($run->status !== 'failed') {
            throw ValidationException::withMessages(['run' => 'Only a failed run can be retried.']);
        }
        $db->table('workflow_runs')->where('id', $run->id)->update(['status' => 'running', 'error' => null]);
        $this->continue((int) $run->id);
    }

    public function cancel(string $runUlid, User $by): void
    {
        $this->guard($by);
        $run = DB::connection(Connections::cell())->table('workflow_runs')->where('ulid', $runUlid)->first() ?? abort(404);
        if (in_array($run->status, ['running', 'waiting', 'approval', 'failed'], true)) {
            $this->finish((int) $run->id, 'cancelled', 'Stopped by '.$by->name.'.');
            DB::connection(Connections::cell())->table('workflow_approvals')->where('run_id', $run->id)->where('status', 'pending')->update(['status' => 'rejected', 'decided_at' => CarbonImmutable::now()]);
        }
    }

    /**
     * An approver's decision. Whoever set the run off can't approve it, and each person votes once.
     */
    public function decide(string $approvalUlid, User $by, bool $approve, ?string $comment = null): void
    {
        $db = DB::connection(Connections::cell());
        $a = $db->table('workflow_approvals')->where('ulid', $approvalUlid)->first() ?? abort(404);
        if ($a->status !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'This has already been decided.']);
        }
        if ($a->requested_by !== null && (int) $a->requested_by === $by->id) {
            throw ValidationException::withMessages(['approval' => 'You started this, so someone else has to approve it.']);
        }
        if (! $this->canApprove($by, (string) $a->permission)) {
            throw ValidationException::withMessages(['approval' => 'You are not one of the approvers for this.']);
        }
        if ($db->table('workflow_approval_votes')->where('approval_id', $a->id)->where('user_id', $by->id)->exists()) {
            throw ValidationException::withMessages(['approval' => 'You have already voted.']);
        }
        $db->table('workflow_approval_votes')->insert(['tenant_id' => $by->tenant_id, 'approval_id' => $a->id, 'user_id' => $by->id, 'decision' => $approve ? 'approve' : 'reject',
            'comment' => $comment !== null ? mb_substr($comment, 0, 300) : null, 'voted_at' => CarbonImmutable::now()]);
        $this->audit->record('core.workflow.approval_vote', $by, metadata: ['approval' => $approvalUlid, 'decision' => $approve ? 'approve' : 'reject', 'comment' => $comment], label: (string) $a->question);
        $yes = $db->table('workflow_approval_votes')->where('approval_id', $a->id)->where('decision', 'approve')->count();
        $status = ! $approve ? 'rejected' : ($yes >= (int) $a->needed ? 'approved' : null);
        if ($status !== null) {
            $db->table('workflow_approvals')->where('id', $a->id)->update(['status' => $status, 'decided_at' => CarbonImmutable::now()]);
            $this->continue((int) $a->run_id);
        }
    }

    public function canApprove(User $user, string $permission): bool
    {
        return $user->kind === UserKind::Owner || ($user->kind !== UserKind::Staff || app(Permissions::class)->governed($user)) && Can::manage($user, $permission);
    }

    /**
     * Pending approvals this person can decide.
     *
     * @return list<array{ulid: string, question: string, workflow: string, subject: string, needed: int, votes: int, asked_at: string}>
     */
    public function approvalsFor(User $user): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        foreach ($db->table('workflow_approvals as a')->join('workflow_runs as r', 'r.id', '=', 'a.run_id')->join('workflows as w', 'w.id', '=', 'r.workflow_id')
            ->where('a.status', 'pending')->orderBy('a.id')->get(['a.*', 'w.name as workflow', 'r.subject_label']) as $a) {
            if (! $this->canApprove($user, (string) $a->permission) || ($a->requested_by !== null && (int) $a->requested_by === $user->id)
                || $db->table('workflow_approval_votes')->where('approval_id', $a->id)->where('user_id', $user->id)->exists()) {
                continue;
            }
            $out[] = ['ulid' => (string) $a->ulid, 'question' => (string) $a->question, 'workflow' => (string) $a->workflow, 'subject' => (string) $a->subject_label,
                'needed' => (int) $a->needed, 'votes' => $db->table('workflow_approval_votes')->where('approval_id', $a->id)->where('decision', 'approve')->count(),
                'asked_at' => CarbonImmutable::parse((string) $a->created_at)->toIso8601String()];
        }

        return $out;
    }

    /**
     * Creates or updates an automation; every save publishes a new version.
     *
     * @param  array<mixed>  $conditions  [{fact, op, value}]
     * @param  array<mixed>  $steps  [{type, params}]
     */
    public function save(?string $ulid, string $name, ?string $description, string $trigger, array $conditions, array $steps, User $by, ?string $template = null): string
    {
        $this->guard($by);
        [$conditions, $steps] = $this->validate($trigger, $conditions, $steps);
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Give the automation a name.']);
        }
        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $ulid, $name, $description, $trigger, $conditions, $steps, $by, $template): string {
            $now = CarbonImmutable::now();
            if ($ulid === null) {
                $ulid = (string) Str::ulid();
                $id = $db->table('workflows')->insertGetId(['ulid' => $ulid, 'tenant_id' => $by->tenant_id, 'name' => mb_substr(trim($name), 0, 120),
                    'description' => $description !== null ? mb_substr($description, 0, 500) : null, 'status' => 'off', 'template_key' => $template,
                    'created_by' => $by->id, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) ($db->table('workflows')->where('ulid', $ulid)->whereNull('deleted_at')->value('id') ?? abort(404));
                $db->table('workflows')->where('id', $id)->update(['name' => mb_substr(trim($name), 0, 120), 'description' => $description !== null ? mb_substr($description, 0, 500) : null, 'updated_at' => $now]);
            }
            $version = (int) $db->table('workflow_versions')->where('workflow_id', $id)->max('version') + 1;
            $versionId = $db->table('workflow_versions')->insertGetId(['tenant_id' => $by->tenant_id, 'workflow_id' => $id, 'version' => $version, 'trigger' => $trigger,
                'conditions' => json_encode($conditions), 'steps' => json_encode($steps), 'published_by' => $by->id, 'published_at' => $now]);
            $db->table('workflows')->where('id', $id)->update(['version_id' => $versionId]);
            $this->audit->record('core.workflow.saved', $by, metadata: ['workflow' => $ulid, 'version' => $version, 'trigger' => $trigger, 'steps' => array_column($steps, 'type')], severity: 'warning', label: $name);

            return $ulid;
        });
    }

    public function switch(string $ulid, bool $on, User $by): void
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $w = $db->table('workflows')->where('ulid', $ulid)->whereNull('deleted_at')->first() ?? abort(404);
        // It acts as whoever switched it on.
        $db->table('workflows')->where('id', $w->id)->update(['status' => $on ? 'on' : 'off', 'created_by' => $on ? $by->id : $w->created_by, 'updated_at' => CarbonImmutable::now()]);
        $this->audit->record($on ? 'core.workflow.switched_on' : 'core.workflow.switched_off', $by, severity: 'warning', label: (string) $w->name);
    }

    public function delete(string $ulid, User $by): void
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $w = $db->table('workflows')->where('ulid', $ulid)->whereNull('deleted_at')->first() ?? abort(404);
        $db->table('workflows')->where('id', $w->id)->update(['status' => 'off', 'deleted_at' => CarbonImmutable::now()]);
        $this->audit->record('core.workflow.deleted', $by, severity: 'warning', label: (string) $w->name);
    }

    /**
     * What an automation would have done over the last N days, from the trigger log. Nothing is changed.
     *
     * @param  array<mixed>  $conditions
     * @param  array<mixed>  $steps
     * @return array{events: int, matched: int, examples: list<string>, steps: list<string>}
     */
    public function dryRun(string $trigger, array $conditions, array $steps, int $days = 30): array
    {
        [$conditions, $steps] = $this->validate($trigger, $conditions, $steps);
        $events = DB::connection(Connections::cell())->table('workflow_events')->where('trigger', $trigger)->where('at', '>=', CarbonImmutable::now()->subDays($days))->orderByDesc('id')->limit(2000)->get();
        $matched = 0;
        $examples = [];
        foreach ($events as $e) {
            $subject = $this->registry->resolve((string) $e->subject_type, (int) $e->subject_id);
            $facts = $this->decode($e->facts) + ($subject !== null ? $this->fieldFacts($subject) : []);
            if ($this->matches($conditions, $facts)) {
                $matched++;
                if (count($examples) < 5) {
                    $examples[] = ($subject->label ?? 'A record that no longer exists').' on '.CarbonImmutable::parse((string) $e->at)->toFormattedDateString();
                }
            }
        }

        return ['events' => $events->count(), 'matched' => $matched, 'examples' => $examples, 'steps' => array_map(fn (array $s): string => $this->describeStep($s), $steps)];
    }

    /**
     * @param  array<mixed>  $step
     */
    public function describeStep(array $step): string
    {
        $p = (array) ($step['params'] ?? []);
        $type = (string) ($step['type'] ?? '');

        return match ($type) {
            'wait' => 'Wait '.trim(((int) ($p['days'] ?? 0) > 0 ? ($p['days'].' '.Str::plural('day', (int) $p['days'])) : '').' '.((int) ($p['hours'] ?? 0) > 0 ? ($p['hours'].' '.Str::plural('hour', (int) $p['hours'])) : '')),
            'approval' => 'Ask for approval: "'.($p['question'] ?? '').'" ('.max(1, (int) ($p['needed'] ?? 1)).' of '.(self::APPROVER_PERMISSIONS[$p['permission'] ?? ''] ?? 'the approvers').')',
            default => $this->registry->findAction($type)?->describe($p) ?? $type,
        };
    }

    /**
     * @param  array<mixed>  $conditions
     */
    public function describeConditions(string $trigger, array $conditions): string
    {
        $facts = $this->registry->findTrigger($trigger)['facts'] ?? [];
        $parts = array_map(function (mixed $c) use ($facts): string {
            $c = (array) $c;
            $fact = (string) ($c['fact'] ?? '');
            $op = (string) ($c['op'] ?? '');
            $name = $facts[$fact] ?? (str_starts_with($fact, 'field.') ? Str::headline(substr($fact, 6)) : $fact);
            $value = is_array($c['value'] ?? null) ? implode(', ', $c['value']) : (string) ($c['value'] ?? '');

            return trim($name.' '.(self::OPS[$op] ?? $op).' '.(in_array($op, ['filled', 'empty'], true) ? '' : $value));
        }, array_values($conditions));

        return $parts === [] ? 'every time' : 'when '.implode(' and ', $parts);
    }

    /**
     * @param  array<mixed>  $conditions
     * @param  array<mixed>  $facts
     */
    public function matches(array $conditions, array $facts): bool
    {
        foreach ($conditions as $c) {
            $c = (array) $c;
            $have = $facts[(string) ($c['fact'] ?? '')] ?? null;
            $want = $c['value'] ?? null;
            $haveS = is_bool($have) ? ($have ? 'yes' : 'no') : (is_scalar($have) ? mb_strtolower((string) $have) : '');
            $ok = match ((string) ($c['op'] ?? '')) {
                'is' => $haveS === mb_strtolower((string) (is_bool($want) ? ($want ? 'yes' : 'no') : $want)),
                'is_not' => $haveS !== mb_strtolower((string) (is_bool($want) ? ($want ? 'yes' : 'no') : $want)),
                'over' => is_numeric($have) && is_numeric($want) && (float) $have > (float) $want,
                'under' => is_numeric($have) && is_numeric($want) && (float) $have < (float) $want,
                'contains' => $haveS !== '' && str_contains($haveS, mb_strtolower((string) $want)),
                'one_of' => in_array($haveS, array_map(fn ($v): string => mb_strtolower(trim((string) $v)), is_array($want) ? $want : explode(',', (string) $want)), true),
                'filled' => $haveS !== '',
                'empty' => $haveS === '',
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $conditions
     * @param  array<mixed>  $steps
     * @return array{0: list<array{fact: string, op: string, value: mixed}>, 1: list<array{type: string, params: array<string, mixed>}>}
     */
    private function validate(string $trigger, array $conditions, array $steps): array
    {
        $def = $this->registry->findTrigger($trigger) ?? throw ValidationException::withMessages(['trigger' => 'Choose what starts this automation.']);
        $cleanConditions = [];
        foreach (array_slice(array_values($conditions), 0, 10) as $c) {
            $c = (array) $c;
            if (! is_string($c['fact'] ?? null) || ! is_string($c['op'] ?? null) || ! array_key_exists($c['op'], self::OPS)) {
                throw ValidationException::withMessages(['conditions' => 'Each condition needs something to check and how.']);
            }
            if (! array_key_exists($c['fact'], $def['facts']) && ! str_starts_with($c['fact'], 'field.')) {
                throw ValidationException::withMessages(['conditions' => "\"{$c['fact']}\" isn't something this trigger knows."]);
            }
            $cleanConditions[] = ['fact' => $c['fact'], 'op' => $c['op'], 'value' => $c['value'] ?? null];
        }
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw ValidationException::withMessages(['steps' => 'Add between 1 and '.self::MAX_STEPS.' steps.']);
        }
        $clean = [];
        foreach (array_values($steps) as $i => $s) {
            $s = (array) $s;
            $type = (string) ($s['type'] ?? '');
            $params = (array) ($s['params'] ?? []);
            $n = $i + 1;
            if ($type === 'wait') {
                $days = (int) ($params['days'] ?? 0);
                $hours = (int) ($params['hours'] ?? 0);
                if ($days < 0 || $hours < 0 || $days > 365 || $hours > 23 || $days + $hours === 0) {
                    throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: wait between 1 hour and 365 days."]);
                }
                $clean[] = ['type' => 'wait', 'params' => ['days' => $days, 'hours' => $hours]];

                continue;
            }
            if ($type === 'approval') {
                $question = trim((string) ($params['question'] ?? ''));
                $needed = (int) ($params['needed'] ?? 1);
                $permission = (string) ($params['permission'] ?? 'core.workflows.manage');
                if ($question === '' || $needed < 1 || $needed > 3 || ! array_key_exists($permission, self::APPROVER_PERMISSIONS)) {
                    throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: say what to approve, who approves, and how many (1 to 3)."]);
                }
                $clean[] = ['type' => 'approval', 'params' => ['question' => mb_substr($question, 0, 300), 'needed' => $needed, 'permission' => $permission]];

                continue;
            }
            $action = $this->registry->findAction($type) ?? throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: choose what to do."]);
            if ($action->subjects() !== [] && ! in_array($def['subject'], $action->subjects(), true)) {
                throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: \"{$action->label()}\" doesn't work on ".Str::plural($this->registry->subjectLabel($def['subject'])).'.']);
            }
            $p = [];
            foreach ($action->params() as $key => $spec) {
                $value = $params[$key] ?? null;
                if (($spec['required'] ?? false) && ($value === null || $value === '')) {
                    throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: fill in {$spec['label']}."]);
                }
                if (isset($spec['options']) && $value !== null && $value !== '' && ! array_key_exists((string) $value, $spec['options'])) {
                    throw ValidationException::withMessages(["steps.{$i}" => "Step {$n}: choose one of the options for {$spec['label']}."]);
                }
                $p[$key] = is_string($value) ? mb_substr(trim($value), 0, 500) : $value;
            }
            $clean[] = ['type' => $type, 'params' => $p];
        }

        return [$cleanConditions, $clean];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $facts
     */
    private function askApproval(int $runId, int $index, array $params, Subject $subject, array $facts, \stdClass $workflow): void
    {
        $db = DB::connection(Connections::cell());
        $requester = isset($facts['_by']) ? (int) $facts['_by'] : null;
        $question = (string) ($params['question'] ?? 'Approve this?');
        $ulid = (string) Str::ulid();
        $db->table('workflow_approvals')->insert(['ulid' => $ulid, 'tenant_id' => $workflow->tenant_id, 'run_id' => $runId, 'step' => $index, 'question' => mb_substr($question, 0, 300),
            'needed' => max(1, min(3, (int) ($params['needed'] ?? 1))), 'permission' => (string) ($params['permission'] ?? 'core.workflows.manage'), 'requested_by' => $requester,
            'created_at' => CarbonImmutable::now()]);
        $this->log($runId, $index, 'approval', 'waiting', 'Waiting for approval: '.$question);
        foreach (User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff, UserKind::Agent])->where('status', 'active')->get() as $u) {
            if ($u->id !== $requester && $this->canApprove($u, (string) ($params['permission'] ?? 'core.workflows.manage'))) {
                $u->notify(new WorkflowApprovalMail($question, $subject->label.' ('.$workflow->name.')'));
            }
        }
    }

    private function log(int $runId, int $step, string $type, string $status, string $note): void
    {
        DB::connection(Connections::cell())->table('workflow_run_steps')->insert(['tenant_id' => DB::connection(Connections::cell())->table('workflow_runs')->where('id', $runId)->value('tenant_id'),
            'run_id' => $runId, 'step' => $step, 'type' => $type, 'status' => $status, 'note' => mb_substr($note, 0, 500), 'at' => CarbonImmutable::now()]);
    }

    private function finish(int $runId, string $status, ?string $note = null): void
    {
        DB::connection(Connections::cell())->table('workflow_runs')->where('id', $runId)->update(['status' => $status, 'finished_at' => CarbonImmutable::now(), 'resume_at' => null,
            'error' => $note !== null ? mb_substr($note, 0, 500) : null]);
    }

    /** The person an automation acts for: whoever switched it on, or an owner if they have left. */
    private function runner(?int $userId): User
    {
        $user = $userId !== null ? User::query()->find($userId) : null;
        if ($user instanceof User && $user->isActive()) {
            return $user;
        }

        return User::query()->where('kind', UserKind::Owner)->where('status', 'active')->orderBy('id')->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function fieldFacts(Subject $subject): array
    {
        if (! array_key_exists($subject->type, CustomFields::ENTITIES)) {
            return [];
        }
        $out = [];
        foreach (app(CustomFields::class)->raw($subject->type, $subject->id) as $key => $value) {
            $out['field.'.$key] = $value;
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $json): array
    {
        $v = is_string($json) ? json_decode($json, true) : $json;

        return is_array($v) ? $v : [];
    }

    private function guard(User $by): void
    {
        if (! self::manages($by)) {
            throw ValidationException::withMessages(['workflow' => 'Only owners, or a role with "Create and switch on automations", can change automations.']);
        }
    }
}
