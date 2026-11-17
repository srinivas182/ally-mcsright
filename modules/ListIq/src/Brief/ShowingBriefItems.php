<?php

declare(strict_types=1);

namespace Modules\ListIq\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Models\Showing;
use Modules\ListIq\Showings;

/** Daily brief (SH1): showing requests waiting for an answer, and listings with no showings in a week. */
final class ShowingBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('showings', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $mine = Prep::query()->where('owner_user_id', $user->id)->pluck('id', 'ulid');
        if ($mine->isEmpty()) {
            return [];
        }
        $items = [];
        $waiting = Showing::query()->whereIn('listing_id', $mine->values()->all())->where('status', 'requested')->where('starts_at', '>', now())->count();
        if ($waiting > 0) {
            $items[] = ['key' => 'showings-waiting', 'title' => "{$waiting} showing request".($waiting === 1 ? '' : 's').' to answer', 'why' => 'Buyers\' agents are waiting to hear back.',
                'action' => 'Answer', 'url' => '/list-iq/'.$mine->keys()->first().'#showings', 'urgency' => 3, 'stakes_cents' => null];
        }
        foreach (app(Showings::class)->quiet($user) as $p) {
            $items[] = ['key' => 'showings-quiet-'.$p->ulid, 'title' => "No showings at {$p->street} this week", 'why' => 'Check the price, photos and description with the seller.',
                'action' => 'See the listing', 'url' => '/list-iq/'.$p->ulid, 'urgency' => 1, 'stakes_cents' => null];
        }

        return $items;
    }
}
