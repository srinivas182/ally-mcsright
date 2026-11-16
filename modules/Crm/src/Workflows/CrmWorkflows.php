<?php

declare(strict_types=1);

namespace Modules\Crm\Workflows;

use App\Models\User;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowRegistry;
use Modules\Core\Workflows\Workflows;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Stage;

/** The CRM's part of the workflow engine (specs/core/06, W1): contacts as subjects, their triggers and steps. */
final class CrmWorkflows
{
    public static function register(WorkflowRegistry $registry): void
    {
        $registry->subject('contact', 'contact', function (int $id): ?Subject {
            $c = Contact::query()->find($id);

            return $c instanceof Contact ? new Subject('contact', $c->id, $c, trim($c->first_name.' '.$c->last_name), $c->owner_user_id) : null;
        });
        $facts = ['source' => 'Where they came from (e.g. website, zillow, referral)', 'origin' => 'Company or personal contact', 'lifecycle' => 'Lifecycle (lead, active, past_client…)'];
        $registry->trigger('contact.created', 'A contact is added', 'contact', $facts);
        $registry->trigger('contact.stage_changed', 'A contact moves to a pipeline stage', 'contact', $facts + ['stage' => 'The stage it moved to', 'outcome' => 'The stage\'s outcome (won, lost)']);
        $registry->action(new CreateTask);
        $registry->action(new AddTag);
        $registry->action(new StartPlan);
    }

    public static function created(int $contactId, ?int $by): void
    {
        $c = Contact::query()->find($contactId);
        if ($c instanceof Contact) {
            app(Workflows::class)->fire('contact.created', $c->id, self::facts($c), $by !== null ? User::query()->find($by) : null);
        }
    }

    public static function stageChanged(int $contactId, int $stageId, ?int $by): void
    {
        $c = Contact::query()->find($contactId);
        $stage = Stage::query()->find($stageId);
        if ($c instanceof Contact && $stage instanceof Stage) {
            app(Workflows::class)->fire('contact.stage_changed', $c->id, self::facts($c) + ['stage' => $stage->name, 'outcome' => (string) $stage->outcome],
                $by !== null ? User::query()->find($by) : null);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function facts(Contact $c): array
    {
        return ['source' => $c->source->value, 'origin' => $c->origin->value, 'lifecycle' => $c->lifecycle->value];
    }

    /** The contact a step works on: the contact itself, or the deal's client. */
    public static function contactOf(Subject $subject): ?Contact
    {
        if ($subject->model instanceof Contact) {
            return $subject->model;
        }
        $id = $subject->model->getAttribute('contact_id');

        return is_numeric($id) ? Contact::query()->find((int) $id) : null;
    }
}
