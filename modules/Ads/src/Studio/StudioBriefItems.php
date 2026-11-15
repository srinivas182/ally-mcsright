<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;

/** Ally Studio's part of the daily brief (specs/grow/13): posts waiting for the person's OK. */
final class StudioBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('studio', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $n = QueuedPost::query()->where('user_id', $user->id)->where('status', 'awaiting')->count();

        return $n === 0 ? [] : [['key' => 'studio:awaiting', 'title' => $n.' Ally Studio '.($n === 1 ? 'post is' : 'posts are').' ready for your OK',
            'why' => 'Designed and written for you. Approve, change the words, or skip.', 'action' => 'Review', 'url' => '/studio/autopilot', 'urgency' => 2, 'stakes_cents' => 0]];
    }
}
