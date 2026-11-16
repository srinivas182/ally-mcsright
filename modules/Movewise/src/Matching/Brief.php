<?php

declare(strict_types=1);

namespace Modules\Movewise\Matching;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Movewise;

/**
 * The brief the chosen agent sees (specs/movewise/00 §5, 04): who the person is, what they want and when, the money
 * facts they shared, the home, their saved homes, what to say first, and the whole AIlly conversation. Contact
 * details appear only once the agent has accepted.
 */
final class Brief
{
    public function __construct(private readonly Movewise $movewise, private readonly ListingSearch $search) {}

    /** @return array<string, mixed> */
    public function for(Connection $c, bool $reveal): array
    {
        $p = Person::query()->findOrFail($c->person_id);
        $agent = Agent::query()->findOrFail($c->agent_id);
        $about = (array) ($p->about ?? []);
        $context = (array) json_decode((string) DB::connection(ControlDb::control())->table('movewise_shows')->where('id', $c->show_id)->value('context'), true);
        $hq = $this->movewise->feedTenant();
        $home = $hq !== null && is_string($context['listing'] ?? null) ? $this->search->find($hq->id, $context['listing']) : null;
        $saved = [];
        if ($hq !== null) {
            foreach (DB::connection(ControlDb::control())->table('movewise_saved_homes')->where('person_id', $p->id)->orderByDesc('created_at')->limit(5)->pluck('listing_ulid') as $u) {
                $h = $this->search->find($hq->id, (string) $u);
                if ($h !== null) {
                    $saved[] = ListingSearch::label($h).(isset($h['price']) ? ' ($'.number_format((int) $h['price']).')' : '');
                }
            }
        }
        $readiness = Readiness::for($p);
        $lines = [];
        foreach (DB::connection(ControlDb::control())->table('movewise_chats')->where('person_id', $p->id)->orderBy('id')->pluck('messages') as $m) {
            foreach ((array) json_decode((string) $m, true) as $msg) {
                if (is_array($msg)) {
                    $lines[] = (($msg['role'] ?? '') === 'person' ? ($p->first_name ?? 'Them') : 'AIlly').': '.(string) ($msg['text'] ?? '');
                }
            }
        }
        $property = $home !== null ? ListingSearch::label($home) : (($about['home_zip'] ?? null) !== null ? 'Their home in '.trim(($about['home_city'] ?? '').' '.$about['home_zip']) : null);
        $price = $home['price'] ?? ($about['home_price'] ?? null);
        $facts = array_values(array_filter([
            $c->lead_type === 'seller' ? 'Selling'.(isset($about['home_price']) ? ', thinks it is worth about $'.number_format((int) $about['home_price']) : '') : 'Buying',
            isset($about['timeline']) ? 'Timeline: '.(Readiness::TIMELINES[$about['timeline']] ?? $about['timeline']) : null,
            ($about['preapproved'] ?? null) !== null ? 'Pre-approved: '.$about['preapproved'] : null,
            ($about['selling_first'] ?? false) ? 'Needs to sell first' : null,
            ($about['language'] ?? 'en') === 'es' ? 'Prefers Spanish' : null,
        ]));
        $first = 'Hi '.($p->first_name ?? 'there').', it\'s '.(string) ($agent->profile['display_name'] ?? '').' from '.$agent->brokerage_name.'. You chose me on Movewise'
            .($property !== null ? ' about '.$property : '').'. '.($c->method === 'time' && $c->slot_at !== null ? 'I\'m confirming our call on '.$c->slot_at->format('l').'.' : 'Is now a good time for a quick chat?');

        return [
            'name' => $p->first_name, 'lead_type' => $c->lead_type, 'facts' => $facts, 'readiness' => $readiness, 'property' => $property, 'price' => $price,
            'zip' => $context['zip'] ?? ($about['home_zip'] ?? null), 'listing_url' => $home !== null ? 'https://'.config('movewise.host').'/homes/'.$home['ulid'] : null,
            'saved' => $saved, 'say_first' => $first, 'method' => $c->method, 'slot_at' => $c->slot_at?->toIso8601String(), 'consent' => $c->consent_text,
            'email' => $reveal ? $p->email : null, 'phone' => $reveal ? $p->phone : null,
            'chat' => array_slice($lines, -80),
            'summary' => 'Movewise introduction. '.implode('. ', $facts).'. Readiness '.$readiness['score'].'/100 ('.implode('; ', $readiness['reasons']).').'
                .($saved !== [] ? ' Saved homes: '.implode('; ', $saved).'.' : ''),
            'transcript' => $lines !== [] ? "Their conversation with AIlly:\n".implode("\n", array_slice($lines, -80)) : 'No conversation with AIlly yet.',
        ];
    }
}
