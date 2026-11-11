<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\AI\Models\AiBatch;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactScore;
use Modules\Crm\Models\Intent;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Task;
use Throwable;

/**
 * Database intelligence, part 1 (CRM additions 10, 12, 15).
 *
 * - The AI reads notes and extracts dated intents ("relocating in March"); nightly at batch price,
 *   or on demand. Every answer is validated; protected characteristics are never recorded.
 * - Scores are transparent rules over intents, stage, newness, recency and years since closing,
 *   always with plain reasons. Scores are signals, not certainties: the agent decides.
 * - Intents coming due within 14 days create a follow-up task once.
 */
final class DatabaseIntelligence
{
    public const FEATURE = 'crm.intents';

    private const SCHEMA = ['type' => 'object', 'required' => ['intents'], 'properties' => ['intents' => ['type' => 'array']]];

    private const BUYER_KINDS = ['buy', 'relocate', 'lease_end'];

    private const SELLER_KINDS = ['sell', 'retire'];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * On demand: read this contact's notes now.
     *
     * @return list<Intent>
     */
    public function analyseNow(Contact $contact, User $user): array
    {
        $records = $this->records($contact);
        if ($records === []) {
            return [];
        }

        $result = $this->gateway->run(new AiTask(self::FEATURE, 'crm.intents', 'crm-intents:'.$contact->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['today' => CarbonImmutable::now()->toDateString()], context: $records, outputSchema: self::SCHEMA, userId: $user->id));

        return $this->storeIntents($contact, (array) ($result->json['intents'] ?? []));
    }

    /**
     * Nightly: contacts with notes added since they were last analysed, at batch price.
     */
    public function analyseInBatch(int $limit = 500): ?AiBatch
    {
        $tasks = [];
        $contacts = Contact::query()->whereNotNull('owner_user_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('crm_activities')->whereColumn('crm_activities.contact_id', 'crm_contacts.id')
                ->whereIn('crm_activities.type', ['note', 'call', 'meeting'])->whereNotNull('crm_activities.body')
                ->where(fn ($w) => $w->whereNull('crm_contacts.analysed_at')->orWhereColumn('crm_activities.created_at', '>', 'crm_contacts.analysed_at')))
            ->limit($limit)->get();

        foreach ($contacts as $contact) {
            $records = $this->records($contact);
            if ($records !== []) {
                $tasks[] = new AiTask(self::FEATURE, 'crm.intents', 'crm-intents-batch:'.$contact->ulid.':'.CarbonImmutable::now()->format('Ymd'),
                    inputs: ['today' => CarbonImmutable::now()->toDateString()], context: $records, userId: $contact->owner_user_id);
            }
        }

        return $tasks === [] ? null : $this->gateway->batch($tasks);
    }

    public function storeBatchResult(string $idempotencyKey, string $text): void
    {
        if (preg_match('/^crm-intents-batch:([0-9A-HJKMNP-TV-Z]{26}):/', $idempotencyKey, $m) !== 1) {
            return;
        }
        $contact = Contact::query()->where('ulid', $m[1])->first();
        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $text)), true);
        if ($contact !== null && is_array($json)) {
            $this->storeIntents($contact, (array) ($json['intents'] ?? []));
        }
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<Intent>
     */
    public function storeIntents(Contact $contact, array $raw): array
    {
        $stored = [];
        $activities = Activity::query()->where('contact_id', $contact->id)->pluck('id', 'ulid');

        foreach (array_slice($raw, 0, 10) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            $summary = mb_substr(trim((string) ($item['summary'] ?? '')), 0, 300);
            if (! in_array($kind, Intent::KINDS, true) || $summary === '' || FairHousing::flags($summary) !== []) {
                continue;
            }

            $due = self::date((string) ($item['when'] ?? ''));
            $duplicate = Intent::query()->where('contact_id', $contact->id)->where('kind', $kind)->where('status', 'open')
                ->get()->contains(fn (Intent $i): bool => mb_strtolower($i->summary) === mb_strtolower($summary) || ($due !== null && $i->due_on?->equalTo($due)));
            if ($duplicate) {
                continue;
            }

            $recordId = (string) ($item['record_id'] ?? '');
            $sourceId = null;
            foreach ($activities as $ulid => $id) {
                if ($recordId !== '' && str_contains($recordId, (string) $ulid)) {
                    $sourceId = $id;
                }
            }

            $stored[] = Intent::query()->create([
                'contact_id' => $contact->id, 'kind' => $kind, 'summary' => $summary, 'due_on' => $due,
                'confidence' => in_array($item['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $item['confidence'] : 'medium',
                'source_activity_id' => $sourceId,
            ]);
        }

        $contact->newQuery()->whereKey($contact->id)->update(['analysed_at' => CarbonImmutable::now()]);
        $this->score($contact->fresh() ?? $contact);

        return $stored;
    }

    /**
     * Transparent opportunity scoring with reasons (0-100 each for buying and selling).
     */
    public function score(Contact $contact): ContactScore
    {
        $now = CarbonImmutable::now();
        $buyer = 0;
        $seller = 0;
        $reasons = [];
        $nearest = null;

        foreach (Intent::query()->where('contact_id', $contact->id)->where('status', 'open')->get() as $intent) {
            $weight = ['high' => 40, 'medium' => 30, 'low' => 15][$intent->confidence] ?? 30;
            if ($intent->due_on !== null && $intent->due_on->gt($now->addMonths(6))) {
                $weight = intdiv($weight, 2);
            }
            if (in_array($intent->kind, self::BUYER_KINDS, true)) {
                $buyer += $weight;
            } elseif (in_array($intent->kind, self::SELLER_KINDS, true)) {
                $seller += $weight;
            } elseif ($intent->kind === 'family_change') {
                $buyer += intdiv($weight, 2);
                $seller += intdiv($weight, 2);
            }
            $reasons[] = 'Said: "'.$intent->summary.'"'.($intent->due_on !== null ? ' ('.$intent->due_on->format('M Y').')' : '');
            if ($intent->due_on !== null && ($nearest === null || $intent->due_on->lt($nearest))) {
                $nearest = $intent->due_on;
            }
        }

        $kind = $contact->pipeline_id !== null ? Pipeline::query()->whereKey($contact->pipeline_id)->value('kind') : null;
        if ($contact->lifecycle === Lifecycle::Active) {
            if ($kind === 'seller') {
                $seller += 25;
                $reasons[] = 'Working with you to sell';
            } else {
                $buyer += 25;
                $reasons[] = 'Actively searching';
            }
        }
        if ($contact->lifecycle === Lifecycle::Lead && $contact->created_at !== null && $contact->created_at->gte($now->subDays(14))) {
            $buyer += 20;
            $reasons[] = 'New lead '.$contact->created_at->diffForHumans();
        }
        if ($contact->closed_on !== null) {
            $years = (int) $contact->closed_on->diffInYears($now);
            if ($years >= 5 && $years <= 8) {
                $seller += 20;
                $reasons[] = "Bought {$years} years ago, a common time to move";
            }
        }
        if ($contact->last_activity_at !== null && $contact->last_activity_at->gte($now->subDays(7))) {
            $buyer >= $seller ? $buyer += 10 : $seller += 10;
            $reasons[] = 'Spoke recently';
        }

        $notProspect = in_array($contact->lifecycle, [Lifecycle::UnderContract, Lifecycle::Archived], true)
            || ($contact->lifecycle === Lifecycle::PastClient && $contact->closed_on !== null && $contact->closed_on->gte($now->subYear()));
        if ($notProspect) {
            $buyer = min($buyer, 10);
            $seller = min($seller, 10);
        }

        $timeframe = match (true) {
            $nearest !== null && $nearest->lte($now->addMonth()) => 'now',
            $nearest !== null && $nearest->lte($now->addMonths(3)) => '3m',
            $nearest !== null && $nearest->lte($now->addMonths(6)) => '6m',
            $nearest !== null && $nearest->lte($now->addYear()) => '12m',
            $nearest !== null => 'later',
            $contact->lifecycle === Lifecycle::Active => 'now',
            default => 'unknown',
        };

        return ContactScore::query()->updateOrCreate(['contact_id' => $contact->id], [
            'buyer' => min(100, $buyer), 'seller' => min(100, $seller), 'timeframe' => $timeframe,
            'reasons' => array_slice($reasons, 0, 5), 'scored_at' => $now,
        ]);
    }

    public function scoreAll(): int
    {
        $count = 0;
        Contact::query()->where('lifecycle', '!=', Lifecycle::Archived)->chunkById(500, function ($contacts) use (&$count): void {
            foreach ($contacts as $contact) {
                $this->score($contact);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Intents coming due within 14 days become a follow-up task for the contact's owner, once.
     */
    public function createAlerts(): int
    {
        $created = 0;
        $intents = Intent::query()->with('contact')->where('status', 'open')->whereNull('alert_task_id')
            ->whereNotNull('due_on')->whereDate('due_on', '<=', CarbonImmutable::now()->addDays(14)->toDateString())->get();

        foreach ($intents as $intent) {
            $contact = $intent->contact;
            if ($contact->owner_user_id === null) {
                continue;
            }
            try {
                $task = Task::query()->create([
                    'contact_id' => $contact->id, 'assigned_to' => $contact->owner_user_id, 'type' => 'call',
                    'title' => mb_substr('Follow up: '.$intent->summary, 0, 200), 'due_at' => CarbonImmutable::now(), 'priority' => 'high',
                ]);
                $intent->update(['alert_task_id' => $task->id]);
                $created++;
            } catch (Throwable) {
                continue;
            }
        }

        return $created;
    }

    /**
     * @return list<RetrievedRecord>
     */
    private function records(Contact $contact): array
    {
        return array_values(Activity::query()->where('contact_id', $contact->id)->whereIn('type', ['note', 'call', 'meeting'])->whereNotNull('body')
            ->orderByDesc('occurred_at')->limit(30)->get()
            ->map(fn (Activity $a): RetrievedRecord => RetrievedRecord::fromModel($a, ['id' => $a->ulid, 'when' => $a->occurred_at->toDateString(), 'note' => mb_substr((string) $a->body, 0, 2000)]))
            ->all());
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $value .= '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
