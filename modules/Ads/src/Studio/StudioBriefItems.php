<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;

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
        $items = [];
        if (in_array($user->kind, [UserKind::Owner, UserKind::Staff], true) && ($r = QueuedPost::query()->where('status', 'review')->count()) > 0) {
            $items[] = ['key' => 'studio:review', 'title' => $r.' agent '.($r === 1 ? 'post needs' : 'posts need').' your review', 'why' => 'Your brokerage reviews agents\' Ally Studio posts before they go out.',
                'action' => 'Review', 'url' => '/studio/review', 'urgency' => 2, 'stakes_cents' => 0];
        }
        $n = QueuedPost::query()->where('user_id', $user->id)->where('status', 'awaiting')->count();

        return $n === 0 ? $items : [...$items, ['key' => 'studio:awaiting', 'title' => $n.' Ally Studio '.($n === 1 ? 'post is' : 'posts are').' ready for your OK',
            'why' => 'Designed and written for you. Approve, change the words, or skip.', 'action' => 'Review', 'url' => '/studio/autopilot', 'urgency' => 2, 'stakes_cents' => 0]];
    }
}
