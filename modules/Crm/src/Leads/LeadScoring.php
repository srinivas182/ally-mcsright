<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Crm\Consent;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;

/**
 * Lead score 0–100 with the reasons (specs/grow/07): what they asked, how they engage, what the AI assistant learned.
 * Hot ≥ 70, warm 40–69, cold below. A lead turning hot alerts its agent at once (and rises to the top of Today's 10).
 * Rule-based and explainable; scores are signals, not certainties.
 */
final class LeadScoring
{
    public const HOT = 70;

    public const WARM = 40;

    private const SOON = '/\b(now|asap|immediately|this (week|month)|next (week|month)|(1|one|2|two|3|three) (weeks?|months?)|30 days|60 days|90 days|soon|ready)\b/i';

    private const YES = '/^\s*(yes|y|yep|yeah|approved|pre-?approved|already|cash)\b/i';

    public function __construct(private readonly Consent $consent) {}

    public function score(Lead $lead): Lead
    {
        $contact = $lead->contact;
        $points = 10;
        $reasons = [];
        $add = static function (int $p, string $why) use (&$points, &$reasons): void {
            $points += $p;
            $reasons[] = ['p' => $p, 'why' => $why];
        };

        if ($lead->intent === 'sell') {
            $add(15, 'Wants to sell');
        } elseif ($lead->intent === 'buy') {
            $add(5, 'Wants to buy');
        }
        if ($lead->property !== null) {
            $add(10, 'Asked about '.$lead->property);
        }
        if ($lead->price_min !== null || $lead->price_max !== null) {
            $add(5, 'Gave a price ($'.number_format((int) ($lead->price_max ?? $lead->price_min)).')');
        }
        if ($lead->message !== null && mb_strlen($lead->message) >= 20) {
            $add(5, 'Wrote a message');
        }
        if ($contact !== null && $contact->primary_email !== null && $contact->primary_phone !== null) {
            $add(5, 'Gave email and phone');
        }

        if ($contact !== null) {
            $inbound = Message::query()->where('contact_id', $contact->id)->where('direction', 'in')->where('created_at', '>=', $lead->created_at)->orderBy('id')->get(['created_at', 'body']);
            if ($inbound->isNotEmpty()) {
                $add(20, 'Replied');
                $firstOut = Message::query()->where('contact_id', $contact->id)->where('direction', 'out')->where('created_at', '>=', $lead->created_at)->orderBy('id')->value('created_at');
                $firstIn = $inbound->first()->created_at;
                if ($firstOut !== null && CarbonImmutable::parse((string) $firstOut)->diffInMinutes($firstIn) <= 10 && $firstIn->gte(CarbonImmutable::parse((string) $firstOut))) {
                    $add(10, 'Replied within 10 minutes');
                }
                if ($inbound->count() >= 3) {
                    $add(10, 'In a real conversation ('.$inbound->count().' replies)');
                }
                if ($inbound->contains(fn ($m): bool => preg_match('/\b(see|tour|show|visit|view)\b.{0,30}\b(it|home|house|place|property|this)\b|\bshowing\b/i', (string) $m->body) === 1)) {
                    $add(15, 'Asked to see a home');
                }
            }
            if ($this->consent->canAutoText($contact)) {
                $add(5, 'Agreed to texts');
            }
        }

        $q = AiThread::query()->where('lead_id', $lead->id)->first()->qualification ?? [];
        if (preg_match(self::YES, (string) ($q['preapproved'] ?? '')) === 1) {
            $add(15, 'Pre-approved'.(preg_match('/\$?\d[\d,.]*\s*k?/i', (string) $q['preapproved'], $m) === 1 ? ' ('.trim($m[0]).')' : ''));
        }
        if (preg_match(self::SOON, (string) ($q['timeline'] ?? '')) === 1) {
            $add(15, 'Moving soon ('.$q['timeline'].')');
        }
        if (trim((string) ($q['showing'] ?? '')) !== '' && preg_match('/^\s*(no|not)\b/i', (string) $q['showing']) !== 1 && ! str_contains(implode(' ', array_column($reasons, 'why')), 'Asked to see a home')) {
            $add(15, 'Wants a showing ('.$q['showing'].')');
        }
        if (preg_match(self::YES, (string) ($q['has_agent'] ?? '')) === 1) {
            $add(-40, 'Already has an agent');
        }

        if ($lead->engaged_at === null && $lead->created_at->lt(CarbonImmutable::now()->subDays(30))) {
            $add(-20, 'No reply in 30 days');
        }
        if ($contact !== null && $contact->sms_opt_out_at !== null && ! $contact->sms_opt_in) {
            $add(-15, 'Opted out of texts');
        }

        $score = max(0, min(100, $points));
        $label = $score >= self::HOT ? 'hot' : ($score >= self::WARM ? 'warm' : 'cold');
        usort($reasons, static fn (array $a, array $b): int => abs($b['p']) <=> abs($a['p']));
        $wasHot = $lead->score_label === 'hot';
        $lead->forceFill(['score' => $score, 'score_label' => $label, 'score_reasons' => array_slice(array_column($reasons, 'why'), 0, 6), 'scored_at' => CarbonImmutable::now()])->save();

        if ($label === 'hot' && ! $wasHot && $lead->hot_alerted_at === null && $contact !== null) {
            $agentId = $lead->assigned_user_id ?? $contact->owner_user_id;
            $agent = $agentId !== null ? User::query()->find($agentId) : null;
            if ($agent !== null) {
                $lead->forceFill(['hot_alerted_at' => CarbonImmutable::now()])->save();
                $why = implode(', ', array_slice(array_column($reasons, 'why'), 0, 3));
                $url = app(SpeedToLead::class)->link('/leads?lead='.$lead->ulid);
                $agent->notify(new LeadAlert("Hot lead: {$contact->name} ({$score})", "{$contact->name} is ready to talk: {$why}.", $url, 'Call now'));
                app(SpeedToLead::class)->textAgent($agent, "RightAlly: hot lead — {$contact->name} ({$score}): {$why}. {$url}");
            }
        }

        return $lead;
    }

    /** The newest open leads for a contact, rescored (after a reply or when the AI learns something). */
    public function rescoreContact(int $contactId): void
    {
        foreach (Lead::query()->where('contact_id', $contactId)->where('status', '!=', 'needs_review')->where('created_at', '>=', CarbonImmutable::now()->subDays(180))->with('contact')->get() as $lead) {
            $this->score($lead);
        }
    }

    public function rescoreAll(): int
    {
        $n = 0;
        Lead::query()->where('status', '!=', 'needs_review')->where('created_at', '>=', CarbonImmutable::now()->subDays(180))->with('contact')
            ->chunkById(300, function ($leads) use (&$n): void {
                foreach ($leads as $lead) {
                    $this->score($lead);
                    $n++;
                }
            });

        return $n;
    }
}
