<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealIq\FormDrafter;
use Modules\Transactions\Models\Deal;

/**
 * Deal-IQ drafting on the brokerage's own forms (specs/ai/02, §3): the model picks one of the brokerage's licensed
 * forms and fills only the values the agent gave; everything else comes from the deal by auto-fill. The result is a
 * draft packet: nothing is sent until the agent checks it and sends it for e-signature.
 */
final class AiDrafter implements FormDrafter
{
    public const FEATURE = 'forms.draft';

    public function __construct(private readonly AiGateway $gateway, private readonly Packets $packets) {}

    public function draft(Deal $deal, string $instruction, User $by): ?array
    {
        $forms = [];
        foreach (DB::connection(Connections::cell())->table('form_templates')->where('active', true)->whereNull('superseded_by')->orderBy('name')->limit(80)->get() as $t) {
            $fields = array_values(array_filter((array) json_decode((string) $t->fields, true), fn ($f): bool => is_array($f) && ! in_array($f['type'] ?? '', Templates::SIGNER_FIELDS, true)));
            $forms[] = ['ulid' => (string) $t->ulid, 'name' => (string) $t->name, 'fields' => array_map(fn (array $f): array => ['key' => (string) ($f['key'] ?? ''),
                'label' => (string) ($f['label'] ?? $f['key'] ?? ''), 'filled_from_deal' => ($f['bind'] ?? null) !== null], array_slice($fields, 0, 60))];
        }
        if ($forms === []) {
            return null;
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'forms.draft', 'forms-draft:'.$deal->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['instruction' => mb_substr(trim($instruction), 0, 1000), 'forms' => (string) json_encode($forms),
                'deal' => (string) json_encode(['address' => trim($deal->street.', '.$deal->city.', '.$deal->state_code), 'type' => $deal->type,
                    'price' => $deal->price_cents / 100, 'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString()])],
            outputSchema: ['type' => 'object', 'required' => ['form'], 'properties' => ['form' => ['type' => 'string'], 'values' => ['type' => 'object'], 'flags' => ['type' => 'array']]],
            userId: $by->id));
        $json = (array) $result->json;
        $chosen = collect($forms)->firstWhere('ulid', (string) ($json['form'] ?? ''));
        if ($chosen === null) {
            return null;
        }
        $packet = $this->packets->create($deal, $chosen['name'], [$chosen['ulid']], $by);
        $formId = (int) DB::connection(Connections::cell())->table('form_packet_forms')->where('packet_id', DB::connection(Connections::cell())->table('form_packets')->where('ulid', $packet)->value('id'))->value('id');
        $keys = array_column($chosen['fields'], 'key');
        $values = [];
        foreach ((array) ($json['values'] ?? []) as $k => $v) {
            if (in_array((string) $k, $keys, true) && is_scalar($v)) {
                $values[(string) $k] = (string) $v;
            }
        }
        $this->packets->setValues($packet, $formId, $values, $by);

        return ['packet' => $packet, 'form' => $chosen['name'], 'filled' => $values,
            'flags' => array_values(array_map(fn ($f): string => mb_substr((string) $f, 0, 200), array_filter((array) ($json['flags'] ?? []), 'is_scalar')))];
    }
}
