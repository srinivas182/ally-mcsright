<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Enums\Source;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\PlanStep;

final class PlanController
{
    public function __construct(private readonly FollowUpPlans $plans) {}

    public function index(Request $request, Pipelines $pipelines): Response
    {
        $user = self::user($request);

        return Inertia::render('crm/plans/index', [
            'plans' => $this->plans->plansFor($user)->map(fn (FollowUpPlan $p): array => [
                'ulid' => $p->ulid, 'name' => $p->name, 'description' => $p->description, 'trigger' => $p->trigger, 'trigger_value' => $p->trigger_value,
                'active' => $p->active, 'shared' => $p->shared, 'can_edit' => $this->plans->canEdit($p, $user),
                'enrolled' => PlanEnrollment::query()->where('plan_id', $p->id)->where('status', 'active')->count(),
                'steps' => $p->steps->map(fn (PlanStep $s): array => ['day' => $s->day, 'type' => $s->type, 'title' => $s->title, 'message' => $s->message])->values(),
            ])->values(),
            'sources' => array_map(fn (Source $s): string => $s->value, Source::cases()),
            'stages' => $pipelines->all()->flatMap(fn ($p) => $p->stages->map(fn ($s): array => ['ulid' => $s->ulid, 'name' => "{$p->name}: {$s->name}"]))->values(),
            'isManager' => CrmAccess::manager($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->plans->save(null, $this->validated($request), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan saved.']);

        return back();
    }

    public function update(Request $request, FollowUpPlan $plan): RedirectResponse
    {
        $this->plans->save($plan, $this->validated($request), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan saved.']);

        return back();
    }

    public function toggle(Request $request, FollowUpPlan $plan): RedirectResponse
    {
        abort_unless($this->plans->canEdit($plan, self::user($request)), 403);
        $plan->update(['active' => ! $plan->active]);

        return back();
    }

    public function enroll(Request $request, Contact $contact, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $contact), 404);
        $data = $request->validate(['plan' => ['required', 'string', 'size:26']]);
        $plan = FollowUpPlan::query()->where('ulid', $data['plan'])->firstOrFail();

        $this->plans->enroll($contact, $plan, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Started \"{$plan->name}\"."]);

        return back();
    }

    public function stop(Request $request, PlanEnrollment $enrollment, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $enrollment->contact), 404);
        $this->plans->stop($enrollment, 'Stopped by '.$user->first_name, $user->id);

        return back();
    }

    /**
     * @return array{name: string, description?: string|null, trigger?: string|null, trigger_value?: string|null, shared?: bool|null, steps: list<array{day: int, type: string, title: string, message?: string|null}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'trigger' => ['nullable', Rule::in(['manual', 'source', 'stage'])],
            'trigger_value' => ['nullable', 'string', 'max:40'],
            'shared' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*.day' => ['required', 'integer', 'min:0', 'max:365'],
            'steps.*.type' => ['required', Rule::in(FollowUpPlans::STEP_TYPES)],
            'steps.*.title' => ['required', 'string', 'max:200'],
            'steps.*.message' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var array{name: string, description?: string|null, trigger?: string|null, trigger_value?: string|null, shared?: bool|null, steps: list<array{day: int, type: string, title: string, message?: string|null}>} */
        return ['steps' => array_values(array_map(fn (array $s): array => ['day' => (int) $s['day'], 'type' => (string) $s['type'],
            'title' => (string) $s['title'], 'message' => $s['message'] ?? null], $data['steps']))] + $data;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
