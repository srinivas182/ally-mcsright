<?php

declare(strict_types=1);

namespace Modules\AI\Workflows;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Workflows\WorkflowDrafter;

/**
 * "Describe it in plain English" (specs/core/06, W1): the model picks a trigger, conditions and steps from the
 * catalogue only. The draft is shown for the person to check and is checked again on save.
 */
final class AiWorkflowDrafter implements WorkflowDrafter
{
    public const FEATURE = 'core.workflow_draft';

    public function __construct(private readonly AiGateway $gateway) {}

    public function draft(string $text, array $catalogue, User $user): ?array
    {
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'ai.workflow_draft', 'workflow-draft:'.$user->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['text' => mb_substr(trim($text), 0, 1000), 'catalogue' => (string) json_encode($catalogue)],
            outputSchema: ['type' => 'object', 'required' => ['trigger', 'steps'], 'properties' => ['trigger' => ['type' => 'string'], 'steps' => ['type' => 'array']]], userId: $user->id));
        $json = (array) $result->json;
        $trigger = (string) ($json['trigger'] ?? '');
        if (! array_key_exists($trigger, $catalogue['triggers'])) {
            return null;
        }
        $conditions = [];
        foreach (array_slice((array) ($json['conditions'] ?? []), 0, 10) as $c) {
            if (is_array($c) && isset($c['fact'], $c['op'])) {
                $conditions[] = ['fact' => (string) $c['fact'], 'op' => (string) $c['op'], 'value' => $c['value'] ?? null];
            }
        }
        $steps = [];
        foreach (array_slice((array) ($json['steps'] ?? []), 0, 20) as $s) {
            if (is_array($s) && isset($s['type']) && (array_key_exists((string) $s['type'], $catalogue['actions']) || in_array($s['type'], ['wait', 'approval'], true))) {
                $steps[] = ['type' => (string) $s['type'], 'params' => (array) ($s['params'] ?? [])];
            }
        }

        return $steps === [] ? null : ['name' => mb_substr(trim((string) ($json['name'] ?? 'New automation')), 0, 120), 'trigger' => $trigger, 'conditions' => $conditions, 'steps' => $steps];
    }
}
