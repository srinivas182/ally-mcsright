<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Learning\TrainingGate;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\PlanStep;
use Modules\Crm\Models\Task;

/**
 * Follow-up plans: steps ("Day 0 · Call · Introduce yourself") that arrive as tasks on schedule,
 * one at a time. Plans stop when the person engages or the deal moves on. Text steps only for
 * contacts with texting consent; templates are checked for Fair Housing wording.
 */
final class FollowUpPlans
{
    public const STEP_TYPES = ['call', 'text', 'email', 'task'];

    private const STOP_STAGES = [Lifecycle::UnderContract, Lifecycle::PastClient, Lifecycle::Archived];

    /**
     * Ready-made plans (SO2), by key: name, description, steps [day, type, title, message]. Every RightAlly gets each
     * one once; a plan someone switched off stays off. Spanish versions are for people who prefer Spanish.
     */
    public const TEMPLATES = [
        'buyer_lead' => ['New online lead (10 days)', 'Fast, steady follow-up for website and portal leads.', [
            [0, 'call', 'Call to introduce yourself', null],
            [0, 'text', 'Send an intro text', 'Hi {first_name}, it\'s {agent_first_name}. Thanks for reaching out about homes! What area and price range are you thinking about?'],
            [1, 'call', 'Second call attempt', null],
            [3, 'email', 'Email a few matching homes', 'Hi {first_name}, here are a few homes I think you\'ll like. Want to see any of them this week? — {agent_first_name}'],
            [5, 'call', 'Third call attempt', null],
            [7, 'text', 'Check-in text', 'Hi {first_name}, still looking? I can set up a search that sends new homes the day they list. — {agent_first_name}'],
            [10, 'email', 'Offer a short planning call', 'Hi {first_name}, would a 10-minute call help to plan your next steps? — {agent_first_name}'],
        ]],
        'seller_lead' => ['New seller lead (14 days)', 'For owners who asked what their home is worth or want to sell.', [
            [0, 'call', 'Call: thank them, ask about timing and plans', null],
            [0, 'email', 'Send the home value and what affects it', 'Hi {first_name}, thanks for asking about your home\'s value. Here is what similar homes nearby sold for, and what could move the number. — {agent_first_name}'],
            [2, 'call', 'Offer a free walk-through for an exact price', null],
            [5, 'text', 'Quick check-in', 'Hi {first_name}, happy to walk through your home and give you an exact price, no obligation. Would this week work? — {agent_first_name}'],
            [9, 'email', 'Share how you would sell it', 'Hi {first_name}, here is how I would market your home: pricing, photos, and the first two weeks. — {agent_first_name}'],
            [14, 'call', 'Follow-up call', null],
        ]],
        'open_house' => ['Open house follow-up', 'Thank visitors and find out what they need.', [
            [0, 'text', 'Thank them for visiting', 'Hi {first_name}, thanks for stopping by the open house today! Any questions about the home? — {agent_first_name}'],
            [2, 'call', 'Call: what did they think, what are they looking for?', null],
            [7, 'email', 'Send similar homes', 'Hi {first_name}, here are a few homes similar to the one you visited. — {agent_first_name}'],
        ]],
        'sphere' => ['Sphere check-in (quarterly)', 'Stay in touch with friends and past contacts.', [
            [0, 'call', 'Catch-up call', null],
            [90, 'email', 'Share a local market update', 'Hi {first_name}, here\'s what\'s happening in the local market this quarter. — {agent_first_name}'],
            [180, 'call', 'Catch-up call', null],
            [270, 'email', 'Share a local market update', 'Hi {first_name}, here\'s the latest on home values near you. — {agent_first_name}'],
        ]],
        'past_client' => ['Past client — first year', 'Look after new homeowners and earn referrals.', [
            [7, 'call', 'How is the new home?', null],
            [30, 'email', 'Helpful homeowner tips', 'Hi {first_name}, a few tips for your first months in the new home. — {agent_first_name}'],
            [180, 'call', 'Six-month check-in', null],
            [365, 'text', 'Happy home anniversary!', 'Happy one-year home anniversary, {first_name}! If friends are thinking of moving, I\'d love to help. — {agent_first_name}'],
        ]],
        'expired_fsbo' => ['Expired or for-sale-by-owner listing', 'Calls and letters for owners whose listing expired or who sell on their own. Calls only to numbers not on Do Not Call.', [
            [0, 'call', 'Call: ask what they think held the sale back', null],
            [1, 'task', 'Mail a short letter with your plan for their home', null],
            [3, 'call', 'Second call', null],
            [10, 'call', 'Third call: offer a free pricing review', null],
        ]],
        'not_ready' => ['Not ready yet (monthly)', 'For people who are a year or more away: a monthly touch, so you are the one they call.', [
            [0, 'email', 'Monthly market note', 'Hi {first_name}, here\'s a quick look at the market near you this month. No rush; I\'m here when you\'re ready. — {agent_first_name}'],
            [30, 'email', 'Monthly market note', 'Hi {first_name}, a few homes that sold near you this month, and what they went for. — {agent_first_name}'],
            [60, 'call', 'Friendly check-in call', null],
            [90, 'email', 'Monthly market note', 'Hi {first_name}, here\'s this month\'s market in two minutes. — {agent_first_name}'],
            [120, 'email', 'Monthly market note', 'Hi {first_name}, rates, prices and what it means for you this month. — {agent_first_name}'],
            [150, 'call', 'Friendly check-in call', null],
        ]],
        'buyer_lead_es' => ['Prospecto nuevo de internet (10 días, en español)', 'Seguimiento rápido para prospectos que prefieren español.', [
            [0, 'call', 'Llamar para presentarse', null],
            [0, 'text', 'Enviar un texto de presentación', 'Hola {first_name}, soy {agent_first_name}. ¡Gracias por escribir sobre casas! ¿Qué zona y qué rango de precio le interesan?'],
            [1, 'call', 'Segundo intento de llamada', null],
            [3, 'email', 'Enviar algunas casas que le pueden gustar', 'Hola {first_name}, aquí tiene algunas casas que creo que le gustarán. ¿Quiere ver alguna esta semana? — {agent_first_name}'],
            [5, 'call', 'Tercer intento de llamada', null],
            [7, 'text', 'Texto de seguimiento', 'Hola {first_name}, ¿sigue buscando? Puedo crearle una búsqueda que le avise de casas nuevas el mismo día. — {agent_first_name}'],
        ]],
        'seller_lead_es' => ['Prospecto vendedor (14 días, en español)', 'Para dueños que preguntaron por el valor de su casa o quieren vender.', [
            [0, 'call', 'Llamar: agradecer y preguntar por sus planes', null],
            [0, 'email', 'Enviar el valor estimado y qué lo afecta', 'Hola {first_name}, gracias por preguntar por el valor de su casa. Aquí ve en cuánto se vendieron casas parecidas cerca. — {agent_first_name}'],
            [2, 'call', 'Ofrecer una visita gratuita para un precio exacto', null],
            [5, 'text', 'Mensaje de seguimiento', 'Hola {first_name}, con gusto visito su casa y le doy un precio exacto, sin compromiso. ¿Le queda bien esta semana? — {agent_first_name}'],
            [14, 'call', 'Llamada de seguimiento', null],
        ]],
        'past_client_es' => ['Cliente anterior — primer año (en español)', 'Cuidar a los nuevos dueños y ganar referidos.', [
            [7, 'call', '¿Cómo va la casa nueva?', null],
            [30, 'email', 'Consejos para el nuevo dueño', 'Hola {first_name}, algunos consejos para sus primeros meses en la casa nueva. — {agent_first_name}'],
            [180, 'call', 'Llamada a los seis meses', null],
            [365, 'text', '¡Feliz aniversario en su casa!', '¡Feliz primer aniversario en su casa, {first_name}! Si algún amigo piensa mudarse, con gusto le ayudo. — {agent_first_name}'],
        ]],
    ];

    /** The plan for someone who went quiet after a plan finished (SO2). */
    public const QUIET_PLAN = 'not_ready';

    public function __construct(private readonly ContactVisibility $visibility, private readonly Consent $consent, private readonly Timeline $timeline) {}

    /**
     * Plans this user may use (brokerage-shared and their own), creating the templates first time.
     *
     * @return Collection<int, FollowUpPlan>
     */
    public function plansFor(User $user): Collection
    {
        $this->seedTemplates();

        return FollowUpPlan::query()->with('steps')->where(fn ($q) => $q->where('shared', true)->orWhere('owner_user_id', $user->id))->orderBy('name')->get();
    }

    /**
     * Adds each ready-made plan this RightAlly doesn't have yet. Plans from before keys existed are matched by name.
     */
    public function seedTemplates(): void
    {
        $existing = FollowUpPlan::query()->where('shared', true)->get(['id', 'name', 'template_key']);
        $keys = $existing->pluck('template_key')->filter()->all();
        foreach (self::TEMPLATES as $key => [$name, $description, $steps]) {
            if (in_array($key, $keys, true)) {
                continue;
            }
            $same = $existing->first(fn (FollowUpPlan $p): bool => $p->template_key === null && $p->name === $name);
            if ($same !== null) {
                $same->forceFill(['template_key' => $key])->save();

                continue;
            }
            DB::transaction(function () use ($key, $name, $description, $steps): void {
                $plan = FollowUpPlan::query()->create(['name' => $name, 'description' => $description, 'shared' => true, 'active' => true, 'trigger' => 'manual', 'template_key' => $key]);
                foreach ($steps as $i => [$day, $type, $title, $message]) {
                    PlanStep::query()->create(['plan_id' => $plan->id, 'sort' => $i, 'day' => $day, 'type' => $type, 'title' => $title, 'message' => $message]);
                }
            });
        }
    }

    /**
     * @param  array{name: string, description?: string|null, trigger?: string|null, trigger_value?: string|null, shared?: bool|null, steps: list<array{day: int, type: string, title: string, message?: string|null}>}  $data
     */
    public function save(?FollowUpPlan $plan, array $data, User $by): FollowUpPlan
    {
        $isManager = CrmAccess::manager($by);
        if ($plan !== null && ! $this->canEdit($plan, $by)) {
            throw ValidationException::withMessages(['plan' => 'You can only change your own plans.']);
        }

        $errors = [];
        $name = trim($data['name']);
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Give the plan a short name.';
        }
        $steps = $data['steps'];
        if ($steps === [] || count($steps) > 30) {
            $errors['steps'] = 'A plan has 1 to 30 steps.';
        }
        $previous = -1;
        foreach ($steps as $i => $step) {
            if (! in_array($step['type'], self::STEP_TYPES, true)) {
                $errors["steps.{$i}.type"] = 'Choose call, text, email or task.';
            }
            if ($step['day'] < 0 || $step['day'] > 365 || $step['day'] < $previous) {
                $errors["steps.{$i}.day"] = 'Days run from 0 to 365, in order.';
            }
            $previous = $step['day'];
            if (trim($step['title']) === '') {
                $errors["steps.{$i}.title"] = 'Describe the step.';
            }
            $flags = FairHousing::screen($step['title'].' '.($step['message'] ?? ''), 'templates', 'blocked', 'Follow-up plan step');
            if ($flags !== []) {
                $errors["steps.{$i}.message"] = 'Please reword: "'.implode('", "', $flags).'" could raise Fair Housing concerns.';
            }
        }
        $trigger = in_array($data['trigger'] ?? 'manual', ['manual', 'source', 'stage'], true) ? (string) ($data['trigger'] ?? 'manual') : 'manual';
        if ($trigger !== 'manual' && ! $isManager && ($data['shared'] ?? false)) {
            $errors['trigger'] = 'Only owners and staff can enrol the whole brokerage automatically.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($plan, $data, $name, $trigger, $by, $isManager): FollowUpPlan {
            $attributes = ['name' => $name, 'description' => $data['description'] ?? null, 'trigger' => $trigger,
                'trigger_value' => $trigger === 'manual' ? null : ($data['trigger_value'] ?? null),
                'shared' => $isManager && (bool) ($data['shared'] ?? false), 'active' => true];
            $plan = $plan ?? new FollowUpPlan(['owner_user_id' => $by->id]);
            $plan->fill($attributes)->save();

            PlanStep::query()->where('plan_id', $plan->id)->delete();
            foreach ($data['steps'] as $i => $step) {
                PlanStep::query()->create(['plan_id' => $plan->id, 'sort' => $i, 'day' => $step['day'], 'type' => $step['type'],
                    'title' => trim($step['title']), 'message' => isset($step['message']) && trim((string) $step['message']) !== '' ? trim((string) $step['message']) : null]);
            }

            return $plan;
        });
    }

    public function enroll(Contact $contact, FollowUpPlan $plan, User $by): PlanEnrollment
    {
        if (! $this->visibility->canSee($by, $contact)) {
            throw ValidationException::withMessages(['contact' => 'Contact not found.']);
        }
        $training = app(TrainingGate::class)->missing($by, 'follow_up_plans'); // LN2
        if ($training !== null) {
            throw ValidationException::withMessages(['plan' => $training]);
        }
        $hold = app(Inbox\Bridges::class)->hold($contact);
        if ($hold !== null) {
            throw ValidationException::withMessages(['contact' => $hold['reason']]);
        }
        if (! $plan->active || (! $plan->shared && $plan->owner_user_id !== $by->id && $plan->owner_user_id !== $contact->owner_user_id)) {
            throw ValidationException::withMessages(['plan' => 'Choose an active plan you can use.']);
        }
        if (PlanEnrollment::query()->where('contact_id', $contact->id)->where('plan_id', $plan->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['plan' => "{$contact->name} is already on this plan."]);
        }

        $first = $plan->steps()->first();
        $now = CarbonImmutable::now();
        $enrollment = PlanEnrollment::query()->create([
            'plan_id' => $plan->id, 'contact_id' => $contact->id, 'assigned_to' => $contact->owner_user_id ?? $by->id,
            'status' => 'active', 'next_step' => 0, 'started_at' => $now, 'next_due_at' => $now->addDays($first !== null ? $first->day : 0),
        ]);
        $this->timeline->system($contact, "Started follow-up plan: {$plan->name}", $by->id);
        $this->process($enrollment);

        return $enrollment;
    }

    public function stop(PlanEnrollment $enrollment, string $reason, ?int $byUserId = null): void
    {
        if ($enrollment->status !== 'active') {
            return;
        }

        $enrollment->update(['status' => 'stopped', 'stop_reason' => mb_substr($reason, 0, 100), 'next_due_at' => null]);
        Task::query()->where('plan_enrollment_id', $enrollment->id)->whereNull('completed_at')->delete();
        $this->timeline->system($enrollment->contact, "Follow-up plan stopped ({$enrollment->plan->name}): {$reason}", $byUserId);
    }

    public function stopAllFor(Contact $contact, string $reason): void
    {
        foreach (PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->get() as $enrollment) {
            $this->stop($enrollment, $reason);
        }
    }

    /**
     * Creates the tasks for steps whose day has come; completes or stops the plan as needed.
     */
    public function process(PlanEnrollment $enrollment): void
    {
        $contact = $enrollment->contact;
        if (in_array($contact->lifecycle, self::STOP_STAGES, true)) {
            $this->stop($enrollment, 'Contact is now '.str_replace('_', ' ', $contact->lifecycle->value));

            return;
        }

        $steps = $enrollment->plan->steps()->get()->values();
        $index = $enrollment->next_step;
        $now = CarbonImmutable::now();

        $step = $steps->get($index);
        while ($step !== null && $enrollment->started_at->addDays($step->day)->lte($now)) {
            $current = $step;
            app(AuditLogger::class)->asAutomation('follow_up_plan', $enrollment->assigned_to, 'Plan: '.$enrollment->plan->name,
                fn () => $this->createTask($enrollment, $current, $contact));
            $index++;
            $step = $steps->get($index);
        }

        $enrollment->update([
            'next_step' => $index,
            'status' => $step === null ? 'completed' : 'active',
            'next_due_at' => $step === null ? null : $enrollment->started_at->addDays($step->day),
        ]);
    }

    public function processDue(): int
    {
        $count = 0;
        foreach (PlanEnrollment::query()->where('status', 'active')->where('next_due_at', '<=', CarbonImmutable::now())->limit(2000)->get() as $enrollment) {
            $this->process($enrollment);
            $count++;
        }

        return $count;
    }

    public function canEdit(FollowUpPlan $plan, User $user): bool
    {
        return $plan->shared ? CrmAccess::manager($user) : $plan->owner_user_id === $user->id;
    }

    private function createTask(PlanEnrollment $enrollment, PlanStep $step, Contact $contact): void
    {
        $owner = User::query()->find($enrollment->assigned_to);
        $type = $step->type;
        $title = $step->title;

        // Respect consent: no text without texting consent, no call when Do Not Call.
        if ($type === 'text' && ! $this->consent->canText($contact)) {
            $type = $contact->primary_email !== null ? 'email' : 'call';
            $title .= ' (sent by '.$type.': no texting consent)';
        }
        if ($type === 'call' && ! $this->consent->canCall($contact)) {
            $type = $contact->primary_email !== null ? 'email' : 'task';
            $title .= ' (Do Not Call: '.($type === 'email' ? 'email instead' : 'no call').')';
        }

        $message = $step->message !== null
            ? strtr($step->message, ['{first_name}' => $contact->first_name !== '' ? $contact->first_name : 'there', '{agent_first_name}' => $owner instanceof User ? $owner->first_name : ''])
            : null;

        Task::query()->create([
            'contact_id' => $contact->id, 'assigned_to' => $enrollment->assigned_to, 'type' => $type === 'task' ? 'follow_up' : $type,
            'title' => mb_substr($title, 0, 200), 'due_at' => CarbonImmutable::now(), 'plan_enrollment_id' => $enrollment->id, 'message' => $message,
            'created_by' => null,
        ]);
        $contact->newQuery()->whereKey($contact->id)->update(['next_task_at' => Task::query()->where('contact_id', $contact->id)->whereNull('completed_at')->min('due_at')]);
    }
}
