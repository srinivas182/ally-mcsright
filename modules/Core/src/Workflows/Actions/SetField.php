<?php

declare(strict_types=1);

namespace Modules\Core\Workflows\Actions;

use App\Models\User;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;

/** Set one of the brokerage's custom fields on the record (specs/core/10). */
final class SetField implements WorkflowAction
{
    public function key(): string
    {
        return 'set_field';
    }

    public function label(): string
    {
        return 'Set a custom field';
    }

    public function subjects(): array
    {
        return ['contact', 'deal', 'agent'];
    }

    public function params(): array
    {
        return ['field' => ['label' => 'Field (its key)', 'type' => 'text', 'required' => true], 'value' => ['label' => 'Value', 'type' => 'text', 'required' => true]];
    }

    public function describe(array $params): string
    {
        return 'Set '.($params['field'] ?? 'a field').' to "'.($params['value'] ?? '').'"';
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        app(CustomFields::class)->save($subject->type, $subject->id, [(string) $params['field'] => $params['value']], $runner, $subject->ownerUserId);

        return 'Set '.$params['field'].'.';
    }
}
