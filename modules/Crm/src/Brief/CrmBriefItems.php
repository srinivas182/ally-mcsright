<?php

declare(strict_types=1);

namespace Modules\Crm\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Brief\DailyBriefs;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Crm\TodaysTen;

/**
 * The CRM's part of the daily brief (specs/core/09): new leads waiting for an answer, replies waiting, who to
 * call today (Today's 10), overdue tasks; for brokers, agents who aren't answering their leads.
 */
final class CrmBriefItems
{
    /** A lead's likely commission, for ranking: 2.5% of the price they gave (a planning figure, never shown as fact). */
    public const RATE = 0.025;

    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('crm', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $items = [];
        $now = CarbonImmutable::now();
        foreach (Lead::query()->where('assigned_user_id', $user->id)->whereNull('responded_at')->whereIn('status', ['assigned', 'offered'])->with('contact')->latest('id')->limit(10)->get() as $lead) {
            $c = $lead->contact;
            if ($c === null) {
                continue;
            }
            $mins = (int) ($lead->assigned_at ?? $lead->created_at)->diffInMinutes($now);
            $items[] = ['key' => 'lead:'.$lead->id, 'title' => 'Answer '.$c->name.', a new lead', 'why' => 'Waiting '.self::ago($mins).'. The first agent to answer usually wins the client.',
                'action' => 'Call or text', 'url' => '/contacts/'.$c->ulid, 'urgency' => 3, 'stakes_cents' => self::stakes($lead)];
        }
        foreach (Conversation::query()->where('assigned_user_id', $user->id)->whereNotNull('needs_reply_since')->with('contact')->orderBy('needs_reply_since')->limit(10)->get() as $conv) {
            $items[] = ['key' => 'reply:'.$conv->id, 'title' => 'Reply to '.$conv->contact->name, 'why' => 'They wrote '.self::ago((int) $conv->needs_reply_since?->diffInMinutes($now)).' and are waiting for you.',
                'action' => 'Reply', 'url' => '/inbox?c='.$conv->ulid, 'urgency' => 3, 'stakes_cents' => self::stakes(Lead::query()->where('contact_id', $conv->contact_id)->latest('id')->first())];
        }
        $overdue = Task::query()->where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<', $now)->count();
        if ($overdue > 0) {
            $items[] = ['key' => 'tasks', 'title' => $overdue.' overdue '.($overdue === 1 ? 'task' : 'tasks'), 'why' => 'Promises to clients slip when tasks wait.',
                'action' => 'See tasks', 'url' => '/tasks', 'urgency' => 3, 'stakes_cents' => null];
        }
        foreach (array_slice(app(TodaysTen::class)->for($user, 5), 0, 5) as $row) {
            $c = $row['contact'] ?? null;
            if (! $c instanceof Contact) {
                continue;
            }
            $items[] = ['key' => 'call:'.$c->id, 'title' => 'Call '.$c->name, 'why' => implode('; ', array_map('strval', (array) ($row['reasons'] ?? []))) ?: 'On your Today\'s 10.',
                'action' => 'Open', 'url' => '/contacts/'.$c->ulid, 'urgency' => 2, 'stakes_cents' => self::stakes(Lead::query()->where('contact_id', $c->id)->latest('id')->first())];
        }
        if (DailyBriefs::manager($user)) {
            $late = Lead::query()->whereNotNull('assigned_user_id')->whereNull('responded_at')->whereIn('status', ['assigned', 'offered'])->where('assigned_at', '<', $now->subHour())
                ->where('created_at', '>', $now->subDays(7))->get()->groupBy('assigned_user_id');
            foreach ($late as $agentId => $leads) {
                $agent = User::query()->find((int) $agentId);
                if ($agent === null || $agent->id === $user->id) {
                    continue;
                }
                $items[] = ['key' => 'slow:'.$agent->id, 'title' => $agent->name.' hasn\'t answered '.$leads->count().' '.($leads->count() === 1 ? 'lead' : 'leads'),
                    'why' => 'Waiting more than an hour. Nudge them, or pass the leads on.', 'action' => 'See leads', 'url' => '/leads', 'urgency' => 2,
                    'stakes_cents' => $leads->sum(fn (Lead $l): int => self::stakes($l) ?? 0) ?: null];
            }
        }

        return $items;
    }

    public static function stakes(?Lead $lead): ?int
    {
        if ($lead === null) {
            return null;
        }
        $price = $lead->price_max ?? $lead->price_min;

        return $price !== null && $price > 0 ? (int) round($price * self::RATE * 100) : null;
    }

    private static function ago(int $minutes): string
    {
        return $minutes < 60 ? max(1, $minutes).' min' : ($minutes < 2880 ? intdiv($minutes, 60).' h' : intdiv($minutes, 1440).' days');
    }
}
