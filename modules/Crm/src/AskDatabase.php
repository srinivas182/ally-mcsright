<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Pipeline;

/**
 * "Ask your database" in plain English or Spanish (typed or spoken). The AI only turns the
 * question into filters and keywords; the search itself runs in code on top of ContactVisibility,
 * and each result carries the reason it matched (the note that mentions it, or the filters).
 */
final class AskDatabase
{
    public const FEATURE = 'crm.ask';

    private const SCHEMA = ['type' => 'object', 'required' => ['keywords', 'explanation'], 'properties' => [
        'lifecycle' => ['type' => 'array', 'items' => ['type' => 'string']], 'origin' => ['type' => 'string'],
        'sources' => ['type' => 'array', 'items' => ['type' => 'string']], 'pipeline' => ['type' => 'string'],
        'cities' => ['type' => 'array', 'items' => ['type' => 'string']], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'no_contact_days' => ['type' => 'integer'], 'created_within_days' => ['type' => 'integer'], 'overdue_task' => ['type' => 'boolean'],
        'keywords' => ['type' => 'array', 'items' => ['type' => 'string']], 'explanation' => ['type' => 'string'],
    ]];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ContactVisibility $visibility,
        private readonly SmartLists $lists,
    ) {}

    /**
     * @return array{filters: array<string, mixed>, explanation: string}
     */
    public function understand(string $question, User $user): array
    {
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'crm.ask', 'crm-ask:'.$user->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['question' => mb_substr(trim($question), 0, 500)], outputSchema: self::SCHEMA, userId: $user->id));
        $json = (array) $result->json;

        $filters = array_filter([
            'lifecycle' => array_values(array_filter((array) ($json['lifecycle'] ?? []), fn ($v) => Lifecycle::tryFrom((string) $v) !== null && $v !== 'archived')),
            'origin' => Origin::tryFrom((string) ($json['origin'] ?? '')) !== null ? (string) $json['origin'] : null,
            'source' => array_values(array_filter((array) ($json['sources'] ?? []), fn ($v) => Source::tryFrom((string) $v) !== null)),
            'tags' => array_values(array_map('strval', array_slice((array) ($json['tags'] ?? []), 0, 5))),
            'cities' => array_values(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 80), array_slice((array) ($json['cities'] ?? []), 0, 5))),
            'pipeline' => in_array($json['pipeline'] ?? '', ['buyer', 'seller'], true) ? (string) $json['pipeline'] : null,
            'no_contact_days' => self::days($json['no_contact_days'] ?? null),
            'created_within_days' => self::days($json['created_within_days'] ?? null),
            'overdue_task' => ($json['overdue_task'] ?? false) === true ? true : null,
            'keywords' => array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), array_slice((array) ($json['keywords'] ?? []), 0, 6)), fn ($k) => mb_strlen($k) >= 3)),
        ], fn ($v) => $v !== null && $v !== []);

        return ['filters' => $filters, 'explanation' => mb_substr((string) ($json['explanation'] ?? ''), 0, 300)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{ulid: string, name: string, lifecycle: string, last_activity_at: string|null, reason: string}>
     */
    public function search(array $filters, User $user): array
    {
        $query = $this->apply($this->visibility->query($user), $filters);
        $keywords = array_values(array_map('strval', (array) ($filters['keywords'] ?? [])));

        $out = [];
        foreach ($query->orderByDesc('last_activity_at')->limit(50)->get() as $contact) {
            $out[] = [
                'ulid' => $contact->ulid, 'name' => $contact->name, 'lifecycle' => $contact->lifecycle->value,
                'last_activity_at' => $contact->last_activity_at?->toIso8601String(),
                'reason' => $this->reason($contact, $keywords, $filters),
            ];
        }

        return $out;
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Contact>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $base = array_intersect_key($filters, array_flip(['lifecycle', 'origin', 'source', 'tags', 'no_contact_days', 'created_within_days', 'overdue_task']));
        $this->lists->apply($query, $base);

        $query->where('lifecycle', '!=', Lifecycle::Archived);

        if (! empty($filters['cities'])) {
            $query->where(fn ($q) => collect((array) $filters['cities'])->each(fn ($c) => $q->orWhereRaw('lower(city) = ?', [mb_strtolower((string) $c)])));
        }
        if (! empty($filters['pipeline'])) {
            $query->whereIn('pipeline_id', Pipeline::query()->where('kind', $filters['pipeline'])->select('id'));
        }
        if (! empty($filters['keywords'])) {
            $keywords = (array) $filters['keywords'];
            $query->where(function ($q) use ($keywords): void {
                foreach ($keywords as $kw) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $kw).'%';
                    $q->orWhere('search_name', 'like', $like)
                        ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('crm_activities')->whereColumn('crm_activities.contact_id', 'crm_contacts.id')
                            ->whereRaw('lower(crm_activities.body) like ?', [$like]))
                        ->orWhereHas('tags', fn ($t) => $t->whereRaw('lower(name) like ?', [$like]));
                }
            });
        }

        return $query;
    }

    /**
     * @param  list<string>  $keywords
     * @param  array<string, mixed>  $filters
     */
    private function reason(Contact $contact, array $keywords, array $filters): string
    {
        foreach ($keywords as $kw) {
            $activity = Activity::query()->where('contact_id', $contact->id)->whereRaw('lower(body) like ?', ['%'.$kw.'%'])->orderByDesc('occurred_at')->first();
            if ($activity !== null && $activity->body !== null) {
                $pos = mb_stripos($activity->body, $kw) ?: 0;
                $start = max(0, $pos - 40);
                $snippet = ($start > 0 ? '…' : '').trim(mb_substr($activity->body, $start, 110)).(mb_strlen($activity->body) > $start + 110 ? '…' : '');

                return ucfirst($activity->type).' '.$activity->occurred_at->format('M j').': "'.$snippet.'"';
            }
        }

        $parts = [str_replace('_', ' ', ucfirst($contact->lifecycle->value))];
        $parts[] = $contact->last_activity_at !== null ? 'last contact '.$contact->last_activity_at->diffForHumans() : 'never contacted';
        if (! empty($filters['cities']) && $contact->city !== null) {
            $parts[] = $contact->city;
        }

        return implode(' · ', $parts);
    }

    private static function days(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= 3650 ? $value : null;
    }
}
