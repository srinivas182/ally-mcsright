<?php

declare(strict_types=1);

namespace Modules\Recruiting\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Recruiting\Pipeline;

/** Daily brief (RC1): recruiting alerts and recruits whose next step is due, for people who recruit. */
final class RecruitingBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('recruiting', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        if (! $user->can('recruiting.manage')) {
            return [];
        }
        $pipeline = app(Pipeline::class);
        $items = [];
        $alerts = $pipeline->alerts();
        if ($alerts !== []) {
            $items[] = ['key' => 'recruiting-alerts', 'title' => count($alerts) === 1 ? 'Recruiting: '.$alerts[0]['message'] : count($alerts).' recruiting alerts',
                'why' => count($alerts) === 1 ? '' : $alerts[0]['message'], 'action' => 'Open recruiting', 'url' => '/recruiting', 'urgency' => 2, 'stakes_cents' => null];
        }
        $due = $pipeline->dueCount($user);
        if ($due > 0) {
            $items[] = ['key' => 'recruiting-due', 'title' => "{$due} recruit".($due === 1 ? '' : 's').' to follow up', 'why' => 'Their next step is due.',
                'action' => 'Open recruiting', 'url' => '/recruiting', 'urgency' => 1, 'stakes_cents' => null];
        }

        return $items;
    }
}
