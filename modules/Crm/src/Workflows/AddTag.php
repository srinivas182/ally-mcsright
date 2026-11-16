<?php

declare(strict_types=1);

namespace Modules\Crm\Workflows;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;
use Modules\Crm\Actions\Tags;

final class AddTag implements WorkflowAction
{
    public function key(): string
    {
        return 'add_tag';
    }

    public function label(): string
    {
        return 'Tag the contact';
    }

    public function subjects(): array
    {
        return ['contact', 'deal'];
    }

    public function params(): array
    {
        return ['tag' => ['label' => 'Tag', 'type' => 'text', 'required' => true]];
    }

    public function describe(array $params): string
    {
        return 'Tag the contact "'.($params['tag'] ?? '').'"';
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        $contact = CrmWorkflows::contactOf($subject) ?? throw ValidationException::withMessages(['tag' => 'This deal has no client contact to tag.']);
        app(Tags::class)->attach($contact, mb_substr((string) $params['tag'], 0, 60));

        return 'Tagged '.$contact->first_name.' "'.$params['tag'].'".';
    }
}
