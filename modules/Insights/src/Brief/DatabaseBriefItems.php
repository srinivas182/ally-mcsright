<?php

declare(strict_types=1);

namespace Modules\Insights\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Crm\Health\DatabaseHealth;

/** One line in the daily brief (DH1): "Your database is 78% healthy — fix 3 things", for agents and owners. */
final class DatabaseBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('database', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        if (! in_array($user->kind->value, ['agent', 'owner'], true)) {
            return [];
        }
        $h = app(DatabaseHealth::class)->for($user->kind->value === 'owner' ? null : $user);
        if ($h['total'] === 0 || $h['score'] >= 90 || $h['to_fix'] === 0) {
            return [];
        }
        $top = collect($h['checks'])->sortByDesc('count')->first();

        return [['key' => 'database-health', 'title' => "Your database is {$h['score']}% healthy — fix {$h['to_fix']} thing".($h['to_fix'] === 1 ? '' : 's'),
            'why' => $top !== null ? "{$top['label']}: {$top['count']}. {$top['fix']}." : '', 'action' => 'See what to fix', 'url' => '/insights/database', 'urgency' => 1, 'stakes_cents' => null]];
    }
}
