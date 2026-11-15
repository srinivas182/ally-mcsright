<?php

declare(strict_types=1);

namespace Modules\Crm\Webhooks;

use App\Models\User;
use Modules\Core\Connect\Outbox;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;

/**
 * CRM events for connected apps (specs/connect/01). The brokerage's webhooks only ever carry company contacts and
 * leads: an agent's personal sphere is private (specs/crm/01) and never leaves through them.
 */
final class CrmEvents
{
    public static function lead(Lead $lead): void
    {
        $contact = $lead->contact_id !== null ? Contact::query()->find($lead->contact_id) : null;
        if ($contact === null || $contact->origin !== Origin::Company) {
            return;
        }
        $source = $lead->source_id !== null ? LeadSource::query()->find($lead->source_id) : null;
        app(Outbox::class)->emit('lead.created', ['lead' => ['id' => $lead->ulid, 'source' => $source?->name, 'channel' => $lead->channel, 'intent' => $lead->intent,
            'zip' => $lead->zip, 'price_min' => $lead->price_min, 'price_max' => $lead->price_max, 'property' => $lead->property, 'message' => $lead->message,
            'landing_url' => $lead->landing_url, 'created_at' => $lead->created_at->toIso8601String()],
            'agent' => self::agent($lead->assigned_user_id), 'contact' => self::contact($contact)]);
    }

    public static function contactCreated(int $contactId): void
    {
        $c = Contact::query()->find($contactId);
        if ($c !== null && $c->origin === Origin::Company) {
            app(Outbox::class)->emit('contact.created', ['contact' => self::contact($c), 'agent' => self::agent($c->owner_user_id)]);
        }
    }

    public static function stageChanged(int $contactId, int $stageId): void
    {
        $c = Contact::query()->find($contactId);
        $stage = Stage::query()->find($stageId);
        if ($c !== null && $stage !== null && $c->origin === Origin::Company) {
            app(Outbox::class)->emit('contact.stage_changed', ['contact' => self::contact($c), 'stage' => ['id' => $stage->ulid, 'name' => $stage->name],
                'agent' => self::agent($c->owner_user_id)]);
        }
    }

    public static function taskCompleted(int $taskId): void
    {
        $t = Task::query()->find($taskId);
        $c = $t?->contact_id !== null ? Contact::query()->find($t->contact_id) : null;
        if ($t === null || ($c !== null && $c->origin !== Origin::Company)) {
            return;
        }
        app(Outbox::class)->emit('task.completed', ['task' => ['id' => $t->ulid, 'type' => $t->type, 'title' => $t->title, 'due_at' => $t->due_at->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String()], 'contact' => $c !== null ? self::contact($c) : null, 'agent' => self::agent($t->assigned_to)]);
    }

    public static function booking(Task $task, string $kind): void
    {
        $c = $task->contact_id !== null ? Contact::query()->find($task->contact_id) : null;
        if ($c !== null && $c->origin !== Origin::Company) {
            return;
        }
        app(Outbox::class)->emit('booking.created', ['booking' => ['id' => $task->ulid, 'kind' => $kind, 'starts_at' => $task->due_at->toIso8601String()],
            'contact' => $c !== null ? self::contact($c) : null, 'agent' => self::agent($task->assigned_to)]);
    }

    /** @return array<string, mixed> */
    private static function contact(Contact $c): array
    {
        return ['id' => $c->ulid, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->primary_email, 'phone' => $c->primary_phone,
            'city' => $c->city, 'state' => $c->state, 'zip' => $c->zip, 'source' => $c->source->value, 'lifecycle' => $c->lifecycle->value];
    }

    /** @return array{id: string, name: string, email: string}|null */
    private static function agent(?int $userId): ?array
    {
        $u = $userId !== null ? User::query()->find($userId) : null;

        return $u instanceof User ? ['id' => $u->ulid, 'name' => $u->name, 'email' => $u->email] : null;
    }
}
