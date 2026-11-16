<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;

/**
 * "Ask your numbers" (specs/insights/01, K2): a plain-English question becomes a report definition (from the catalogue
 * only), which then runs with the person's own access, so the answer is the real rows behind it, never a guess.
 */
final class AskYourNumbers
{
    public const FEATURE = 'insights.ask';

    public function __construct(private readonly AiGateway $gateway, private readonly Reports $reports) {}

    /**
     * @return array{definition: array<string, mixed>, intro: string, result: array<string, mixed>}|null
     */
    public function ask(string $question, User $user): ?array
    {
        $catalogue = [];
        foreach (array_keys(Reports::subjects()) as $subject) {
            $catalogue[$subject] = array_map(fn (array $c): string => $c['label'].' ('.$c['type'].')', $this->reports->columns($subject));
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'insights.ask', 'insights-ask:'.$user->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['question' => mb_substr(trim($question), 0, 500), 'catalogue' => (string) json_encode($catalogue), 'today' => CarbonImmutable::today()->toDateString()],
            outputSchema: ['type' => 'object', 'required' => ['subject'], 'properties' => ['subject' => ['type' => 'string']]], userId: $user->id));
        $json = (array) $result->json;
        $subject = (string) ($json['subject'] ?? '');
        if (! array_key_exists($subject, Reports::subjects())) {
            return null;
        }
        $columns = $this->reports->columns($subject);
        $definition = [
            'subject' => $subject,
            'columns' => array_values(array_filter(array_map('strval', (array) ($json['columns'] ?? [])), fn (string $c): bool => isset($columns[$c]))),
            'filters' => array_values(array_filter(array_map(fn ($f): array => (array) $f, (array) ($json['filters'] ?? [])),
                fn (array $f): bool => isset($columns[(string) ($f['column'] ?? '')]) && array_key_exists((string) ($f['op'] ?? ''), Reports::OPS))),
            'group_by' => (string) ($json['group_by'] ?? ''),
        ];
        try {
            $rows = $this->reports->run($user, $definition);
        } catch (ValidationException) {
            $definition['filters'] = [];
            $rows = $this->reports->run($user, $definition);
        }

        return ['definition' => $definition, 'intro' => mb_substr(trim((string) ($json['answer_intro'] ?? 'Here is what we found.')), 0, 300), 'result' => $rows];
    }
}
