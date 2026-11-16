<?php

declare(strict_types=1);

namespace Modules\Movewise\Matching;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Movewise\Models\Person;

/**
 * The readiness score, 0–100 (specs/movewise/00 §5, 04): how close the person is to moving, from what they told us
 * and what they did. A rules-based start; the Lead-IQ model trained on Movewise outcomes replaces the weights later.
 * Agents see the score with its reasons; it never decides who may connect.
 */
final class Readiness
{
    public const TIMELINES = ['now' => 'Within 3 months', 'soon' => 'In 3 to 6 months', 'later' => 'In 6 to 12 months', 'someday' => 'More than a year away'];

    /** @return array{score: int, reasons: list<string>} */
    public static function for(Person $p): array
    {
        $db = DB::connection(Connections::control());
        $about = (array) ($p->about ?? []);
        $parts = [];
        $add = function (int $points, string $why) use (&$parts): void {
            if ($points > 0) {
                $parts[] = [$points, $why];
            }
        };
        $add(match ($about['timeline'] ?? null) {
            'now' => 30, 'soon' => 18, 'later' => 6, default => 0,
        }, 'Timeline: '.(self::TIMELINES[$about['timeline'] ?? ''] ?? 'not given'));
        $add(($about['preapproved'] ?? null) === 'yes' ? 12 : 0, 'Says they are pre-approved');
        $add($p->email_verified_at !== null ? 8 : 0, 'Email verified');
        $add($p->phone_verified_at !== null ? 12 : 0, 'Mobile verified');
        $saved = $db->table('movewise_saved_homes')->where('person_id', $p->id)->count();
        $add(min(15, $saved * 3), $saved.' saved '.($saved === 1 ? 'home' : 'homes'));
        $searches = $db->table('movewise_saved_searches')->where('person_id', $p->id)->where('frequency', '!=', 'off')->count();
        $add(min(8, $searches * 4), $searches.' saved '.($searches === 1 ? 'search' : 'searches').' with alerts');
        $messages = 0;
        foreach ($db->table('movewise_chats')->where('person_id', $p->id)->pluck('messages') as $m) {
            $messages += count(array_filter((array) json_decode((string) $m, true), fn ($x): bool => is_array($x) && ($x['role'] ?? '') === 'person'));
        }
        $add(min(10, $messages * 2), $messages.' questions to AIlly');
        $add(is_string(((array) ($p->plan ?? []))['move_date'] ?? null) ? 5 : 0, 'Set a moving date in the move planner');

        return ['score' => min(100, array_sum(array_column($parts, 0))), 'reasons' => array_map(fn (array $x): string => $x[1], $parts)];
    }
}
