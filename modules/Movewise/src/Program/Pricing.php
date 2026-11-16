<?php

declare(strict_types=1);

namespace Modules\Movewise\Program;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Movewise\Models\State;

/**
 * Movewise's pricing models per state (specs/movewise/00 §9, 03). HQ switches each model on in a state and sets its
 * price in credits. A model is offered only when it is on and priced: Movewise is never free by accident. Price
 * changes apply to new connections only (charges in MW4 read the price at the moment of the charge).
 */
final class Pricing
{
    public const MODELS = ['connection' => 'Per connection', 'appointment' => 'Per appointment', 'share' => 'Share at close'];

    /** @return array{models: list<string>, connection: array{buyer: int, seller: int}, appointment: int, share_pct: float} */
    public static function for(State $state): array
    {
        $p = (array) ($state->pricing ?? []);
        $c = (array) ($p['connection'] ?? []);

        return ['models' => array_values(array_intersect(array_keys(self::MODELS), array_map('strval', (array) ($p['models'] ?? [])))),
            'connection' => ['buyer' => max(0, (int) ($c['buyer'] ?? 0)), 'seller' => max(0, (int) ($c['seller'] ?? 0))],
            'appointment' => max(0, (int) ($p['appointment'] ?? 0)), 'share_pct' => max(0.0, (float) ($p['share_pct'] ?? 0))];
    }

    /** @return list<string> the models an agent can choose in this state now */
    public static function offered(State $state): array
    {
        $p = self::for($state);

        return array_values(array_filter($p['models'], fn (string $m): bool => match ($m) {
            'connection' => $p['connection']['buyer'] > 0 && $p['connection']['seller'] > 0,
            'appointment' => $p['appointment'] > 0,
            default => $p['share_pct'] > 0,
        }));
    }

    /** Credits charged up front for one introduction of this lead type under this model (share at close: none). */
    public static function upfront(State $state, string $model, string $leadType): int
    {
        $p = self::for($state);

        return match ($model) {
            'connection' => $p['connection'][$leadType === 'seller' ? 'seller' : 'buyer'],
            'appointment' => $p['appointment'],
            default => 0,
        };
    }

    /** @param  array<string, mixed>  $in */
    public function save(State $state, array $in, User $by): void
    {
        $models = array_values(array_intersect(array_keys(self::MODELS), array_map('strval', (array) ($in['models'] ?? []))));
        $share = round((float) ($in['share_pct'] ?? 0), 2);
        if ($share > 50) {
            throw ValidationException::withMessages(['share_pct' => 'A referral share above 50% isn\'t allowed.']);
        }
        $int = fn (mixed $v): int => max(0, min(100_000, (int) $v));
        $before = $state->pricing;
        $state->forceFill(['pricing' => ['models' => $models, 'connection' => ['buyer' => $int($in['connection_buyer'] ?? 0), 'seller' => $int($in['connection_seller'] ?? 0)],
            'appointment' => $int($in['appointment'] ?? 0), 'share_pct' => $share]])->save();
        app(AuditLogger::class)->record('movewise.pricing.saved', $state, ['pricing' => ['old' => $before, 'new' => $state->pricing]], label: $state->name);
    }
}
