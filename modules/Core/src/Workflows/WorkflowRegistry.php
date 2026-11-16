<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use Closure;

/**
 * What automations can listen to and do (specs/core/06, W1). Modules register their triggers (with the facts each
 * one carries, for conditions), the kinds of record they run on, and their actions.
 */
final class WorkflowRegistry
{
    /** @var array<string, array{key: string, label: string, subject: string, facts: array<string, string>}> */
    private array $triggers = [];

    /** @var array<string, array{label: string, resolve: Closure(int): ?Subject}> */
    private array $subjects = [];

    /** @var array<string, WorkflowAction> */
    private array $actions = [];

    /**
     * @param  array<string, string>  $facts  fact => plain-English name
     */
    public function trigger(string $key, string $label, string $subject, array $facts = []): void
    {
        $this->triggers[$key] = ['key' => $key, 'label' => $label, 'subject' => $subject, 'facts' => $facts];
    }

    /**
     * @param  Closure(int): ?Subject  $resolve
     */
    public function subject(string $type, string $label, Closure $resolve): void
    {
        $this->subjects[$type] = ['label' => $label, 'resolve' => $resolve];
    }

    public function action(WorkflowAction $action): void
    {
        $this->actions[$action->key()] = $action;
    }

    /**
     * @return array<string, array{key: string, label: string, subject: string, facts: array<string, string>}>
     */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /**
     * @return array{key: string, label: string, subject: string, facts: array<string, string>}|null
     */
    public function findTrigger(string $key): ?array
    {
        return $this->triggers[$key] ?? null;
    }

    /**
     * @return array<string, WorkflowAction>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function findAction(string $key): ?WorkflowAction
    {
        return $this->actions[$key] ?? null;
    }

    public function resolve(string $type, int $id): ?Subject
    {
        $s = $this->subjects[$type] ?? null;

        return $s !== null ? ($s['resolve'])($id) : null;
    }

    public function subjectLabel(string $type): string
    {
        return $this->subjects[$type]['label'] ?? $type;
    }
}
