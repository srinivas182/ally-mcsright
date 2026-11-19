<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * MK3: what the HQ content desk writes about — questions broker owners and agents search for — and the topic booking
 * that keeps every site unique: a topic and angle booked in a city is not given to any other desk (HQ or tenant) for
 * six months. Cities come from HQ's list for the state, or else by how many listings each city has.
 */
final class Topics
{
    public const BOOKED_DAYS = 180;

    /** key => [landing or article, title pattern, angles] — for broker owners and agents. */
    public const HQ = [
        'brokerage-ops' => ['landing', 'Running a real estate brokerage in {city}, {state}', ['owners', 'growing-teams', 'small-offices']],
        'agent-business' => ['landing', 'For real estate agents in {city}: your whole business in one place', ['new-agents', 'experienced-agents', 'solo-agents']],
        'open-brokerage' => ['article', 'How to open your own real estate brokerage in {state}', ['licence-steps', 'costs-to-plan', 'first-90-days']],
        'market-for-agents' => ['article', '{city} real estate market: what agents should know', ['prices', 'homes-for-sale', 'days-on-market']],
        'commission-plans' => ['article', 'Commission splits and caps for {city} brokerages', ['splits', 'caps', 'revenue-share']],
        'compliance' => ['article', '{state} rules every real estate broker should know', ['advertising', 'trust-accounts', 'texting-consent']],
        'recruiting' => ['article', 'How {city} brokerages attract and keep agents', ['training', 'tools', 'support']],
    ];

    public const STATES = ['AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
        'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia'];

    /** @return list<string> the desk's cities, most important first */
    public static function cities(stdClass $desk, int $limit = 40): array
    {
        $own = array_values(array_filter(array_map('strval', (array) json_decode((string) ($desk->cities ?? '[]'), true))));
        if ($own !== []) {
            return array_slice($own, 0, $limit);
        }
        try {
            return array_values(array_map('strval', DB::connection(Connections::listings())->table('listings')->where('state_or_province', (string) $desk->state)
                ->whereNotNull('city')->groupBy('city')->orderByRaw('count(*) desc')->limit($limit)->pluck('city')->all()));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The next unbooked topic for this desk (alternating landing pages and articles), booked for six months; null when
     * every topic in every city is taken.
     *
     * @param  array<string, array{0: string, 1: string, 2: list<string>}>  $catalog
     * @return array{city: string, topic: string, angle: string, kind: string, title: string}|null
     */
    public static function next(stdClass $desk, array $catalog, ?string $preferKind = null): ?array
    {
        $db = DB::connection(Connections::control());
        $state = self::STATES[(string) $desk->state] ?? (string) $desk->state;
        $cities = self::cities($desk);
        $cities = $cities === [] ? [$state] : $cities; // no city data yet: state-wide topics
        $order = $preferKind === null ? $catalog : array_merge(array_filter($catalog, fn (array $t): bool => $t[0] === $preferKind), $catalog);
        foreach ($cities as $city) {
            foreach ($order as $topic => [$kind, $pattern, $angles]) {
                $stateWide = ! str_contains($pattern, '{city}');
                foreach ($angles as $angle) {
                    $place = $stateWide ? $state : $city;
                    $taken = $db->table('content_bookings')->where('state', (string) $desk->state)->where('city', $place)->where('topic', $topic)->where('angle', $angle)
                        ->where('until', '>', now())->exists();
                    if (! $taken) {
                        $db->table('content_bookings')->insert(['state' => (string) $desk->state, 'city' => $place, 'topic' => $topic, 'angle' => $angle, 'desk_id' => $desk->id,
                            'until' => CarbonImmutable::now()->addDays(self::BOOKED_DAYS), 'created_at' => now(), 'updated_at' => now()]);

                        return ['city' => $place, 'topic' => $topic, 'angle' => $angle, 'kind' => $kind,
                            'title' => str_replace(['{city}', '{state}'], [$place, $state], $pattern)];
                    }
                }
            }
        }

        return null;
    }

    public static function slug(string $state, string $title, string $angle): string
    {
        return mb_substr(Str::slug($state.' '.$title.' '.$angle), 0, 100);
    }
}
