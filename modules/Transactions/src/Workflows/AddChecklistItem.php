<?php

declare(strict_types=1);

namespace Modules\Transactions\Workflows;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/** Add a document to the deal's checklist (e.g. a lead-based paint disclosure for older homes). */
final class AddChecklistItem implements WorkflowAction
{
    public function key(): string
    {
        return 'add_checklist_item';
    }

    public function label(): string
    {
        return 'Add a document to the deal checklist';
    }

    public function subjects(): array
    {
        return ['deal'];
    }

    public function params(): array
    {
        return ['label' => ['label' => 'Document', 'type' => 'text', 'required' => true],
            'required' => ['label' => 'Needed to close', 'type' => 'choice', 'options' => ['yes' => 'Yes', 'no' => 'No'], 'required' => true]];
    }

    public function describe(array $params): string
    {
        return 'Add "'.($params['label'] ?? '').'" to the checklist'.(($params['required'] ?? 'yes') === 'yes' ? ' (needed to close)' : '');
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        $deal = $subject->model;
        if (! $deal instanceof Deal || $deal->locked_at !== null) {
            throw ValidationException::withMessages(['deal' => 'The deal is closed and locked.']);
        }
        $label = mb_substr(trim((string) $params['label']), 0, 150);
        if (Item::query()->where('deal_id', $deal->id)->where('label', $label)->exists()) {
            return 'Already on the checklist: '.$label;
        }
        Item::query()->create(['deal_id' => $deal->id, 'label' => $label, 'required' => ($params['required'] ?? 'yes') === 'yes', 'stage' => 'contract',
            'sort' => (int) Item::query()->where('deal_id', $deal->id)->max('sort') + 1, 'status' => 'missing']);

        return 'Added to the checklist: '.$label;
    }
}
