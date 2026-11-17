<?php

declare(strict_types=1);

namespace Modules\Pricing\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Pricing\Models\Cma;

/** Daily brief (CM1): sellers who opened their CMA in the last two days — a good moment to call. */
final class PricingBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('pricing', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        return array_values(Cma::query()->where('owner_user_id', $user->id)->where('last_viewed_at', '>=', now()->subDays(2))->orderByDesc('last_viewed_at')->limit(3)->get()
            ->map(fn (Cma $c): array => ['key' => 'cma-opened-'.$c->ulid, 'title' => "Your CMA for {$c->title} was opened".($c->views > 1 ? " {$c->views} times" : ''),
                'why' => 'Call while it\'s fresh: they\'re thinking about price.', 'action' => 'Open the CMA', 'url' => '/pricing/cma/'.$c->ulid, 'urgency' => 2, 'stakes_cents' => null])->all());
    }
}
