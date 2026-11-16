<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\CustomFields\CustomFields;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/**
 * "Fill with AI" for a contact's custom field (specs/core/10, N1): a suggested value from the contact's notes, with
 * the words it came from. Nothing is saved until the person confirms it.
 */
final class FieldSuggestions
{
    public const FEATURE = 'crm.field_fill';

    public function __construct(private readonly AiGateway $gateway, private readonly CustomFields $fields) {}

    /**
     * @return array{value: string|null, evidence: string|null}
     */
    public function suggest(Contact $contact, string $key, User $user): array
    {
        $field = collect($this->fields->valuesFor('contact', $contact->id, $user, $contact->owner_user_id))->firstWhere('key', $key);
        if ($field === null || $field['hidden']) {
            abort(404);
        }
        $notes = Activity::query()->where('contact_id', $contact->id)->whereIn('type', ['note', 'call', 'email', 'text', 'meeting'])->whereNotNull('body')
            ->orderByDesc('occurred_at')->limit(30)->get()
            ->map(fn (Activity $a): string => '- '.$a->occurred_at->toDateString().': '.mb_substr(trim((string) $a->body), 0, 600))->implode("\n");
        if (trim($notes) === '') {
            return ['value' => null, 'evidence' => 'There are no notes on this contact to read from yet.'];
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'crm.field_fill', 'crm-field-fill:'.$contact->ulid.':'.$key.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['label' => $field['label'], 'type' => $field['type'], 'contact_name' => $contact->first_name,
                'choices' => $field['options'] !== [] ? ' — choices: '.implode(', ', $field['options']) : '', 'notes' => $notes],
            outputSchema: ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'string'], 'evidence' => ['type' => 'string']]], userId: $user->id));
        $json = (array) $result->json;
        $value = trim((string) ($json['value'] ?? ''));
        try {
            $value = $value === '' ? null : CustomFields::encode($field['type'], $field['options'], $field['type'] === 'multi' ? array_map('trim', explode(',', $value)) : $value);
        } catch (\InvalidArgumentException) {
            $value = null; // a suggestion that doesn't fit the field is dropped
        }
        if ($value !== null && $field['type'] === 'multi') {
            $value = implode(', ', (array) json_decode($value, true));
        }

        return ['value' => $value, 'evidence' => isset($json['evidence']) && trim((string) $json['evidence']) !== '' ? mb_substr(trim((string) $json['evidence']), 0, 300) : null];
    }
}
