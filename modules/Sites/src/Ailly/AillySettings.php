<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Sites\Models\Site;

/**
 * The brokerage's AIlly settings (specs/websites/11), kept with its website and used on its agents' websites too.
 *
 * - gate: when AIlly asks for name, email and phone. "before" (before the first answer), "after" (after N answers,
 *   the default, N = 2) or "booking" (only when booking a call or a showing).
 * - visitor_daily: most messages one visitor may send a day; daily_cap: most new conversations a day.
 */
final class AillySettings
{
    public const DEFAULTS = ['enabled' => false, 'name' => 'Ally', 'greeting' => '', 'gate' => 'after', 'gate_after' => 2, 'visitor_daily' => 20, 'daily_cap' => 300];

    /**
     * @return array{enabled: bool, name: string, greeting: string, gate: string, gate_after: int, visitor_daily: int, daily_cap: int}
     */
    public static function for(?Site $brokerage): array
    {
        $s = (array) (($brokerage !== null ? ($brokerage->settings ?? []) : [])['ailly'] ?? []);

        return ['enabled' => (bool) ($s['enabled'] ?? false), 'name' => (string) ($s['name'] ?? 'Ally') ?: 'Ally', 'greeting' => (string) ($s['greeting'] ?? ''),
            'gate' => in_array($s['gate'] ?? null, ['before', 'after', 'booking'], true) ? (string) $s['gate'] : 'after',
            'gate_after' => max(1, min(10, (int) ($s['gate_after'] ?? 2))), 'visitor_daily' => max(5, min(100, (int) ($s['visitor_daily'] ?? 20))),
            'daily_cap' => max(10, min(10_000, (int) ($s['daily_cap'] ?? 300)))];
    }

    /** @param array<string, mixed> $in */
    public static function save(Site $brokerage, array $in, User $by): void
    {
        $name = trim((string) ($in['name'] ?? 'Ally'));
        if (mb_strlen($name) > 30 || preg_match('/[<>{}]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Use a short name (up to 30 characters).']);
        }
        $old = self::for($brokerage);
        $new = ['enabled' => (bool) ($in['enabled'] ?? false), 'name' => $name !== '' ? $name : 'Ally', 'greeting' => mb_substr(trim(strip_tags((string) ($in['greeting'] ?? ''))), 0, 240),
            'gate' => in_array($in['gate'] ?? null, ['before', 'after', 'booking'], true) ? (string) $in['gate'] : 'after',
            'gate_after' => max(1, min(10, (int) ($in['gate_after'] ?? 2))), 'visitor_daily' => max(5, min(100, (int) ($in['visitor_daily'] ?? 20))),
            'daily_cap' => max(10, min(10_000, (int) ($in['daily_cap'] ?? 300)))];
        $brokerage->settings = [...($brokerage->settings ?? []), 'ailly' => $new];
        $brokerage->save();
        app(AuditLogger::class)->record('sites.ailly.settings', $brokerage, changes: ['ailly' => ['old' => $old, 'new' => $new]], severity: 'notice');
    }

    /** @param array{name: string, greeting: string} $s */
    public static function greeting(array $s, Site $site): string
    {
        return $s['greeting'] !== '' ? $s['greeting']
            : 'Hi, I\'m '.$s['name'].'. I can help you find homes, answer questions about buying or selling, or connect you with an agent. What are you looking for?';
    }
}
