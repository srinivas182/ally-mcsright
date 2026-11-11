<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Memory;

/**
 * Small personal details for a human touch (family and pet names, hobbies, favourite things,
 * milestones). Never sensitive topics; shown to the agent only; never used to search, filter or
 * market (Fair Housing). Encrypted at rest.
 */
final class PersonalMemory
{
    /** Topics that are never stored, in any wording. */
    private const SENSITIVE = ['health', 'sick', 'illness', 'cancer', 'diagnos', 'surgery', 'hospital stay', 'therapy', 'medication', 'pregnan',
        'disab', 'wheelchair', 'religio', 'church', 'mosque', 'synagogue', 'temple', 'pray', 'ethnic', 'race', 'immigra', 'visa', 'citizenship',
        'gay', 'lesbian', 'sexual', 'politic', 'democrat', 'republican', 'divorc', 'separat', 'death', 'died', 'funeral', 'passed away',
        'debt', 'bankrupt', 'foreclos', 'unemploy', 'laid off', 'arrest', 'court', 'rehab', 'addict'];

    public function add(Contact $contact, string $category, string $detail, ?User $by = null, ?int $sourceActivityId = null): ?Memory
    {
        $detail = mb_substr(trim($detail), 0, 200);
        if (! in_array($category, Memory::CATEGORIES, true) || $detail === '') {
            return null;
        }
        if ($this->isSensitive($detail)) {
            if ($by !== null && $sourceActivityId === null) {
                throw ValidationException::withMessages(['detail' => 'Personal notes cannot include sensitive topics such as health, religion, money troubles or family breakdowns.']);
            }

            return null;
        }
        $existing = Memory::query()->where('contact_id', $contact->id)->get()->contains(fn (Memory $m): bool => mb_strtolower($m->detail) === mb_strtolower($detail));

        return $existing ? null : Memory::query()->create(['contact_id' => $contact->id, 'category' => $category, 'detail' => $detail,
            'source_activity_id' => $sourceActivityId, 'created_by' => $by?->id]);
    }

    /**
     * @param  array<int, mixed>  $raw  from the note-reading pass
     */
    public function storeExtracted(Contact $contact, array $raw): int
    {
        $count = 0;
        foreach (array_slice($raw, 0, 10) as $item) {
            if (is_array($item) && $this->add($contact, (string) ($item['category'] ?? ''), (string) ($item['detail'] ?? '')) !== null) {
                $count++;
            }
        }

        return $count;
    }

    public function isSensitive(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::SENSITIVE as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return FairHousing::flags($text) !== [];
    }
}
