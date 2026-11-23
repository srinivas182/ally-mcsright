<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Sites\Content\MediaLibrary;
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
    public const DEFAULT_NAME = 'Ask Ally';

    public const DEFAULTS = ['enabled' => true, 'name' => self::DEFAULT_NAME, 'photo' => null, 'greeting' => '', 'gate' => 'after', 'gate_after' => 2, 'visitor_daily' => 20, 'daily_cap' => 300,
        'team' => [], 'nudge' => true];

    /**
     * team: the agents who take bookings from the brokerage's website (empty: everyone who set their hours).
     * nudge: a gentle offer on listing pages after a while (no chat starts until the visitor clicks).
     *
     * @return array{enabled: bool, name: string, photo: string|null, greeting: string, gate: string, gate_after: int, visitor_daily: int, daily_cap: int, team: list<int>, nudge: bool}
     */
    public static function for(?Site $brokerage): array
    {
        $s = (array) (($brokerage !== null ? ($brokerage->settings ?? []) : [])['ailly'] ?? []);

        // FT1: on for every website unless the brokerage turns it off (within its daily limits).
        return ['enabled' => (bool) ($s['enabled'] ?? true), 'name' => (string) ($s['name'] ?? self::DEFAULT_NAME) ?: self::DEFAULT_NAME,
            'photo' => is_string($s['photo'] ?? null) && $s['photo'] !== '' ? $s['photo'] : null, 'greeting' => (string) ($s['greeting'] ?? ''),
            'gate' => in_array($s['gate'] ?? null, ['before', 'after', 'booking'], true) ? (string) $s['gate'] : 'after',
            'gate_after' => max(1, min(10, (int) ($s['gate_after'] ?? 2))), 'visitor_daily' => max(5, min(100, (int) ($s['visitor_daily'] ?? 20))),
            'daily_cap' => max(10, min(10_000, (int) ($s['daily_cap'] ?? 300))),
            'team' => array_values(array_map('intval', array_filter(is_array($s['team'] ?? null) ? $s['team'] : [], 'is_numeric'))), 'nudge' => (bool) ($s['nudge'] ?? true)];
    }

    /** @param array<string, mixed> $in */
    public static function save(Site $brokerage, array $in, User $by): void
    {
        $name = trim((string) ($in['name'] ?? self::DEFAULT_NAME));
        if (mb_strlen($name) > 30 || preg_match('/[<>{}]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Use a short name (up to 30 characters).']);
        }
        $old = self::for($brokerage);
        $new = ['enabled' => (bool) ($in['enabled'] ?? false), 'name' => $name !== '' ? $name : self::DEFAULT_NAME, 'photo' => self::photo($in['photo'] ?? null), 'greeting' => mb_substr(trim(strip_tags((string) ($in['greeting'] ?? ''))), 0, 240),
            'gate' => in_array($in['gate'] ?? null, ['before', 'after', 'booking'], true) ? (string) $in['gate'] : 'after',
            'gate_after' => max(1, min(10, (int) ($in['gate_after'] ?? 2))), 'visitor_daily' => max(5, min(100, (int) ($in['visitor_daily'] ?? 20))),
            'daily_cap' => max(10, min(10_000, (int) ($in['daily_cap'] ?? 300))),
            'team' => array_slice(array_values(array_unique(array_map('intval', array_filter(is_array($in['team'] ?? null) ? $in['team'] : [], 'is_numeric')))), 0, 200),
            'nudge' => (bool) ($in['nudge'] ?? true)];
        $brokerage->settings = [...($brokerage->settings ?? []), 'ailly' => $new];
        $brokerage->save();
        app(AuditLogger::class)->record('sites.ailly.settings', $brokerage, changes: ['ailly' => ['old' => $old, 'new' => $new]], severity: 'notice');
    }

    /**
     * Who the assistant is on a website: on an agent's website the agent's own name and photo for it (if they set
     * them), otherwise the brokerage's; the brokerage's default is "Ask Ally" with the built-in illustration.
     *
     * @return array{name: string, photo: string|null}
     */
    public static function persona(?Site $site, ?Site $brokerage): array
    {
        $b = self::for($brokerage);
        $a = $site !== null && $site->kind === 'agent' ? (array) (($site->settings ?? [])['ailly'] ?? []) : [];
        $name = trim((string) ($a['name'] ?? ''));
        $photo = is_string($a['photo'] ?? null) && $a['photo'] !== '' ? $a['photo'] : $b['photo'];

        return ['name' => $name !== '' ? $name : $b['name'], 'photo' => $photo !== null ? app(MediaLibrary::class)->url($photo, 'thumb') : null];
    }

    /** An agent's own name and photo for the assistant on their website (empty = the brokerage's). */
    public static function saveAgentPersona(Site $agentSite, ?string $name, ?string $photo, User $by): void
    {
        $name = trim((string) $name);
        if (mb_strlen($name) > 30 || preg_match('/[<>{}]/', $name) === 1) {
            throw ValidationException::withMessages(['ailly_name' => 'Use a short name (up to 30 characters).']);
        }
        $agentSite->settings = [...($agentSite->settings ?? []), 'ailly' => ['name' => $name, 'photo' => self::photo($photo)]];
        $agentSite->save();
        app(AuditLogger::class)->record('sites.ailly.agent_persona', $agentSite, metadata: ['name' => $name], severity: 'info');
    }

    /** A photo from this brokerage's media library (by id), or none. */
    private static function photo(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (app(MediaLibrary::class)->find($value) === null) {
            throw ValidationException::withMessages(['photo' => 'Choose a photo from your media library.']);
        }

        return $value;
    }

    /** @param array{name: string, greeting: string} $s */
    public static function greeting(array $s, string $name): string
    {
        return $s['greeting'] !== '' ? $s['greeting']
            : 'Hi, I\'m '.$name.'. I can help you find homes, answer questions about buying or selling, or connect you with an agent. What are you looking for?';
    }
}
