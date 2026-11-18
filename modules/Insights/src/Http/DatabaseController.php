<?php

declare(strict_types=1);

namespace Modules\Insights\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Health\DatabaseHealth;
use Modules\Insights\DatabaseValue;

/** The database report (DH1): health with fixes, commission forecast, database value and proof. */
final class DatabaseController
{
    public function show(Request $request, DatabaseValue $value, DatabaseHealth $health): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Owner) || $user->isKind(UserKind::Agent)), 403);
        $broker = $user->isKind(UserKind::Owner);
        // PS2 (specs/core/14): the per-agent table costs about 15 queries an agent, so it is worked out at most every
        // 10 minutes per brokerage; the brokerage's own report above it is always live.
        $agents = $broker ? Cache::remember('insights.database.agents:'.$user->tenant_id, now()->addMinutes(10), fn (): array => User::query()->where('kind', UserKind::Agent)->where('status', 'active')
            ->orderBy('first_name')->get()->map(fn (User $a): array => ['name' => $a->name, 'score' => $health->for($a)['score'], 'value_cents' => $value->value($a)['value_cents'],
                'forecast_high_cents' => $value->forecast($a)['buckets'][2]['high_cents']])->sortBy('score')->values()->all()) : [];

        return Inertia::render('insights/database', ['broker' => $broker, 'report' => $value->report($broker ? null : $user), 'agents' => $agents]);
    }
}
